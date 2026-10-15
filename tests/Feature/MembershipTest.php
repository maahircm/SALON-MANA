<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\Invoice;
use App\Models\LoyaltyAccount;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $this->cashier = $this->cashier();
        $this->manager = $this->manager();
        $this->openDrawer($this->cashier);
        $this->openDrawer($this->manager, '0.00');
        $this->customer = $this->customer();
    }

    private function plan(array $attributes = []): MembershipPlan
    {
        return MembershipPlan::create($attributes + ['name' => 'Gold '.Str::random(4), 'price' => '100.00', 'duration_days' => 30, 'discount_percent' => '10.00', 'points_multiplier' => '1.00', 'is_active' => true]);
    }

    private function buy(MembershipPlan $plan, ?Customer $customer = null): Invoice
    {
        return $this->issueInvoice($this->cashier, [['membership_plan_id' => $plan->id, 'quantity' => 1]], [$this->cashPayment((string) $plan->price)], ['customer_id' => ($customer ?? $this->customer)->id]);
    }

    private function serviceSale(string $price, string $pay, array $line = []): Invoice
    {
        return $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, $price)->id, 'quantity' => 1] + $line], [$this->cashPayment($pay)], ['customer_id' => $this->customer->id]);
    }

    public function test_selling_a_membership_creates_an_active_membership_with_the_terms_it_was_sold_under(): void
    {
        $plan = $this->plan(['duration_days' => 30, 'discount_percent' => '15.00', 'points_multiplier' => '2.00']);
        $invoice = $this->buy($plan);

        $membership = CustomerMembership::firstOrFail();
        $this->assertSame($this->customer->id, $membership->customer_id);
        $this->assertSame($invoice->id, $membership->invoice_id);
        $this->assertSame('2026-10-19', $membership->starts_on->toDateString());
        $this->assertSame('2026-11-17', $membership->ends_on->toDateString(), '30 days including the first');
        $this->assertSame('active', $membership->status);
        $this->assertSame('100.00', (string) $membership->price_paid);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.activated']);

        $plan->update(['discount_percent' => '99.00', 'price' => '500.00']);
        $this->assertSame('15.00', (string) $membership->fresh()->discount_percent, 'later plan edits never change a sold membership');
    }

    public function test_membership_sales_need_a_customer_an_active_plan_and_only_one_per_invoice(): void
    {
        $plan = $this->plan();
        $inactive = $this->plan(['is_active' => false]);

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['membership_plan_id' => $plan->id]], [$this->cashPayment('100.00')]))->assertSessionHasErrors('items.0.membership_plan_id');
        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['membership_plan_id' => $inactive->id]], [], ['customer_id' => $this->customer->id]))->assertSessionHasErrors('items.0.membership_plan_id');
        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['membership_plan_id' => $plan->id], ['membership_plan_id' => $plan->id]], [], ['customer_id' => $this->customer->id]))->assertSessionHasErrors('items.1.membership_plan_id');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CustomerMembership::count());
    }

    public function test_members_get_their_discount_automatically_without_the_discount_permission(): void
    {
        $this->buy($this->plan(['discount_percent' => '10.00']));
        $this->assertFalse($this->cashier->can('pos.discount'));

        $invoice = $this->serviceSale('50.00', '45.00');

        $item = $invoice->items->first();
        $this->assertSame('5.00', (string) $item->discount_amount);
        $this->assertSame('membership', $item->discount_source);
        $this->assertSame('45.00', (string) $invoice->total);
    }

    public function test_a_manual_discount_wins_over_the_member_discount_and_the_membership_line_itself_is_never_discounted(): void
    {
        $this->buy($this->plan(['discount_percent' => '10.00']));
        $discounter = $this->cashier('pos.discount');
        $this->openDrawer($discounter);

        $invoice = $this->issueInvoice($discounter, [['service_id' => $this->service(60, '100.00')->id, 'quantity' => 1, 'discount_type' => 'fixed', 'discount_value' => '5.00']], [$this->cashPayment('95.00')], ['customer_id' => $this->customer->id]);
        $this->assertSame('manual', $invoice->items->first()->discount_source);
        $this->assertSame('5.00', (string) $invoice->items->first()->discount_amount);

        $renewal = $this->buy($this->plan(['name' => 'Silver', 'price' => '60.00']));
        $this->assertSame('60.00', (string) $renewal->total, 'a membership is not discounted by another membership');
    }

    public function test_expired_cancelled_and_not_yet_started_memberships_give_no_discount(): void
    {
        $plan = $this->plan();
        $make = fn (array $a) => CustomerMembership::create($a + ['customer_id' => $this->customer->id, 'membership_plan_id' => $plan->id, 'price_paid' => '100.00', 'discount_percent' => '10.00', 'points_multiplier' => '1.00']);
        $make(['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'status' => 'active']);
        $make(['starts_on' => '2026-10-01', 'ends_on' => '2026-12-01', 'status' => 'cancelled']);
        $make(['starts_on' => '2026-11-01', 'ends_on' => '2026-12-01', 'status' => 'active']);

        $this->assertNull($this->customer->activeMembership());
        $this->assertSame('0.00', (string) $this->serviceSale('50.00', '50.00')->items->first()->discount_amount);
    }

    public function test_a_renewal_starts_the_day_after_the_current_membership_ends(): void
    {
        $plan = $this->plan(['duration_days' => 30]);
        $this->buy($plan);
        $this->buy($plan);

        $renewal = CustomerMembership::orderBy('id')->get()->last();
        $this->assertSame('2026-11-18', $renewal->starts_on->toDateString());
        $this->assertSame('2026-12-17', $renewal->ends_on->toDateString());
        $this->assertSame(2, CustomerMembership::count());
    }

    public function test_the_points_multiplier_applies_to_new_purchases(): void
    {
        $this->buy($this->plan(['discount_percent' => '0.00', 'points_multiplier' => '2.00']));
        $this->assertSame(0, (int) LoyaltyAccount::value('balance'), 'buying the membership itself earns nothing');

        $this->serviceSale('40.00', '40.00');

        $this->assertSame(80, (int) LoyaltyAccount::firstOrFail()->balance, '40 points doubled');
    }

    public function test_voiding_or_fully_refunding_the_sale_ends_the_membership(): void
    {
        $plan = $this->plan();
        $unpaid = $this->issueInvoice($this->cashier, [['membership_plan_id' => $plan->id]], [], ['customer_id' => $this->customer->id]);
        $this->assertSame('active', CustomerMembership::firstOrFail()->status);
        $this->actingAs($this->manager)->post("/invoices/{$unpaid->id}/void", ['reason' => 'Changed mind'])->assertSessionDoesntHaveErrors();
        $this->assertSame('cancelled', CustomerMembership::firstOrFail()->status);

        $other = $this->customer();
        $paid = $this->buy($plan, $other);
        $this->actingAs($this->manager)->post("/invoices/{$paid->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('cash')->id,
            'reason' => 'Refund', 'quantities' => [$paid->items->first()->id => 1]])->assertSessionDoesntHaveErrors();
        $this->assertSame('cancelled', CustomerMembership::where('customer_id', $other->id)->firstOrFail()->status);
        $this->assertNull($other->activeMembership());
    }

    public function test_managers_cancel_memberships_with_a_reason_and_overdue_ones_expire(): void
    {
        $this->buy($this->plan());
        $membership = CustomerMembership::firstOrFail();
        $viewer = User::factory()->withPermissions('loyalty.view')->create();
        $manager = User::factory()->withPermissions('loyalty.view', 'loyalty.manage')->create();

        $this->actingAs($viewer)->post("/memberships/{$membership->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($manager)->post("/memberships/{$membership->id}/cancel", [])->assertSessionHasErrors('reason');
        $this->actingAs($manager)->post("/memberships/{$membership->id}/cancel", ['reason' => 'Customer request'])->assertSessionDoesntHaveErrors();
        $this->assertSame('cancelled', $membership->fresh()->status);
        $this->actingAs($manager)->post("/memberships/{$membership->id}/cancel", ['reason' => 'again'])->assertSessionHasErrors('membership');

        $this->buy($this->plan(['name' => 'Short', 'duration_days' => 5, 'price' => '10.00']), $this->customer());
        $this->assertSame(0, app(MembershipService::class)->expireOverdue());
        $this->freezeSalonTime();
        Carbon::setTestNow(now()->addDays(10));
        $this->assertSame(1, app(MembershipService::class)->expireOverdue());
        $this->assertSame('expired', CustomerMembership::where('status', 'expired')->firstOrFail()->status);
    }

    public function test_plan_management_is_permissioned_and_validated(): void
    {
        $manager = User::factory()->withPermissions('loyalty.view', 'loyalty.manage')->create();
        $viewer = User::factory()->withPermissions('loyalty.view')->create();
        $payload = ['name' => 'Platinum', 'price' => '250.00', 'duration_days' => 365, 'discount_percent' => '20', 'points_multiplier' => '3'];

        $this->actingAs($viewer)->get('/memberships')->assertOk();
        $this->actingAs($viewer)->post('/memberships/plans', $payload)->assertForbidden();
        $this->actingAs($manager)->post('/memberships/plans', $payload)->assertSessionDoesntHaveErrors();
        $this->actingAs($manager)->post('/memberships/plans', $payload)->assertSessionHasErrors('name');
        $this->actingAs($manager)->post('/memberships/plans', ['discount_percent' => '120', 'price' => '-1', 'duration_days' => 0, 'name' => 'Bad'])->assertSessionHasErrors(['discount_percent', 'price', 'duration_days']);

        $plan = MembershipPlan::where('name', 'Platinum')->firstOrFail();
        $this->actingAs($manager)->put("/memberships/plans/{$plan->id}", ['price' => '300.00', 'is_active' => 0] + $payload)->assertSessionDoesntHaveErrors();
        $this->assertFalse($plan->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership_plan.updated']);
    }
}
