<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\AuditLog;
use App\Models\CashDrawerSession;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\Skill;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns;
use Tests\TestCase;

class ReceiptAndHistoryTest extends TestCase
{
    use Concerns, RefreshDatabase;

    public function test_first_print_is_the_original_and_reprints_are_marked_and_audited(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $invoice = $this->issueInvoice($cashier, [['service_id' => $this->service(60, '25.00')->id, 'quantity' => 1]], [$this->cashPayment('25.00', '30.00')]);

        $this->actingAs($cashier)->get("/invoices/{$invoice->id}/receipt")->assertOk()->assertSee($invoice->invoice_number)->assertSee('TOTAL')->assertDontSee('DUPLICATE');
        $this->assertSame(0, AuditLog::where('action', 'invoice.receipt_reprinted')->count());

        $this->actingAs($cashier)->get("/invoices/{$invoice->id}/receipt")->assertOk()->assertSee('DUPLICATE');
        $this->assertSame(2, $invoice->fresh()->print_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.receipt_reprinted', 'entity_id' => (string) $invoice->id]);
    }

    public function test_receipt_shows_items_tax_payments_change_balance_and_configured_text(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        app(SettingsService::class)->set('pos.receipt_footer', 'See you soon, <b>friend</b>');
        $service = $this->service(60, '20.00', ['tax_rate' => '5.00']);
        $invoice = $this->issueInvoice($cashier, [['service_id' => $service->id, 'quantity' => 2]], [$this->cashPayment('30.00', '50.00')], ['customer_id' => $this->customer(['name' => 'Ayaan Receipt'])->id]);

        $html = $this->actingAs($cashier)->get("/invoices/{$invoice->id}/receipt")->assertOk()->getContent();
        foreach (['Ayaan Receipt', '2 × $20.00', '$42.00', '$30.00', '$50.00 / $20.00', 'BALANCE DUE', '$12.00'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('&lt;b&gt;friend&lt;/b&gt;', $html, 'configured text is escaped');
    }

    public function test_receipts_require_invoice_permission_and_drafts_have_none(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $invoice = $this->issueInvoice($cashier, [['service_id' => $this->service(60, '25.00')->id, 'quantity' => 1]], [$this->cashPayment('25.00')]);
        $this->actingAs($this->receptionist())->get("/invoices/{$invoice->id}/receipt")->assertForbidden();

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([['service_id' => $this->service()->id, 'quantity' => 1]], [], ['action' => 'save_draft']));
        $draft = Invoice::whereNull('invoice_number')->firstOrFail();
        $this->actingAs($cashier)->get("/invoices/{$draft->id}/receipt")->assertNotFound();
    }

    public function test_pos_settings_are_permission_protected_validated_audited_and_applied(): void
    {
        $cashier = $this->cashier();
        $this->actingAs($cashier)->get('/settings/pos')->assertForbidden();
        $owner = User::factory()->withPermissions('settings.manage')->create();
        $this->actingAs($owner)->get('/settings/pos')->assertOk();

        $this->actingAs($owner)->put('/settings/pos', ['invoice_prefix' => 'bad prefix!', 'refund_prefix' => 'R-', 'number_padding' => 5, 'max_discount_percent' => 10])->assertSessionHasErrors('invoice_prefix');
        $this->actingAs($owner)->put('/settings/pos', ['invoice_prefix' => 'SAL-', 'refund_prefix' => 'REF-', 'number_padding' => 5, 'max_discount_percent' => 15, 'allow_credit_sales' => 1,
            'require_open_drawer' => 1, 'receipt_header' => 'Hodan Street', 'receipt_footer' => 'Thanks'])->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.pos_updated']);

        $this->openDrawer($cashier);
        $this->assertSame('SAL-00001', $this->issueInvoice($cashier, [['service_id' => $this->service(60, '10.00')->id, 'quantity' => 1]], [$this->cashPayment('10.00')])->invoice_number);
    }

    public function test_every_pos_and_finance_screen_renders_with_realistic_data(): void
    {
        $this->freezeSalonTime();
        $admin = User::factory()->superAdmin()->create();
        $this->openDrawer($admin, '50.00');
        $skill = Skill::factory()->create();
        $stylist = $this->stylist();
        $stylist->skills()->attach($skill);
        $service = $this->service(60, '40.00', ['tax_rate' => '5.00', 'required_skill_id' => $skill->id]);
        $customer = $this->customer();
        $appointment = app(AppointmentBookingService::class)->create(['customer_id' => $customer->id, 'start_at' => '2026-10-20 10:00',
            'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id, 'room_id' => Room::factory()->create()->id]]], $admin);
        $appointment->forceFill(['status' => AppointmentStatus::Completed])->save();

        $invoice = $this->issueInvoice($admin, [['appointment_service_id' => $appointment->lines->first()->id, 'service_id' => $service->id, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => '5']], [$this->cashPayment('20.00', '20.00')]);
        $this->actingAs($admin)->post("/invoices/{$invoice->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('cash')->id, 'reason' => 'Partial', 'quantities' => [$invoice->items->first()->id => 1]]);
        $this->actingAs($admin)->post('/pos', $this->checkoutPayload([['service_id' => $service->id, 'quantity' => 1]], [], ['action' => 'save_draft']));
        $draft = Invoice::whereNull('invoice_number')->firstOrFail();
        $session = CashDrawerSession::firstOrFail();

        $urls = ['/pos', "/pos?draft={$draft->id}", "/pos?appointment={$appointment->id}", "/pos?customer_id={$customer->id}", '/invoices', '/invoices?status=issued&payment_status=partially_paid&q=INV&from=2026-01-01',
            "/invoices/{$invoice->id}", "/invoices/{$invoice->id}/receipt", '/payments', '/payments?method=1&from=2026-01-01', '/cash', "/cash/sessions/{$session->id}", '/cash/drawers', '/cash/daily',
            '/settings/pos', "/customers/{$customer->id}", "/appointments/{$appointment->id}"];
        foreach ($urls as $url) {
            $response = $this->actingAs($admin)->get($url)->assertOk();
            $this->assertNoNestedForms($response->getContent(), $url);
        }
        $html = $this->actingAs($admin)->get('/dashboard')->getContent();
        preg_match_all('/class="nav-item[^"]*" href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            $this->actingAs($admin)->get($href)->assertOk();
        }
        $this->assertStringContainsString('Point of Sale', $html);
    }

    public function test_the_sidebar_hides_sales_screens_from_users_without_permission(): void
    {
        $html = $this->actingAs($this->receptionist())->get('/dashboard')->getContent();
        foreach (['Point of Sale', 'Cash Drawer', 'Daily Summary', 'POS Settings'] as $label) {
            $this->assertStringNotContainsString($label, $html);
        }
        $cashierHtml = $this->actingAs($this->cashier())->get('/dashboard')->getContent();
        $this->assertStringContainsString('Point of Sale', $cashierHtml);
        $this->assertStringNotContainsString('Daily Summary', $cashierHtml);
    }
}
