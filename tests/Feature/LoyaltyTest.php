<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = $this->cashier();
        $this->manager = $this->manager();
        $this->openDrawer($this->cashier);
        $this->openDrawer($this->manager, '0.00');
        $this->customer = $this->customer();
    }

    private function points(?Customer $customer = null): int
    {
        return (int) (LoyaltyAccount::where('customer_id', ($customer ?? $this->customer)->id)->value('balance') ?? 0);
    }

    private function sale(string $price, array $payments, array $service = [], ?Customer $customer = null): Invoice
    {
        return $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, $price, $service)->id, 'quantity' => 1]], $payments, ['customer_id' => ($customer ?? $this->customer)->id]);
    }

    private function grant(int $points, ?Customer $customer = null): void
    {
        app(LoyaltyService::class)->adjust($customer ?? $this->customer, $points, 'Test grant', User::factory()->withPermissions('loyalty.manage')->create());
    }

    private function refund(Invoice $invoice, array $quantities, string $method = 'cash'): void
    {
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod($method)->id,
            'reason' => 'Return', 'quantities' => $quantities])->assertSessionDoesntHaveErrors();
    }

    public function test_points_are_earned_on_net_spend_excluding_tax_when_the_invoice_is_fully_paid(): void
    {
        $this->sale('50.00', [$this->cashPayment('52.50')], ['tax_rate' => '5.00']);

        $this->assertSame(50, $this->points());
        $tx = LoyaltyTransaction::firstOrFail();
        $this->assertSame('earn', $tx->type);
        $this->assertSame(50, $tx->balance_after);
        $this->assertSame(50, LoyaltyAccount::firstOrFail()->lifetime_earned);
    }

    public function test_nothing_is_earned_until_fully_paid_and_then_only_once(): void
    {
        $invoice = $this->sale('100.00', [$this->cashPayment('40.00')]);
        $this->assertSame(0, $this->points());

        $pay = fn () => $this->actingAs($this->cashier)->post("/invoices/{$invoice->id}/payments", ['idempotency_key' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->paymentMethod('cash')->id, 'amount' => '60.00']]]);
        $pay();
        $this->assertSame(100, $this->points());

        app(LoyaltyService::class)->awardForInvoice($invoice->fresh());
        app(LoyaltyService::class)->awardForInvoice($invoice->fresh());
        $this->assertSame(100, $this->points(), 'awarding is idempotent');
        $this->assertSame(1, LoyaltyTransaction::where('type', 'earn')->count());
    }

    public function test_no_points_for_walk_ins_when_disabled_or_for_a_zero_rate(): void
    {
        $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '30.00')->id, 'quantity' => 1]], [$this->cashPayment('30.00')]);
        $this->assertSame(0, LoyaltyTransaction::count());

        $this->setting('loyalty.enabled', false);
        $this->sale('30.00', [$this->cashPayment('30.00')]);
        $this->assertSame(0, LoyaltyTransaction::count());

        $this->setting('loyalty.enabled', true);
        $this->setting('loyalty.earn_points_per_unit', '0');
        $this->sale('30.00', [$this->cashPayment('30.00')]);
        $this->assertSame(0, LoyaltyTransaction::count());
    }

    public function test_the_earn_rate_is_configurable(): void
    {
        $this->setting('loyalty.earn_points_per_unit', '2.50');
        $this->sale('20.00', [$this->cashPayment('20.00')]);

        $this->assertSame(50, $this->points());
    }

    public function test_points_can_pay_part_of_an_invoice_without_a_cash_drawer_and_the_balance_never_goes_negative(): void
    {
        $this->grant(500);
        $noDrawer = $this->cashier();
        $service = $this->service(60, '20.00');
        $points = ['payment_method_id' => $this->paymentMethod('loyalty_points')->id, 'amount' => '5.00'];

        $invoice = app(CheckoutService::class)->checkout($this->checkoutPayload([['service_id' => $service->id, 'quantity' => 1]], [$points], ['customer_id' => $this->customer->id]), $noDrawer)['invoice'];

        $this->assertSame(0, $this->points());
        $this->assertSame('partially_paid', $invoice->payment_status);
        $payment = Payment::firstOrFail();
        $this->assertNull($payment->cash_drawer_session_id, 'points never touch a drawer');
        $redeem = LoyaltyTransaction::where('type', 'redeem')->firstOrFail();
        $this->assertSame(-500, $redeem->points);
        $this->assertSame($payment->id, $redeem->payment_id);
    }

    public function test_redemption_rules_are_enforced_server_side(): void
    {
        $this->grant(500);
        $service = $this->service(60, '20.00');
        $try = fn (string $amount, ?Customer $customer = null) => $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['service_id' => $service->id, 'quantity' => 1]],
            [['payment_method_id' => $this->paymentMethod('loyalty_points')->id, 'amount' => $amount]], ['customer_id' => ($customer ?? $this->customer)->id]));

        $try('6.00')->assertSessionHasErrors('points');                       // needs 600 points, has 500
        $try('0.50')->assertSessionHasErrors('payments');                     // below the 100 point minimum? 0.50 = 50 points
        $this->assertSame(500, $this->points());

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['service_id' => $service->id, 'quantity' => 1]],
            [['payment_method_id' => $this->paymentMethod('loyalty_points')->id, 'amount' => '5.00']]))->assertSessionHasErrors();   // no customer
        $this->assertSame(0, Invoice::count());

        $this->setting('loyalty.max_redeem_percent', '10');
        $try('5.00')->assertSessionHasErrors('payments');                     // 10% of 20.00 = 2.00 max
        $this->setting('loyalty.max_redeem_percent', '100');
        $try('5.00')->assertSessionDoesntHaveErrors();
        $this->assertSame(0, $this->points());
    }

    public function test_the_part_paid_with_points_earns_no_new_points(): void
    {
        $this->grant(500);
        $this->sale('20.00', [['payment_method_id' => $this->paymentMethod('loyalty_points')->id, 'amount' => '5.00'], $this->cashPayment('15.00')]);

        $this->assertSame(15, $this->points(), '500 spent, 15 earned on the 15.00 paid in cash');
    }

    public function test_refunds_claw_back_earned_points_proportionally_but_never_below_zero(): void
    {
        $invoice = $this->sale('100.00', [$this->cashPayment('100.00')]);
        $this->assertSame(100, $this->points());

        $this->refund($invoice, [$invoice->items->first()->id => 1]);

        $this->assertSame(0, $this->points());
        $this->assertSame(['earn', 'earn_reversal'], LoyaltyTransaction::orderBy('id')->pluck('type')->all());
        $this->assertSame(-100, LoyaltyTransaction::where('type', 'earn_reversal')->firstOrFail()->points);
    }

    public function test_clawback_is_capped_at_the_points_still_available(): void
    {
        $this->setting('loyalty.min_redeem_points', 0);
        $invoice = $this->sale('100.00', [$this->cashPayment('100.00')]);
        $this->assertSame(100, $this->points());
        // Spend 80 of those points (0.80 of value) on another sale; 9.20 in cash earns 9 more.
        $this->sale('10.00', [['payment_method_id' => $this->paymentMethod('loyalty_points')->id, 'amount' => '0.80'], $this->cashPayment('9.20')]);
        $this->assertSame(29, $this->points());

        $this->refund($invoice, [$invoice->items->first()->id => 1]);

        $this->assertSame(0, $this->points(), 'only the 29 remaining points could be clawed back, never below zero');
        $this->assertSame(-29, LoyaltyTransaction::where('type', 'earn_reversal')->firstOrFail()->points);
        $this->assertSame([], app(LoyaltyService::class)->reconcile());
    }

    public function test_refunding_to_the_points_method_returns_the_points(): void
    {
        $this->grant(500);
        $service = $this->service(60, '5.00');
        $invoice = $this->issueInvoice($this->cashier, [['service_id' => $service->id, 'quantity' => 4]],
            [['payment_method_id' => $this->paymentMethod('loyalty_points')->id, 'amount' => '5.00'], $this->cashPayment('15.00')], ['customer_id' => $this->customer->id]);
        $this->assertSame(15, $this->points(), '500 spent, 15 earned on the cash part');

        $this->refund($invoice, [$invoice->items->first()->id => 1], 'loyalty_points');

        $this->assertSame(500, LoyaltyTransaction::where('type', 'redeem_refund')->firstOrFail()->points);
        $this->assertSame(15 + 500 - 3, $this->points(), '5.00 of 20.00 refunded: 3 of the 15 earned points reversed, 500 redeemed points returned');
    }

    public function test_a_refund_to_the_points_method_cannot_exceed_what_was_paid_with_points(): void
    {
        $this->grant(500);
        $invoice = $this->sale('20.00', [['payment_method_id' => $this->paymentMethod('loyalty_points')->id, 'amount' => '5.00'], $this->cashPayment('15.00')]);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('loyalty_points')->id,
            'reason' => 'Return', 'quantities' => [$invoice->items->first()->id => 1]])->assertSessionHasErrors('payment_method_id');
        $this->assertSame(0, LoyaltyTransaction::where('type', 'redeem_refund')->count());
    }

    public function test_voiding_reverses_points_left_over_after_rounded_partial_refunds(): void
    {
        $this->setting('loyalty.earn_points_per_unit', '0.5');
        $invoice = $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '1.00')->id, 'quantity' => 3]], [$this->cashPayment('3.00')], ['customer_id' => $this->customer->id]);
        $this->assertSame(1, $this->points());
        $itemId = $invoice->items->first()->id;

        foreach ([1, 1, 1] as $ignored) {
            $this->refund($invoice, [$itemId => 1]);
        }
        $this->assertSame(1, $this->points(), 'each 1.00 refund is worth 0 whole points, so the earned point is still there');

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Fully refunded'])->assertSessionDoesntHaveErrors();

        $this->assertSame(0, $this->points());
        $this->assertSame(-1, LoyaltyTransaction::where('idempotency_key', "earn_reversal:void:{$invoice->id}")->firstOrFail()->points);
        $this->assertSame([], app(LoyaltyService::class)->reconcile());
    }

    public function test_managers_adjust_points_with_a_reason_and_cannot_go_below_zero(): void
    {
        $manager = User::factory()->withPermissions('loyalty.view', 'loyalty.manage')->create();
        $url = "/loyalty/customers/{$this->customer->id}/adjust";

        $this->actingAs($manager)->post($url, ['points' => 200, 'reason' => 'Birthday gift'])->assertSessionDoesntHaveErrors();
        $this->assertSame(200, $this->points());
        $this->actingAs($manager)->post($url, ['points' => -300, 'reason' => 'Correction'])->assertSessionHasErrors('points');
        $this->actingAs($manager)->post($url, ['points' => -50, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($manager)->post($url, ['points' => 0, 'reason' => 'x'])->assertSessionHasErrors('points');
        $this->actingAs($manager)->post($url, ['points' => -50, 'reason' => 'Correction'])->assertSessionDoesntHaveErrors();
        $this->assertSame(150, $this->points());
        $this->assertDatabaseHas('audit_logs', ['action' => 'loyalty.adjustment', 'reason' => 'Birthday gift']);

        $viewer = User::factory()->withPermissions('loyalty.view')->create();
        $this->actingAs($viewer)->post($url, ['points' => 10, 'reason' => 'x'])->assertForbidden();
        $this->assertSame(150, $this->points());
    }

    public function test_the_points_ledger_is_immutable_and_always_reconciles(): void
    {
        $this->grant(300);
        $this->sale('10.00', [$this->cashPayment('10.00')]);

        $this->assertSame([], app(LoyaltyService::class)->reconcile());
        $this->assertEquals(310, LoyaltyTransaction::sum('points'));
        foreach ([fn () => LoyaltyTransaction::firstOrFail()->update(['points' => 1]), fn () => LoyaltyTransaction::firstOrFail()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected immutability.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_loyalty_screens_and_settings_are_permissioned_validated_and_audited(): void
    {
        $this->grant(120);
        $viewer = User::factory()->withPermissions('loyalty.view')->create();
        $this->actingAs($viewer)->get('/loyalty')->assertOk()->assertSee($this->customer->name);
        $this->actingAs($viewer)->get("/loyalty/customers/{$this->customer->id}")->assertOk()->assertSee('Test grant');
        $this->actingAs($viewer)->get('/loyalty/settings')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/loyalty')->assertForbidden();

        $manager = User::factory()->withPermissions('loyalty.view', 'loyalty.manage')->create();
        $this->actingAs($manager)->get('/loyalty/settings')->assertOk();
        $this->actingAs($manager)->put('/loyalty/settings', ['earn_points_per_unit' => '-1', 'redeem_points_per_unit' => '0', 'min_redeem_points' => 'x', 'max_redeem_percent' => '150', 'vip_threshold' => '1'])
            ->assertSessionHasErrors(['earn_points_per_unit', 'redeem_points_per_unit', 'min_redeem_points', 'max_redeem_percent']);
        $this->actingAs($manager)->put('/loyalty/settings', ['enabled' => 1, 'earn_points_per_unit' => '2', 'redeem_points_per_unit' => '50', 'min_redeem_points' => '10', 'max_redeem_percent' => '50', 'vip_threshold' => '300'])->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.loyalty_updated']);
        $this->assertSame('50', app(SettingsService::class)->get('loyalty.redeem_points_per_unit'));
    }
}
