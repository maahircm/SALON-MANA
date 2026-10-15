<?php

namespace Tests\Feature;

use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns;
use Tests\TestCase;

class CashDrawerTest extends TestCase
{
    use Concerns, RefreshDatabase;

    public function test_a_cashier_opens_a_session_and_cannot_open_a_second_one(): void
    {
        $cashier = $this->cashier();
        $drawer = CashDrawer::firstOrFail();

        $this->actingAs($cashier)->post('/cash/open', ['cash_drawer_id' => $drawer->id, 'opening_cash' => '150.50'])->assertRedirect();
        $session = CashDrawerSession::firstOrFail();
        $this->assertSame('150.50', (string) $session->opening_cash);
        $this->assertTrue($session->isOpen());
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash_drawer.opened', 'user_id' => $cashier->id]);

        $other = CashDrawer::create(['name' => 'Back office', 'is_active' => true]);
        $this->actingAs($cashier)->post('/cash/open', ['cash_drawer_id' => $other->id, 'opening_cash' => '10'])->assertSessionHasErrors('session');
        $this->assertSame(1, CashDrawerSession::count());
    }

    public function test_one_open_session_per_drawer(): void
    {
        $first = $this->cashier();
        $second = $this->cashier();
        $drawer = CashDrawer::firstOrFail();
        app(CashDrawerService::class)->open($first, $drawer, '10.00');

        $this->actingAs($second)->post('/cash/open', ['cash_drawer_id' => $drawer->id, 'opening_cash' => '10'])->assertSessionHasErrors('session');
        $this->assertSame(1, CashDrawerSession::count());
    }

    public function test_opening_cash_is_validated_and_inactive_drawers_are_refused(): void
    {
        $cashier = $this->cashier();
        $drawer = CashDrawer::firstOrFail();
        $this->actingAs($cashier)->post('/cash/open', ['cash_drawer_id' => $drawer->id, 'opening_cash' => '-5'])->assertSessionHasErrors('opening_cash');
        $this->actingAs($cashier)->post('/cash/open', ['cash_drawer_id' => $drawer->id, 'opening_cash' => '1.234'])->assertSessionHasErrors('opening_cash');
        $drawer->update(['is_active' => false]);
        $this->actingAs($cashier)->post('/cash/open', ['cash_drawer_id' => $drawer->id, 'opening_cash' => '5'])->assertSessionHasErrors('cash_drawer_id');
        $this->assertSame(0, CashDrawerSession::count());
    }

    public function test_expected_cash_reconciles_opening_sales_refunds_paid_in_and_paid_out(): void
    {
        $cashier = $this->cashier();
        $manager = $this->manager();
        $session = $this->openDrawer($cashier, '100.00');
        $this->openDrawer($manager, '0.00');
        $service = $this->service(60, '50.00');

        $this->issueInvoice($cashier, [['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('50.00', '60.00')]);              // +50 cash (change not counted)
        $card = $this->issueInvoice($cashier, [['service_id' => $service->id, 'quantity' => 1]], [$this->cardPayment('50.00')]);                 // card: not in the drawer
        $refunded = $this->issueInvoice($cashier, [['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('50.00')]);             // +50 cash
        $this->actingAs($manager)->post("/invoices/{$refunded->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('cash')->id,
            'reason' => 'Returned', 'quantities' => [$refunded->items->first()->id => 1]]);                                                       // -50 cash, from the manager's drawer
        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/movements", ['type' => 'paid_in', 'amount' => '20.00', 'reason' => 'Float top-up']);
        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/movements", ['type' => 'paid_out', 'amount' => '15.25', 'reason' => 'Milk and tea']);

        $recon = app(CashDrawerService::class)->reconciliation($session->fresh());
        $this->assertSame('100.00', $recon['opening']);
        $this->assertSame('100.00', $recon['cash_sales']);
        $this->assertSame('0.00', $recon['cash_refunds']);
        $this->assertSame('20.00', $recon['paid_in']);
        $this->assertSame('15.25', $recon['paid_out']);
        $this->assertSame('204.75', $recon['expected']);

        $managerSession = CashDrawerSession::where('opened_by', $manager->id)->firstOrFail();
        $this->assertSame('-50.00', app(CashDrawerService::class)->reconciliation($managerSession)['expected']);
        $this->assertSame('50.00', (string) $card->fresh()->amount_paid, 'the card sale is paid but never counted as drawer cash');
    }

    public function test_closing_with_a_matching_count_freezes_the_session_and_records_a_snapshot(): void
    {
        $cashier = $this->cashier();
        $session = $this->openDrawer($cashier, '100.00');
        $this->issueInvoice($cashier, [['service_id' => $this->service(60, '40.00')->id, 'quantity' => 1]], [$this->cashPayment('25.00'), $this->cardPayment('15.00')]);

        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/close", ['counted_cash' => '125.00'])->assertSessionDoesntHaveErrors()->assertRedirect();

        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertSame('125.00', (string) $session->expected_cash);
        $this->assertSame('0.00', (string) $session->variance);
        $this->assertEquals(['Cash' => '25.00', 'Card' => '15.00'], $session->summary['payments_by_method'], 'JSON key order is not significant');
        $this->assertNull($session->open_drawer_key);
        $this->assertNull($session->open_user_key);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash_drawer.closed']);

        try {
            $session->update(['counted_cash' => '999.00']);
            $this->fail('A closed session must not be editable.');
        } catch (LogicException) {
            $this->assertSame('125.00', (string) $session->fresh()->counted_cash);
        }
        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/close", ['counted_cash' => '1'])->assertSessionHasErrors('session');
        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/movements", ['type' => 'paid_in', 'amount' => '5', 'reason' => 'late'])->assertSessionHasErrors('session');
    }

    public function test_a_count_that_differs_needs_an_explanation_and_the_variance_is_recorded(): void
    {
        $cashier = $this->cashier();
        $session = $this->openDrawer($cashier, '100.00');

        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/close", ['counted_cash' => '97.50'])->assertSessionHasErrors('closing_notes');
        $this->assertTrue($session->fresh()->isOpen());

        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/close", ['counted_cash' => '97.50', 'closing_notes' => 'Gave wrong change'])->assertSessionDoesntHaveErrors();
        $this->assertSame('-2.50', (string) $session->fresh()->variance);
    }

    public function test_after_closing_a_new_session_is_needed_to_take_payments(): void
    {
        $cashier = $this->cashier();
        $session = $this->openDrawer($cashier, '0.00');
        $this->actingAs($cashier)->post("/cash/sessions/{$session->id}/close", ['counted_cash' => '0']);
        $service = $this->service(60, '10.00');

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('10.00')]))->assertSessionHasErrors('session');
        $this->openDrawer($cashier, '0.00');
        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('10.00')]))->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Invoice::count());
    }

    public function test_sessions_belong_to_their_cashier_unless_the_user_can_review_all(): void
    {
        $cashier = $this->cashier();
        $other = $this->cashier();
        $mine = $this->openDrawer($cashier);
        $theirs = $this->openDrawer($other);
        $manager = $this->manager();

        $this->actingAs($cashier)->get("/cash/sessions/{$mine->id}")->assertOk();
        $this->actingAs($cashier)->get("/cash/sessions/{$theirs->id}")->assertForbidden();
        $this->actingAs($cashier)->post("/cash/sessions/{$theirs->id}/close", ['counted_cash' => '100'])->assertForbidden();
        $this->actingAs($cashier)->post("/cash/sessions/{$theirs->id}/movements", ['type' => 'paid_out', 'amount' => '5', 'reason' => 'steal'])->assertForbidden();
        $this->assertTrue($theirs->fresh()->isOpen());
        $this->assertSame(0, $theirs->movements()->count());

        $this->actingAs($cashier)->get('/cash')->assertOk()->assertDontSee("cash/sessions/{$theirs->id}");
        $this->actingAs($manager)->get('/cash')->assertOk()->assertSee("cash/sessions/{$theirs->id}");
        $this->actingAs($manager)->post("/cash/sessions/{$theirs->id}/close", ['counted_cash' => '100'])->assertSessionDoesntHaveErrors();
        $this->assertSame('closed', $theirs->fresh()->status);
    }

    public function test_cash_movements_are_validated_and_append_only(): void
    {
        $cashier = $this->cashier();
        $session = $this->openDrawer($cashier);
        $url = "/cash/sessions/{$session->id}/movements";
        $this->actingAs($cashier)->post($url, ['type' => 'paid_in', 'amount' => '0', 'reason' => 'x'])->assertSessionHasErrors('amount');
        $this->actingAs($cashier)->post($url, ['type' => 'steal', 'amount' => '5', 'reason' => 'x'])->assertSessionHasErrors('type');
        $this->actingAs($cashier)->post($url, ['type' => 'paid_out', 'amount' => '5', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(0, $session->movements()->count());

        $this->actingAs($cashier)->post($url, ['type' => 'paid_out', 'amount' => '5', 'reason' => 'Supplies']);
        $this->expectException(LogicException::class);
        $session->movements()->firstOrFail()->update(['amount' => '1.00']);
    }

    public function test_drawer_management_and_daily_summary_need_review_permission(): void
    {
        $cashier = $this->cashier();
        $manager = $this->manager();
        $this->actingAs($cashier)->get('/cash/drawers')->assertForbidden();
        $this->actingAs($cashier)->get('/cash/daily')->assertForbidden();
        $this->actingAs($cashier)->post('/cash/drawers', ['name' => 'Rogue'])->assertForbidden();

        $this->actingAs($manager)->post('/cash/drawers', ['name' => 'Front desk'])->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('cash_drawers', ['name' => 'Front desk']);
        $this->actingAs($manager)->post('/cash/drawers', ['name' => 'Front desk'])->assertSessionHasErrors('name');
        $drawer = CashDrawer::where('name', 'Front desk')->firstOrFail();
        $this->actingAs($manager)->post("/cash/drawers/{$drawer->id}/toggle")->assertRedirect();
        $this->assertFalse($drawer->fresh()->is_active);
        $this->actingAs($manager)->get('/cash/drawers')->assertOk();
    }

    public function test_daily_summary_separates_sales_collections_refunds_and_receivables(): void
    {
        $cashier = $this->cashier();
        $manager = $this->manager();
        $this->openDrawer($cashier);
        $this->openDrawer($manager, '0.00');
        $service = $this->service(60, '100.00');
        $this->issueInvoice($cashier, [['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('100.00')]);
        $partly = $this->issueInvoice($cashier, [['service_id' => $service->id, 'quantity' => 1]], [$this->cardPayment('40.00')], ['customer_id' => $this->customer()->id]);
        $this->actingAs($manager)->post("/invoices/{$partly->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('card')->id,
            'reason' => 'x', 'quantities' => [$partly->items->first()->id => 1]]);

        $page = $this->actingAs($manager)->get('/cash/daily')->assertOk();
        $page->assertSee('2 invoices')->assertSee('$200.00')->assertSee('$140.00')->assertSee('$100.00');
        $viewer = User::factory()->withPermissions('finance.view')->create();
        $this->actingAs($viewer)->get('/cash/daily?date=2026-01-01')->assertOk();
    }
}
