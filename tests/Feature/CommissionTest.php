<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CommissionService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = $this->cashier('pos.discount');
        $this->manager = $this->manager();
        $this->openDrawer($this->cashier);
        $this->openDrawer($this->manager, '0.00');
    }

    private function stylist(string $type = 'percentage', string $rate = '20.00'): Employee
    {
        return Employee::factory()->create(['commission_type' => $type, 'commission_rate' => $rate]);
    }

    /** @return array<string, mixed> */
    private function line(Employee $employee, string $price = '100.00', int $qty = 1, array $extra = [], array $service = []): array
    {
        return array_merge(['service_id' => $this->service(60, $price, $service)->id, 'quantity' => $qty, 'employee_id' => $employee->id], $extra);
    }

    private function sell(array $lines, string $pay): Invoice
    {
        return $this->issueInvoice($this->cashier, $lines, [$this->cashPayment($pay)]);
    }

    private function net(Employee $employee): string
    {
        return Money::add(...Commission::where('employee_id', $employee->id)->pluck('amount')->map(fn ($a) => (string) $a)->all(),
            ...CommissionAdjustment::where('employee_id', $employee->id)->pluck('amount')->map(fn ($a) => (string) $a)->all());
    }

    private function refund(Invoice $invoice, array $quantities, string $method = 'cash'): void
    {
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod($method)->id,
            'reason' => 'Return', 'quantities' => $quantities])->assertSessionDoesntHaveErrors();
    }

    public function test_a_percentage_commission_is_earned_on_the_value_after_discount_and_before_tax(): void
    {
        $stylist = $this->stylist('percentage', '20.00');
        $invoice = $this->sell([$this->line($stylist, '100.00', 1, ['discount_type' => 'percent', 'discount_value' => '10'], ['tax_rate' => '10.00'])], '99.00');

        $commission = Commission::firstOrFail();
        $this->assertSame('90.00', (string) $commission->basis_amount);
        $this->assertSame('18.00', (string) $commission->amount);
        $this->assertSame('percentage', $commission->rule_type);
        $this->assertSame('20.00', (string) $commission->rate);
        $this->assertSame($invoice->id, $commission->invoice_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'commission.accrued']);
    }

    public function test_a_fixed_commission_is_paid_per_unit(): void
    {
        $stylist = $this->stylist('fixed', '5.00');
        $this->sell([$this->line($stylist, '40.00', 2)], '80.00');

        $this->assertSame('10.00', (string) Commission::firstOrFail()->amount);
    }

    public function test_the_rate_in_force_at_the_time_of_sale_is_kept_when_the_employee_rate_changes_later(): void
    {
        $stylist = $this->stylist('percentage', '10.00');
        $this->sell([$this->line($stylist, '100.00')], '100.00');

        $stylist->update(['commission_rate' => '50.00']);
        $this->sell([$this->line($stylist, '100.00')], '100.00');

        $this->assertSame(['10.00', '50.00'], Commission::orderBy('id')->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame('10.00', (string) Commission::orderBy('id')->first()->rate);
        $this->assertSame('60.00', $this->net($stylist));
    }

    public function test_no_commission_without_a_provider_a_rule_or_for_products_unless_enabled(): void
    {
        $noRule = $this->stylist('none', '0.00');
        $paid = $this->stylist('percentage', '10.00');
        $product = $this->stockedProduct('10', '2.00', ['selling_price' => '20.00']);

        $this->sell([$this->line($noRule), ['service_id' => $this->service(60, '30.00')->id, 'quantity' => 1]], '130.00');
        $this->assertSame(0, Commission::count());

        $this->sell([['product_id' => $product->id, 'quantity' => 1, 'employee_id' => $paid->id]], '20.00');
        $this->assertSame(0, Commission::count(), 'products earn nothing by default');

        $this->setting('commission.include_products', true);
        $this->sell([['product_id' => $product->id, 'quantity' => 2, 'employee_id' => $paid->id]], '40.00');
        $this->assertSame('4.00', (string) Commission::firstOrFail()->amount);
    }

    public function test_accrual_is_idempotent(): void
    {
        $stylist = $this->stylist();
        $invoice = $this->sell([$this->line($stylist)], '100.00');

        app(CommissionService::class)->accrueForInvoice($invoice);
        app(CommissionService::class)->accrueForInvoice($invoice->fresh());

        $this->assertSame(1, Commission::count());
    }

    public function test_commission_can_wait_for_full_payment(): void
    {
        $this->setting('commission.accrue_on', 'full_payment');
        $stylist = $this->stylist();
        $invoice = $this->issueInvoice($this->cashier, [$this->line($stylist)], [$this->cashPayment('40.00')], ['customer_id' => $this->customer()->id]);
        $this->assertSame(0, Commission::count(), 'part-paid invoices earn nothing yet');

        $this->actingAs($this->cashier)->post("/invoices/{$invoice->id}/payments", ['idempotency_key' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->paymentMethod('cash')->id, 'amount' => '60.00']]]);
        $this->assertSame('20.00', (string) Commission::firstOrFail()->amount);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
    }

    public function test_refunds_reduce_commission_in_proportion_and_a_full_refund_nets_to_exactly_zero(): void
    {
        $stylist = $this->stylist('percentage', '20.00');
        $invoice = $this->sell([$this->line($stylist, '50.00', 2)], '100.00');
        $itemId = $invoice->items->first()->id;
        $this->assertSame('20.00', $this->net($stylist));

        $this->refund($invoice, [$itemId => 1]);
        $this->assertSame('10.00', $this->net($stylist));
        $this->assertSame('-10.00', (string) CommissionAdjustment::firstOrFail()->amount);
        $this->assertSame('refund', CommissionAdjustment::firstOrFail()->type);

        $this->refund($invoice, [$itemId => 1]);
        $this->assertSame('0.00', $this->net($stylist));
    }

    public function test_rounding_remainders_never_leave_commission_behind_after_a_full_refund(): void
    {
        $stylist = $this->stylist('percentage', '10.00');
        $invoice = $this->sell([$this->line($stylist, '3.33', 3)], '9.99');
        $this->assertSame('1.00', $this->net($stylist));
        $itemId = $invoice->items->first()->id;

        foreach ([1, 1, 1] as $ignored) {
            $this->refund($invoice, [$itemId => 1]);
        }

        $this->assertSame(['-0.33', '-0.33', '-0.34'], CommissionAdjustment::orderBy('id')->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame('0.00', $this->net($stylist));
    }

    public function test_voiding_an_invoice_reverses_whatever_commission_remains(): void
    {
        $stylist = $this->stylist('percentage', '20.00');
        $invoice = $this->issueInvoice($this->cashier, [$this->line($stylist, '100.00')], [], ['customer_id' => $this->customer()->id]);
        $this->assertSame('20.00', $this->net($stylist));

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Mistake'])->assertSessionDoesntHaveErrors();

        $this->assertSame('0.00', $this->net($stylist));
        $this->assertSame('void', CommissionAdjustment::firstOrFail()->type);
        app(CommissionService::class)->reverseForVoid($invoice->fresh());
        $this->assertSame(1, CommissionAdjustment::count(), 'a second reversal is a no-op');
    }

    public function test_statements_show_earnings_and_adjustments_for_authorised_users_only(): void
    {
        $stylist = $this->stylist('percentage', '20.00');
        $other = $this->stylist('percentage', '50.00');
        $this->sell([$this->line($stylist, '100.00')], '100.00');
        $this->sell([$this->line($other, '100.00')], '100.00');
        $hr = User::factory()->withPermissions('employees.commissions')->create();

        $this->actingAs($hr)->post('/commissions/adjustments', ['employee_id' => $stylist->id, 'amount' => '5.50', 'reason' => 'Monthly bonus'])->assertSessionDoesntHaveErrors();
        $this->actingAs($hr)->post('/commissions/adjustments', ['employee_id' => $stylist->id, 'amount' => '0', 'reason' => 'x'])->assertSessionHasErrors('amount');
        $this->actingAs($hr)->post('/commissions/adjustments', ['employee_id' => $stylist->id, 'amount' => '5', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('audit_logs', ['action' => 'commission.manual_adjustment', 'reason' => 'Monthly bonus']);

        $this->actingAs($hr)->get("/commissions?employee={$stylist->id}")->assertOk()->assertSee('$25.50')->assertDontSee('$50.00');
        $this->assertSame('25.50', $this->net($stylist));

        $noRights = User::factory()->withPermissions('employees.view')->create();
        $this->actingAs($noRights)->get('/commissions')->assertForbidden();
        $this->actingAs($noRights)->post('/commissions/adjustments', ['employee_id' => $stylist->id, 'amount' => '5', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($noRights)->get('/commissions/export')->assertForbidden();
    }

    public function test_a_stylist_sees_only_their_own_statement(): void
    {
        $user = User::factory()->withPermissions('commissions.own')->create();
        $mine = Employee::factory()->create(['user_id' => $user->id, 'commission_type' => 'percentage', 'commission_rate' => '20.00']);
        $theirs = $this->stylist('percentage', '33.00');
        $this->sell([$this->line($mine, '100.00')], '100.00');
        $this->sell([$this->line($theirs, '100.00')], '100.00');

        $this->actingAs($user)->get('/my-commissions')->assertOk()->assertSee('$20.00')->assertDontSee('$33.00');
        $this->actingAs($user)->get("/my-commissions?employee={$theirs->id}")->assertOk()->assertDontSee('$33.00');
        $this->actingAs($user)->get('/commissions')->assertForbidden();

        $unlinked = User::factory()->withPermissions('commissions.own')->create();
        $this->actingAs($unlinked)->get('/my-commissions')->assertOk()->assertSee('not linked');
    }

    public function test_the_csv_export_is_permissioned_and_safe_against_formula_injection(): void
    {
        $stylist = $this->stylist();
        $this->sell([$this->line($stylist, '100.00')], '100.00');
        $hr = User::factory()->withPermissions('employees.commissions')->create();
        $this->actingAs($hr)->post('/commissions/adjustments', ['employee_id' => $stylist->id, 'amount' => '1', 'reason' => '=cmd|calc']);

        $csv = $this->actingAs($hr)->get('/commissions/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('NET TOTAL', $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString(',=cmd|calc', $csv);
    }

    public function test_commissions_and_adjustments_are_append_only(): void
    {
        $stylist = $this->stylist();
        $this->sell([$this->line($stylist, '100.00')], '100.00');
        app(CommissionService::class)->adjustManually($stylist, '1.00', 'Test', User::factory()->withPermissions('employees.commissions')->create());

        foreach ([fn () => Commission::firstOrFail()->update(['amount' => '99']), fn () => Commission::firstOrFail()->delete(),
            fn () => CommissionAdjustment::firstOrFail()->update(['amount' => '99']), fn () => CommissionAdjustment::firstOrFail()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected immutability.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_hr_settings_are_protected_validated_and_audited(): void
    {
        $this->actingAs($this->manager)->get('/settings/hr')->assertForbidden();
        $owner = User::factory()->withPermissions('settings.manage')->create();
        $this->actingAs($owner)->get('/settings/hr')->assertOk();
        $this->actingAs($owner)->put('/settings/hr', ['accrue_on' => 'whenever', 'grace_minutes' => 500])->assertSessionHasErrors(['accrue_on', 'grace_minutes']);
        $this->actingAs($owner)->put('/settings/hr', ['accrue_on' => 'full_payment', 'include_products' => 1, 'grace_minutes' => 5])->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.hr_updated']);
    }
}
