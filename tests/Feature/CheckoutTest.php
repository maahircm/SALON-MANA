<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Events\InvoiceIssued;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentStatusService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private function line(int $serviceId, int $qty = 1, array $extra = []): array
    {
        return array_merge(['service_id' => $serviceId, 'quantity' => $qty], $extra);
    }

    public function test_cashier_completes_a_cash_sale_with_change_and_gets_a_sequential_invoice_number(): void
    {
        $cashier = $this->cashier();
        $session = $this->openDrawer($cashier);
        $service = $this->service(60, '25.00');

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($service->id)], [$this->cashPayment('25.00', '30.00')]))->assertRedirect();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('INV-000001', $invoice->invoice_number);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame('25.00', $invoice->total);
        $this->assertSame('0.00', $invoice->balanceDue());
        $payment = Payment::firstOrFail();
        $this->assertSame($session->id, $payment->cash_drawer_session_id);
        $this->assertSame('30.00', $payment->tendered_amount);
        $this->assertSame('5.00', $payment->change_given);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.issued', 'user_id' => $cashier->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.recorded']);
        $this->actingAs($cashier)->get("/invoices/{$invoice->id}")->assertOk()->assertSee('INV-000001');
    }

    public function test_tax_and_discount_are_calculated_exactly_per_line_with_half_up_rounding(): void
    {
        $cashier = $this->cashier('pos.discount');
        $this->openDrawer($cashier);
        $taxed = $this->service(30, '12.35', ['tax_rate' => '10.00']);      // 1.235 tax -> 1.24
        $bulk = $this->service(30, '33.33', ['tax_rate' => '8.00']);         // 3 x 33.33 = 99.99, tax 7.9992 -> 8.00

        $invoice = $this->issueInvoice($cashier, [$this->line($taxed->id), $this->line($bulk->id, 3)], [$this->cashPayment('121.58')]);

        $this->assertSame('112.34', $invoice->subtotal);
        $this->assertSame('9.24', $invoice->tax_total);
        $this->assertSame('121.58', $invoice->total);
        $this->assertSame('13.59', (string) $invoice->items[0]->line_total);
        $this->assertSame('107.99', (string) $invoice->items[1]->line_total);
        $this->assertSame('paid', $invoice->payment_status);
    }

    public function test_percentage_and_fixed_discounts_reduce_the_taxable_amount(): void
    {
        $cashier = $this->cashier('pos.discount');
        $this->openDrawer($cashier);
        $service = $this->service(60, '50.00', ['tax_rate' => '10.00']);

        $invoice = $this->issueInvoice($cashier, [$this->line($service->id, 1, ['discount_type' => 'percent', 'discount_value' => '10'])], [$this->cashPayment('49.50')]);
        $this->assertSame('5.00', $invoice->discount_total);
        $this->assertSame('4.50', $invoice->tax_total);
        $this->assertSame('49.50', $invoice->total);

        $fixed = $this->issueInvoice($cashier, [$this->line($service->id, 1, ['discount_type' => 'fixed', 'discount_value' => '5.00'])], [$this->cashPayment('49.50')]);
        $this->assertSame('5.00', $fixed->discount_total);
    }

    public function test_prices_come_from_the_catalogue_and_never_from_the_browser(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $service = $this->service(60, '40.00');
        $service->forceFill(['promo_price' => '30.00', 'promo_starts_on' => '2026-01-01', 'promo_ends_on' => '2099-01-01'])->save();

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($service->id, 1, ['unit_price' => '0.01', 'line_total' => '0.01', 'tax_rate' => '0'])], [$this->cashPayment('30.00')]))->assertRedirect();

        $this->assertSame('30.00', (string) Invoice::firstOrFail()->total);
    }

    public function test_discounts_need_permission_and_respect_the_limit_unless_approved(): void
    {
        $service = $this->service(60, '100.00');
        $plain = $this->cashier();
        $this->openDrawer($plain);
        $discounted = [$this->line($service->id, 1, ['discount_type' => 'percent', 'discount_value' => '5'])];
        $this->actingAs($plain)->post('/pos', $this->checkoutPayload($discounted, [$this->cashPayment('95.00')]))->assertSessionHasErrors('items.0.discount_value');

        $limited = $this->cashier('pos.discount');
        $this->openDrawer($limited);
        $this->actingAs($limited)->post('/pos', $this->checkoutPayload($discounted, [$this->cashPayment('95.00')]))->assertSessionDoesntHaveErrors();
        $tooMuch = [$this->line($service->id, 1, ['discount_type' => 'percent', 'discount_value' => '25'])];
        $this->actingAs($limited)->post('/pos', $this->checkoutPayload($tooMuch, [$this->cashPayment('75.00')]))->assertSessionHasErrors('items.0.discount_value');
        $this->actingAs($limited)->post('/pos', $this->checkoutPayload([$this->line($service->id, 1, ['discount_type' => 'fixed', 'discount_value' => '11'])], [$this->cashPayment('89.00')]))->assertSessionHasErrors('items.0.discount_value');
        $this->assertSame(1, Invoice::count());

        $boss = $this->cashier('pos.discount', 'pos.discount_unlimited');
        $this->openDrawer($boss);
        $this->actingAs($boss)->post('/pos', $this->checkoutPayload($tooMuch, [$this->cashPayment('75.00')]))->assertSessionDoesntHaveErrors();
        $this->assertTrue(AuditLog::where('action', 'invoice.issued')->latest('id')->first()->after['discount_override']);
    }

    public function test_discount_limit_is_a_setting_and_discount_cannot_exceed_the_line(): void
    {
        $service = $this->service(60, '100.00');
        $user = $this->cashier('pos.discount');
        $this->openDrawer($user);
        app(SettingsService::class)->set('pos.max_discount_percent', '30');
        $this->issueInvoice($user, [$this->line($service->id, 1, ['discount_type' => 'percent', 'discount_value' => '25'])], [$this->cashPayment('75.00')]);
        $this->assertSame(1, Invoice::count());

        $boss = $this->cashier('pos.discount', 'pos.discount_unlimited');
        $this->actingAs($boss)->post('/pos', $this->checkoutPayload([$this->line($service->id, 1, ['discount_type' => 'fixed', 'discount_value' => '150'])]))->assertSessionHasErrors('items.0.discount_value');
        $this->actingAs($boss)->post('/pos', $this->checkoutPayload([$this->line($service->id, 1, ['discount_type' => 'percent', 'discount_value' => '101'])]))->assertSessionHasErrors();
    }

    public function test_split_payment_across_methods_marks_invoice_paid(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $service = $this->service(60, '80.00');

        $invoice = $this->issueInvoice($cashier, [$this->line($service->id)], [$this->cashPayment('30.00'), $this->cardPayment('50.00')]);

        $this->assertSame(2, $invoice->payments()->count());
        $this->assertSame('80.00', $invoice->amount_paid);
        $this->assertSame('paid', $invoice->payment_status);
    }

    public function test_partial_payment_with_a_customer_leaves_an_outstanding_balance(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $customer = $this->customer();
        $service = $this->service(60, '100.00');

        $invoice = $this->issueInvoice($cashier, [$this->line($service->id)], [$this->cashPayment('40.00')], ['customer_id' => $customer->id]);

        $this->assertSame('partially_paid', $invoice->payment_status);
        $this->assertSame('60.00', $invoice->balanceDue());
        $this->assertNotSame('paid', $invoice->payment_status);
    }

    public function test_unpaid_credit_sale_needs_no_drawer_session(): void
    {
        $cashier = $this->cashier();
        $customer = $this->customer();
        $invoice = $this->issueInvoice($cashier, [$this->line($this->service(60, '45.00')->id)], [], ['customer_id' => $customer->id]);

        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame('45.00', $invoice->balanceDue());
        $this->assertSame(0, Payment::count());
    }

    public function test_walk_in_cannot_leave_a_balance_and_a_failed_checkout_persists_nothing_nor_burns_a_number(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $service = $this->service(60, '100.00');

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($service->id)], [$this->cashPayment('40.00')]))->assertSessionHasErrors('customer_id');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseCount('invoice_items', 0);

        $ok = $this->issueInvoice($cashier, [$this->line($service->id)], [$this->cashPayment('100.00')]);
        $this->assertSame('INV-000001', $ok->invoice_number);
    }

    public function test_payment_failure_rolls_back_the_whole_sale(): void
    {
        $cashier = $this->cashier();
        $service = $this->service(60, '100.00');

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($service->id)], [$this->cashPayment('100.00')]))->assertSessionHasErrors('session');
        $this->assertSame(0, Invoice::count());
        $this->assertDatabaseCount('document_sequences', 0);
    }

    public function test_overpayment_is_rejected(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($this->service(60, '20.00')->id)], [$this->cardPayment('25.00')]))->assertSessionHasErrors('payments');
        $this->assertSame(0, Invoice::count());
    }

    public function test_credit_sales_can_be_disabled(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        app(SettingsService::class)->set('pos.allow_credit_sales', false);

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($this->service(60, '50.00')->id)], [$this->cashPayment('10.00')], ['customer_id' => $this->customer()->id]))
            ->assertSessionHasErrors('payments');
        $this->assertSame(0, Invoice::count());
    }

    public function test_repeating_the_same_submission_does_not_charge_twice(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $payload = $this->checkoutPayload([$this->line($this->service(60, '60.00')->id)], [$this->cashPayment('60.00')]);

        $this->actingAs($cashier)->post('/pos', $payload)->assertRedirect();
        $this->actingAs($cashier)->post('/pos', $payload)->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame('60.00', (string) Invoice::first()->amount_paid);
    }

    public function test_a_checkout_key_cannot_be_replayed_by_another_user(): void
    {
        $first = $this->cashier();
        $this->openDrawer($first);
        $payload = $this->checkoutPayload([$this->line($this->service(60, '60.00')->id)], [$this->cashPayment('60.00')]);
        $this->actingAs($first)->post('/pos', $payload);

        $second = $this->cashier();
        $this->actingAs($second)->post('/pos', $payload)->assertSessionHasErrors('idempotency_key');
    }

    public function test_invoice_numbers_are_sequential_and_follow_the_configured_prefix(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $service = $this->service(60, '10.00');
        $numbers = [];
        foreach (range(1, 3) as $ignored) {
            $numbers[] = $this->issueInvoice($cashier, [$this->line($service->id)], [$this->cashPayment('10.00')])->invoice_number;
        }
        $this->assertSame(['INV-000001', 'INV-000002', 'INV-000003'], $numbers);

        app(SettingsService::class)->set('pos.invoice_prefix', 'SAL/');
        app(SettingsService::class)->set('pos.number_padding', 4);
        $this->assertSame('SAL/0004', $this->issueInvoice($cashier, [$this->line($service->id)], [$this->cashPayment('10.00')])->invoice_number);
    }

    public function test_invalid_carts_are_rejected(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $inactive = $this->service(60, '10.00', ['is_active' => false]);
        $active = $this->service(60, '10.00');

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([]))->assertSessionHasErrors('items');
        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($inactive->id)]))->assertSessionHasErrors('items.0.service_id');
        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($active->id, 100)]))->assertSessionHasErrors('items.0.quantity');
        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($active->id)], [], ['idempotency_key' => 'not-a-uuid']))->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, Invoice::count());
    }

    public function test_blocked_customers_cannot_be_billed(): void
    {
        $cashier = $this->cashier();
        $blocked = $this->customer(['status' => 'blocked']);
        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($this->service(60, '10.00')->id)], [], ['customer_id' => $blocked->id]))->assertSessionHasErrors('customer_id');
    }

    public function test_user_without_pos_permission_cannot_use_the_pos(): void
    {
        $receptionist = $this->receptionist();
        $service = $this->service();
        $this->actingAs($receptionist)->get('/pos')->assertForbidden();
        $this->actingAs($receptionist)->post('/pos', $this->checkoutPayload([$this->line($service->id)]))->assertForbidden();
        $this->assertSame(0, Invoice::count());
    }

    public function test_pos_screen_renders_with_catalogue_methods_and_drawer_warning(): void
    {
        $cashier = $this->cashier();
        $service = $this->service(60, '25.00');
        $this->actingAs($cashier)->get('/pos')->assertOk()->assertSee($service->name)->assertSee('no open cash drawer session');
        $this->openDrawer($cashier);
        $this->actingAs($cashier)->get('/pos')->assertOk()->assertDontSee('no open cash drawer session');
    }

    public function test_issuing_an_invoice_raises_an_event_for_later_modules(): void
    {
        Event::fake([InvoiceIssued::class]);
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $this->issueInvoice($cashier, [$this->line($this->service(60, '10.00')->id)], [$this->cashPayment('10.00')]);

        Event::assertDispatched(InvoiceIssued::class);
    }

    public function test_drafts_can_be_saved_resumed_issued_and_discarded_by_their_owner(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $service = $this->service(60, '30.00');

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($service->id)], [], ['action' => 'save_draft']))->assertRedirect();
        $draft = Invoice::firstOrFail();
        $this->assertSame(Invoice::STATUS_DRAFT, $draft->status);
        $this->assertNull($draft->invoice_number);
        $this->actingAs($cashier)->get("/pos?draft={$draft->id}")->assertOk();

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($service->id, 2)], [$this->cashPayment('60.00')], ['draft_id' => $draft->id]))->assertRedirect();
        $issued = $draft->fresh();
        $this->assertSame($draft->id, $issued->id);
        $this->assertSame('INV-000001', $issued->invoice_number);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, $issued->items()->count());

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload([$this->line($service->id)], [], ['action' => 'save_draft']));
        $second = Invoice::whereNull('invoice_number')->firstOrFail();
        $this->actingAs($cashier)->delete("/pos/drafts/{$second->id}")->assertRedirect('/pos');
        $this->assertDatabaseMissing('invoices', ['id' => $second->id]);
    }

    public function test_drafts_belong_to_their_owner_and_issued_invoices_cannot_be_discarded(): void
    {
        $owner = $this->cashier();
        $other = $this->cashier();
        $service = $this->service(60, '30.00');
        $this->actingAs($owner)->post('/pos', $this->checkoutPayload([$this->line($service->id)], [], ['action' => 'save_draft']));
        $draft = Invoice::firstOrFail();

        $this->actingAs($other)->get("/pos?draft={$draft->id}")->assertForbidden();
        $this->actingAs($other)->delete("/pos/drafts/{$draft->id}")->assertForbidden();
        $this->actingAs($other)->post('/pos', $this->checkoutPayload([$this->line($service->id)], [], ['draft_id' => $draft->id]))->assertForbidden();
        $this->assertDatabaseHas('invoices', ['id' => $draft->id]);

        $issued = $this->issueInvoice($owner, [$this->line($service->id)], [], ['customer_id' => $this->customer()->id]);
        $this->actingAs($owner)->delete("/pos/drafts/{$issued->id}")->assertSessionHasErrors('invoice');
        $this->assertDatabaseHas('invoices', ['id' => $issued->id]);
    }

    public function test_checkout_from_a_booked_appointment_uses_the_booked_price_and_customer_and_cannot_be_billed_twice(): void
    {
        $this->freezeSalonTime();
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $admin = User::factory()->superAdmin()->create();
        $stylist = $this->stylist();
        $service = $this->service(60, '40.00');
        $customer = $this->customer();
        $appointment = app(AppointmentBookingService::class)->create(['customer_id' => $customer->id, 'start_at' => '2026-10-20 10:00', 'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id]]], $admin);
        $line = $appointment->lines->first();
        $service->forceFill(['base_price' => '99.00'])->save();

        $this->actingAs($cashier)->get("/pos?appointment={$appointment->id}")->assertForbidden();
        $reader = $this->cashier('appointments.view', 'appointments.all');
        $this->actingAs($reader)->get("/pos?appointment={$appointment->id}")->assertOk()->assertSee($customer->name);

        $items = [['appointment_service_id' => $line->id, 'service_id' => $service->id, 'quantity' => 1]];
        $invoice = $this->issueInvoice($cashier, $items, [$this->cashPayment('40.00')]);
        $this->assertSame('40.00', $invoice->total);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame($appointment->id, $invoice->appointment_id);
        $this->assertSame($stylist->id, $invoice->items->first()->employee_id);

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload($items, [$this->cashPayment('40.00')]))->assertSessionHasErrors('items.0.appointment_service_id');
        $this->assertSame(1, Invoice::count());
    }

    public function test_appointment_checkout_rejects_another_customer_and_cancelled_appointments(): void
    {
        $this->freezeSalonTime();
        $cashier = $this->cashier();
        $admin = User::factory()->superAdmin()->create();
        $stylist = $this->stylist();
        $service = $this->service(60, '40.00');
        $booking = app(AppointmentBookingService::class);
        $appointment = $booking->create(['customer_id' => $this->customer()->id, 'start_at' => '2026-10-20 10:00', 'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id]]], $admin);
        $items = [['appointment_service_id' => $appointment->lines->first()->id, 'service_id' => $service->id, 'quantity' => 1]];

        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload($items, [], ['customer_id' => Customer::factory()->create()->id]))->assertSessionHasErrors('customer_id');

        app(AppointmentStatusService::class)->transition($appointment, AppointmentStatus::Cancelled, $admin, 'Customer cancelled');
        $this->actingAs($cashier)->post('/pos', $this->checkoutPayload($items, []))->assertSessionHasErrors('items.0.appointment_service_id');
    }
}
