<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MembershipPlan;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $this->cashier = $this->cashier();
        $this->manager = $this->manager();
        $this->openDrawer($this->cashier);
        $this->openDrawer($this->manager, '0.00');
    }

    private function promo(array $attributes = [], string $code = 'SAVE10', array $coupon = []): Coupon
    {
        $promotion = Promotion::create($attributes + ['name' => 'Promo '.$code, 'discount_type' => 'percent', 'discount_value' => '10.00', 'min_purchase' => '0.00',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'is_active' => true]);

        $attributes = $coupon + ['code' => $code, 'is_active' => true];
        $model = new Coupon($attributes);
        $model->forceFill(['promotion_id' => $promotion->id, 'used_count' => 0])->save();

        return $model;
    }

    /** @return array<int, array<string, mixed>> */
    private function cart(string ...$prices): array
    {
        return array_map(fn ($price) => ['service_id' => $this->service(60, $price)->id, 'quantity' => 1], $prices);
    }

    private function checkout(array $items, string $pay, string $code = 'SAVE10', ?Customer $customer = null, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->cashier)->post('/pos', $this->checkoutPayload($items, [$this->cashPayment($pay)], ['coupon_code' => $code] + ($customer ? ['customer_id' => $customer->id] : [])));
    }

    public function test_a_percentage_coupon_is_shared_across_lines_to_the_cent_and_tax_follows_the_discounted_value(): void
    {
        $this->promo();
        $taxed = $this->service(60, '10.00', ['tax_rate' => '10.00']);
        $items = [['service_id' => $taxed->id, 'quantity' => 1], ...$this->cart('10.00', '10.01')];

        $this->checkout($items, '27.91')->assertSessionDoesntHaveErrors();   // 30.01 - 3.00 = 27.01, plus tax on the discounted taxed line

        $invoice = Invoice::firstOrFail();
        $this->assertSame('3.00', (string) $invoice->coupon_discount_total);
        $this->assertSame('3.00', Money::add(...$invoice->items->pluck('coupon_discount')->map(fn ($a) => (string) $a)->all()), 'shares add up exactly');
        $this->assertSame((string) $invoice->total, Money::add(...$invoice->items->pluck('line_total')->map(fn ($a) => (string) $a)->all()));
        $this->assertSame((string) $invoice->total, Money::add(Money::sub(Money::sub((string) $invoice->subtotal, (string) $invoice->discount_total), (string) $invoice->coupon_discount_total), (string) $invoice->tax_total));
        $taxedLine = $invoice->items->firstWhere('service_id', $taxed->id);
        $this->assertSame(Money::percent(Money::sub('10.00', (string) $taxedLine->coupon_discount), '10'), (string) $taxedLine->tax_amount);
        $redemption = CouponRedemption::firstOrFail();
        $this->assertSame('3.00', (string) $redemption->discount_amount);
        $this->assertSame(1, Coupon::firstOrFail()->used_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.redeemed']);
    }

    public function test_a_fixed_coupon_never_exceeds_the_purchase_and_codes_are_case_insensitive(): void
    {
        $this->promo(['discount_type' => 'fixed', 'discount_value' => '50.00'], 'BIG50');

        $this->checkout($this->cart('20.00'), '0.00', 'big50', $this->customer())->assertSessionDoesntHaveErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('20.00', (string) $invoice->coupon_discount_total);
        $this->assertSame('0.00', (string) $invoice->total);
        $this->assertSame('paid', $invoice->payment_status);
    }

    public function test_invalid_coupons_are_refused_with_a_clear_reason_and_nothing_is_saved(): void
    {
        $items = $this->cart('40.00');
        $this->checkout($items, '36.00', 'NOPE')->assertSessionHasErrors(['coupon_code' => 'This coupon code is not valid.']);

        $this->promo(['min_purchase' => '100.00'], 'MIN100');
        $this->checkout($items, '36.00', 'MIN100')->assertSessionHasErrors('coupon_code');

        $this->promo(['starts_at' => now()->addDay()], 'LATER');
        $this->checkout($items, '36.00', 'LATER')->assertSessionHasErrors(['coupon_code' => 'This promotion has not started yet.']);

        $this->promo(['ends_at' => now()->subHour()], 'OLD');
        $this->checkout($items, '36.00', 'OLD')->assertSessionHasErrors(['coupon_code' => 'This promotion has ended.']);

        $this->promo(['is_active' => false], 'OFFPROMO');
        $this->checkout($items, '36.00', 'OFFPROMO')->assertSessionHasErrors('coupon_code');

        $this->promo([], 'OFFCODE', ['is_active' => false]);
        $this->checkout($items, '36.00', 'OFFCODE')->assertSessionHasErrors('coupon_code');

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CouponRedemption::count());
    }

    public function test_usage_limits_are_enforced_per_coupon_per_promotion_and_per_customer(): void
    {
        $this->promo([], 'ONCE', ['max_uses' => 1]);
        $this->checkout($this->cart('40.00'), '36.00', 'ONCE')->assertSessionDoesntHaveErrors();
        $this->checkout($this->cart('40.00'), '36.00', 'ONCE')->assertSessionHasErrors(['coupon_code' => 'This coupon has reached its usage limit.']);

        $first = $this->promo(['max_redemptions' => 1], 'SHARED1');
        (new Coupon(['code' => 'SHARED2', 'is_active' => true]))
            ->forceFill(['promotion_id' => $first->promotion_id, 'used_count' => 0])->save();
        $this->checkout($this->cart('40.00'), '36.00', 'SHARED1')->assertSessionDoesntHaveErrors();
        $this->checkout($this->cart('40.00'), '36.00', 'SHARED2')->assertSessionHasErrors(['coupon_code' => 'This promotion has reached its redemption limit.']);

        $this->promo(['per_customer_limit' => 1], 'PERSON');
        $alice = $this->customer();
        $bob = $this->customer();
        $this->checkout($this->cart('40.00'), '36.00', 'PERSON')->assertSessionHasErrors('coupon_code');   // needs a customer
        $this->checkout($this->cart('40.00'), '36.00', 'PERSON', $alice)->assertSessionDoesntHaveErrors();
        $this->checkout($this->cart('40.00'), '36.00', 'PERSON', $alice)->assertSessionHasErrors('coupon_code');
        $this->checkout($this->cart('40.00'), '36.00', 'PERSON', $bob)->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Coupon::where('code', 'ONCE')->firstOrFail()->used_count);
    }

    public function test_resubmitting_a_checkout_cannot_redeem_a_coupon_twice(): void
    {
        $this->promo([], 'SAVE10', ['max_uses' => 5]);
        $payload = $this->checkoutPayload($this->cart('40.00'), [$this->cashPayment('36.00')], ['coupon_code' => 'SAVE10']);

        $this->actingAs($this->cashier)->post('/pos', $payload)->assertSessionDoesntHaveErrors();
        $this->actingAs($this->cashier)->post('/pos', $payload)->assertSessionHas('success');

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, CouponRedemption::count());
        $this->assertSame(1, Coupon::firstOrFail()->used_count);
    }

    public function test_voiding_releases_the_coupon_for_reuse(): void
    {
        $this->promo([], 'ONCE', ['max_uses' => 1]);
        $invoice = $this->issueInvoice($this->cashier, $this->cart('40.00'), [], ['coupon_code' => 'ONCE', 'customer_id' => $this->customer()->id]);
        $this->assertSame(1, Coupon::firstOrFail()->used_count);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Wrong items'])->assertSessionDoesntHaveErrors();

        $this->assertSame(0, Coupon::firstOrFail()->used_count);
        $this->assertSame('reversed', CouponRedemption::firstOrFail()->status);
        $this->issueInvoice($this->cashier, $this->cart('40.00'), [], ['coupon_code' => 'ONCE', 'customer_id' => $this->customer()->id]);
        $this->assertSame(1, Coupon::firstOrFail()->used_count);
    }

    public function test_memberships_are_excluded_from_coupon_discounts(): void
    {
        $plan = MembershipPlan::create(['name' => 'Gold', 'price' => '100.00', 'duration_days' => 30, 'discount_percent' => '0', 'points_multiplier' => '1', 'is_active' => true]);
        $this->promo();

        $invoice = $this->issueInvoice($this->cashier, [['membership_plan_id' => $plan->id], ...$this->cart('50.00')], [], ['coupon_code' => 'SAVE10', 'customer_id' => $this->customer()->id]);

        $this->assertSame('5.00', (string) $invoice->coupon_discount_total, '10% of the 50.00 service only');
        $this->assertSame('0.00', (string) $invoice->items->firstWhere('item_type', 'membership')->coupon_discount);
        $this->assertSame('145.00', (string) $invoice->total);
    }

    public function test_the_pos_preview_returns_the_servers_totals_and_validation_errors(): void
    {
        $this->promo();
        $items = $this->cart('40.00', '10.00');

        $response = $this->actingAs($this->cashier)->postJson('/pos/preview', ['items' => $items, 'coupon_code' => 'save10'])->assertOk();
        $response->assertJsonPath('total', '45.00')->assertJsonPath('coupon_discount_total', '5.00')->assertJsonPath('coupon', 'SAVE10');
        $this->assertSame(0, Invoice::count(), 'a preview saves nothing');
        $this->assertSame(0, Coupon::firstOrFail()->used_count);

        $this->actingAs($this->cashier)->postJson('/pos/preview', ['items' => $items, 'coupon_code' => 'BOGUS'])->assertStatus(422)->assertJsonPath('errors.coupon_code.0', 'This coupon code is not valid.');
        $this->actingAs($this->receptionist())->postJson('/pos/preview', ['items' => $items])->assertForbidden();
        $this->actingAs($this->cashier)->postJson('/pos/preview', ['items' => []])->assertStatus(422);
    }

    public function test_a_draft_remembers_its_coupon_and_it_is_redeemed_when_the_draft_is_issued(): void
    {
        $this->promo();
        $items = $this->cart('40.00');
        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload($items, [], ['action' => 'save_draft', 'coupon_code' => 'SAVE10']))->assertSessionDoesntHaveErrors();
        $draft = Invoice::firstOrFail();
        $this->assertSame('3.60', Money::sub('4.00', '0.40'), 'sanity');
        $this->assertSame('4.00', (string) $draft->coupon_discount_total);
        $this->assertSame(0, CouponRedemption::count(), 'drafts never consume a coupon');
        $this->actingAs($this->cashier)->get("/pos?draft={$draft->id}")->assertOk()->assertSee('SAVE10');

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload($items, [$this->cashPayment('36.00')], ['coupon_code' => 'SAVE10', 'draft_id' => $draft->id]))->assertSessionDoesntHaveErrors();
        $this->assertSame(1, CouponRedemption::count());
        $this->assertSame($draft->id, CouponRedemption::firstOrFail()->invoice_id);
    }

    public function test_promotions_and_coupons_are_managed_with_permission_and_validated(): void
    {
        $manager = User::factory()->withPermissions('loyalty.view', 'loyalty.manage')->create();
        $viewer = User::factory()->withPermissions('loyalty.view')->create();
        $payload = ['name' => 'Eid Special', 'discount_type' => 'percent', 'discount_value' => '15', 'min_purchase' => '20', 'starts_at' => '2026-10-20T00:00', 'ends_at' => '2026-11-20T23:59'];

        $this->actingAs($viewer)->get('/promotions')->assertOk();
        $this->actingAs($viewer)->get('/promotions/create')->assertForbidden();
        $this->actingAs($viewer)->post('/promotions', $payload)->assertForbidden();
        $this->actingAs($manager)->post('/promotions', ['discount_value' => '150', 'ends_at' => '2026-10-01T00:00'] + $payload)->assertSessionHasErrors(['discount_value', 'ends_at']);
        $this->actingAs($manager)->post('/promotions', ['discount_value' => '0'] + $payload)->assertSessionHasErrors('discount_value');
        $this->actingAs($manager)->post('/promotions', $payload)->assertSessionDoesntHaveErrors();

        $promotion = Promotion::firstOrFail();
        $this->actingAs($manager)->post("/promotions/{$promotion->id}/coupons", ['code' => 'eid15', 'max_uses' => 100])->assertSessionDoesntHaveErrors();
        $this->actingAs($manager)->post("/promotions/{$promotion->id}/coupons", ['code' => 'EID15'])->assertSessionHasErrors('code');
        $this->actingAs($manager)->post("/promotions/{$promotion->id}/coupons", ['code' => 'bad code!'])->assertSessionHasErrors('code');
        $this->assertSame('EID15', Coupon::firstOrFail()->code);
        $this->actingAs($viewer)->post("/promotions/{$promotion->id}/coupons", ['code' => 'SNEAKY'])->assertForbidden();

        $coupon = Coupon::firstOrFail();
        $this->actingAs($manager)->post("/coupons/{$coupon->id}/toggle")->assertRedirect();
        $this->assertFalse($coupon->fresh()->is_active);
        $this->actingAs($manager)->get("/promotions/{$promotion->id}")->assertOk()->assertSee('EID15');
        $this->assertDatabaseHas('audit_logs', ['action' => 'promotion.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.deactivated']);
    }
}
