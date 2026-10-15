<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = $this->cashier();
        $this->manager = $this->manager();
        $this->openDrawer($this->cashier);
        $this->openDrawer($this->manager, '0.00');
    }

    private function creditInvoice(string $price = '50.00'): Invoice
    {
        return $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, $price)->id, 'quantity' => 1]], [], ['customer_id' => $this->customer()->id]);
    }

    public function test_an_invoice_without_payments_can_be_voided_with_a_reason_and_stays_on_record(): void
    {
        $invoice = $this->creditInvoice();

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", [])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Wrong customer'])->assertRedirect();

        $invoice->refresh();
        $this->assertTrue($invoice->isVoided());
        $this->assertSame('Wrong customer', $invoice->void_reason);
        $this->assertSame($this->manager->id, $invoice->voided_by);
        $this->assertSame('0.00', $invoice->balanceDue());
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.voided', 'reason' => 'Wrong customer']);
        $this->actingAs($this->manager)->get("/invoices/{$invoice->id}")->assertOk()->assertSee('VOIDED');
    }

    public function test_an_invoice_holding_customer_money_cannot_be_voided_until_it_is_fully_refunded(): void
    {
        $service = $this->service(60, '40.00');
        $invoice = $this->issueInvoice($this->cashier, [['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('40.00')]);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Mistake'])->assertSessionHasErrors('invoice');
        $this->assertTrue($invoice->fresh()->isIssued());

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('cash')->id,
            'reason' => 'Mistake', 'quantities' => [$invoice->items->first()->id => 1]]);
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Mistake'])->assertSessionDoesntHaveErrors();
        $this->assertTrue($invoice->fresh()->isVoided());
        $this->assertSame(1, $invoice->payments()->count(), 'payment history is preserved');
    }

    public function test_voiding_requires_the_void_permission_and_cannot_be_repeated(): void
    {
        $invoice = $this->creditInvoice();
        $this->actingAs($this->cashier)->post("/invoices/{$invoice->id}/void", ['reason' => 'x'])->assertForbidden();
        $this->assertTrue($invoice->fresh()->isIssued());

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'x']);
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'again'])->assertSessionHasErrors('invoice');
    }

    public function test_a_voided_invoice_is_excluded_from_balances_and_frees_its_appointment_lines(): void
    {
        $this->freezeSalonTime();
        $admin = User::factory()->superAdmin()->create();
        $service = $this->service(60, '40.00');
        $appointment = app(AppointmentBookingService::class)->create(['customer_id' => $this->customer()->id, 'start_at' => '2026-10-20 10:00',
            'lines' => [['service_id' => $service->id, 'employee_id' => $this->stylist()->id]]], $admin);
        $items = [['appointment_service_id' => $appointment->lines->first()->id, 'service_id' => $service->id, 'quantity' => 1]];

        $invoice = $this->issueInvoice($this->cashier, $items);
        $this->assertSame('40.00', $invoice->balanceDue());
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Billed in error']);

        $this->assertSame('0.00', $invoice->fresh()->balanceDue());
        $this->assertSame('INV-000002', $this->issueInvoice($this->cashier, $items)->invoice_number);
        $viewer = User::factory()->withPermissions('customers.view', 'pos.invoices')->create();
        $this->actingAs($viewer)->get("/customers/{$invoice->customer_id}")->assertOk()->assertSee('Outstanding balance');
    }

    public function test_only_invoice_viewers_can_open_invoices_and_drafts_are_not_invoices(): void
    {
        $invoice = $this->creditInvoice();
        $this->actingAs($this->receptionist())->get("/invoices/{$invoice->id}")->assertForbidden();
        $this->actingAs($this->receptionist())->get('/invoices')->assertForbidden();

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['service_id' => $this->service()->id, 'quantity' => 1]], [], ['action' => 'save_draft']));
        $draft = Invoice::whereNull('invoice_number')->firstOrFail();
        $this->actingAs($this->cashier)->get("/invoices/{$draft->id}")->assertNotFound();
        $this->actingAs($this->cashier)->get('/invoices')->assertDontSee("Draft #{$draft->id}");
    }

    public function test_customer_profile_shows_outstanding_balance_and_lifetime_value_to_authorised_users_only(): void
    {
        $customer = $this->customer();
        $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '100.00')->id, 'quantity' => 1]], [$this->cashPayment('30.00')], ['customer_id' => $customer->id]);

        $viewer = User::factory()->withPermissions('customers.view', 'pos.invoices')->create();
        $this->actingAs($viewer)->get("/customers/{$customer->id}")->assertOk()->assertSee('Outstanding balance')->assertSee('$70.00');
        $this->actingAs($this->receptionist())->get("/customers/{$customer->id}")->assertOk()->assertDontSee('Outstanding balance');
    }
}
