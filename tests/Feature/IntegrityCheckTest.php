<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\LoyaltyAccount;
use App\Models\Promotion;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AppointmentBookingService;
use App\Services\IntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns;
use Tests\TestCase;

class IntegrityCheckTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $cashier = $this->cashier();
        $manager = $this->manager();
        $this->openDrawer($cashier);
        $this->openDrawer($manager, '0.00');
        $customer = $this->customer();
        $product = $this->stockedProduct('10', '5.00', ['selling_price' => '12.00', 'tax_rate' => '10.00']);
        $promotion = Promotion::create(['name' => 'P', 'discount_type' => 'percent', 'discount_value' => '10.00', 'min_purchase' => '0.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'is_active' => true]);
        (new Coupon(['code' => 'TEN', 'is_active' => true]))->forceFill(['promotion_id' => $promotion->id, 'used_count' => 0])->save();

        $this->invoice = $this->issueInvoice($cashier, [['service_id' => $this->service(30, '50.00', ['tax_rate' => '10.00'])->id, 'quantity' => 1], ['product_id' => $product->id, 'quantity' => 2]], [$this->cashPayment('40.00')], ['customer_id' => $customer->id, 'coupon_code' => 'TEN']);
        $paid = $this->issueInvoice($cashier, [['service_id' => $this->service(30, '20.00')->id, 'quantity' => 1]], [$this->cashPayment('20.00')], ['customer_id' => $customer->id]);
        $this->actingAs($manager)->post("/invoices/{$paid->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('cash')->id, 'reason' => 'x', 'quantities' => [$paid->items->first()->id => 1]])->assertSessionDoesntHaveErrors();
        $this->actingAs($manager)->post("/invoices/{$paid->id}/void", ['reason' => 'x'])->assertSessionDoesntHaveErrors();
        $expense = (new Expense(['expense_category_id' => ExpenseCategory::first()->id, 'description' => 'E', 'amount' => '5.00', 'expense_date' => today()]));
        $expense->forceFill(['expense_number' => 'EXP-000001', 'status' => 'approved', 'decided_by' => $manager->id, 'payment_status' => 'paid', 'paid_on' => today(), 'payment_method_id' => $this->paymentMethod()->id])->save();
        DB::table('document_sequences')->where('key', 'expense')->update(['last_number' => 1]);
    }

    /** @return array<string, array<string, mixed>> */
    private function findings(): array
    {
        return app(IntegrityChecker::class)->run();
    }

    public function test_a_database_built_only_through_the_application_is_consistent(): void
    {
        $this->assertSame([], $this->findings());
        $this->artisan('salon:integrity-check')->expectsOutputToContain('no inconsistencies')->assertExitCode(0);
    }

    public function test_each_kind_of_tampering_is_detected_and_named(): void
    {
        $cases = [
            'stock_ledger' => fn () => DB::table('products')->update(['current_stock' => 99]),
            'invoice_arithmetic' => fn () => DB::table('invoices')->where('id', $this->invoice->id)->update(['tax_total' => '999.00']),
            'invoice_payments' => fn () => DB::table('invoices')->where('id', $this->invoice->id)->update(['amount_paid' => '41.00']),
            'invoice_status' => fn () => DB::table('invoices')->where('id', $this->invoice->id)->update(['payment_status' => 'paid']),
            'document_sequences' => fn () => DB::table('document_sequences')->where('key', 'invoice')->update(['last_number' => 0]),
            'coupon_usage' => fn () => DB::table('coupons')->update(['used_count' => 7]),
            'cash_drawer' => fn () => DB::table('cash_drawer_sessions')->update(['status' => 'closed', 'expected_cash' => '10.00', 'counted_cash' => '12.00', 'variance' => '5.00']),
            'expenses' => fn () => DB::table('expenses')->update(['decided_by' => null, 'paid_on' => null]),
            'negative_stock' => fn () => DB::table('products')->update(['current_stock' => '-1.000']),
        ];
        foreach ($cases as $key => $tamper) {
            DB::beginTransaction();
            $tamper();
            $found = $this->findings();
            DB::rollBack();
            $this->assertArrayHasKey($key, $found, "tampering for {$key} went unnoticed");
            $this->assertGreaterThan(0, $found[$key]['count']);
            $this->assertNotEmpty($found[$key]['samples']);
        }
        $this->assertSame([], $this->findings(), 'rollback restored a healthy database');
    }

    public function test_loyalty_balance_drift_is_detected(): void
    {
        $account = LoyaltyAccount::query()->firstOrFail();
        DB::table('customer_loyalty_accounts')->where('id', $account->id)->update(['balance' => $account->balance + 50]);

        $this->assertArrayHasKey('loyalty_ledger', $this->findings());
    }

    public function test_a_voided_invoice_still_holding_money_and_over_refunds_are_detected(): void
    {
        DB::table('invoices')->where('id', $this->invoice->id)->update(['status' => 'voided']);
        $this->assertArrayHasKey('invoice_status', $this->findings());
        DB::table('invoices')->where('id', $this->invoice->id)->update(['status' => 'issued', 'refunded_total' => '500.00']);
        $found = $this->findings();
        $this->assertArrayHasKey('invoice_status', $found);
        $this->assertArrayHasKey('invoice_payments', $found);
    }

    public function test_double_booked_staff_are_detected_even_if_the_database_was_edited_directly(): void
    {
        $stylist = $this->stylist();
        $service = $this->service(60, '30.00');
        $customer = $this->customer();
        $booking = app(AppointmentBookingService::class);
        $admin = User::factory()->superAdmin()->create();
        $first = $booking->create(['customer_id' => $customer->id, 'start_at' => '2026-10-20 10:00', 'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id]]], $admin);
        $second = $booking->create(['customer_id' => $customer->id, 'start_at' => '2026-10-20 12:00', 'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id]]], $admin);
        $this->assertSame([], $this->findings());

        DB::table('appointment_services')->where('appointment_id', $second->id)->update(['start_at' => '2026-10-20 10:30:00', 'end_at' => '2026-10-20 11:30:00']);

        $found = $this->findings();
        $this->assertArrayHasKey('appointment_overlap', $found);
        $this->assertStringContainsString($first->appointment_number, $found['appointment_overlap']['samples'][0]);
    }

    public function test_the_command_fails_with_details_notifies_administrators_and_can_print_json(): void
    {
        $admin = User::factory()->withPermissions('settings.manage')->create();
        DB::table('coupons')->update(['used_count' => 7]);

        $this->artisan('salon:integrity-check')->expectsOutputToContain('Coupon used_count equals its redeemed (not reversed) redemptions')->assertExitCode(1);
        $this->artisan('salon:integrity-check --notify')->assertExitCode(1);

        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->where('title', 'Data integrity check found problems')->count());
        $this->artisan('salon:integrity-check --json --notify')->assertExitCode(1);
        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->count(), 'one alert per day');
    }
}
