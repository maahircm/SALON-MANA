<?php

namespace Tests\Feature;

use App\Models\CashDrawerSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use App\Services\CashDrawerService;
use App\Services\RefundService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns;
use Tests\TestCase;

class RefundTest extends TestCase
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

    private function sale(string $price = '30.00', int $qty = 3, array $payments = [], array $extra = []): Invoice
    {
        $service = $this->service(60, $price);

        return $this->issueInvoice($this->cashier, [['service_id' => $service->id, 'quantity' => $qty]], $payments ?: [$this->cashPayment(Money::times($price, $qty))], $extra);
    }

    /** @return array<string, mixed> */
    private function refundPayload(Invoice $invoice, array $quantities, string $method = 'cash', array $extra = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod($method)->id,
            'reason' => 'Customer unhappy', 'quantities' => $quantities,
        ], $extra);
    }

    public function test_refund_returns_money_for_selected_quantities_and_leaves_the_original_history_untouched(): void
    {
        $invoice = $this->sale('30.00', 3);
        $item = $invoice->items->first();
        $paymentsBefore = Payment::all()->map->getAttributes()->all();

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$item->id => 1]))->assertSessionDoesntHaveErrors()->assertRedirect();

        $refund = Refund::firstOrFail();
        $this->assertSame('RF-000001', $refund->refund_number);
        $this->assertSame('30.00', $refund->amount);
        $this->assertSame('30.00', (string) $invoice->fresh()->refunded_total);
        $this->assertSame('partial', $invoice->fresh()->refundStatus());
        $this->assertSame('60.00', $invoice->fresh()->refundableAmount());
        $this->assertSame('90.00', (string) $invoice->fresh()->total, 'the original total is never rewritten');
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame($paymentsBefore, Payment::all()->map->getAttributes()->all(), 'payments are not modified by refunds');
        $this->assertDatabaseHas('refund_items', ['refund_id' => $refund->id, 'invoice_item_id' => $item->id, 'quantity' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'refund.created', 'reason' => 'Customer unhappy', 'user_id' => $this->manager->id]);
    }

    public function test_refunds_include_the_lines_share_of_discount_and_tax(): void
    {
        $discounter = $this->cashier('pos.discount');
        $this->openDrawer($discounter);
        $service = $this->service(60, '50.00', ['tax_rate' => '10.00']);
        $invoice = $this->issueInvoice($discounter, [['service_id' => $service->id, 'quantity' => 2, 'discount_type' => 'percent', 'discount_value' => '10']], [$this->cashPayment('99.00')]);
        $this->assertSame('99.00', (string) $invoice->total);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$invoice->items->first()->id => 1]));

        $this->assertSame('49.50', (string) Refund::firstOrFail()->amount);
    }

    public function test_quantity_is_limited_to_what_was_sold_and_not_yet_refunded(): void
    {
        $invoice = $this->sale('30.00', 3);
        $item = $invoice->items->first();
        $url = "/invoices/{$invoice->id}/refunds";

        $this->actingAs($this->manager)->post($url, $this->refundPayload($invoice, [$item->id => 4]))->assertSessionHasErrors("items.{$item->id}");
        $this->actingAs($this->manager)->post($url, $this->refundPayload($invoice, [$item->id => 2]))->assertSessionDoesntHaveErrors();
        $this->actingAs($this->manager)->post($url, $this->refundPayload($invoice, [$item->id => 2]))->assertSessionHasErrors("items.{$item->id}");
        $this->actingAs($this->manager)->post($url, $this->refundPayload($invoice, [$item->id => 1]))->assertSessionDoesntHaveErrors();

        $this->assertSame('90.00', (string) $invoice->fresh()->refunded_total);
        $this->assertSame('full', $invoice->fresh()->refundStatus());
        $this->assertSame('0.00', $invoice->fresh()->refundableAmount());
        $this->actingAs($this->manager)->post($url, $this->refundPayload($invoice, [$item->id => 1]))->assertSessionHasErrors();
        $this->assertSame(2, Refund::count());
    }

    public function test_rounding_remainder_goes_to_the_last_unit_so_a_line_refunds_to_exactly_its_total(): void
    {
        $invoice = $this->sale('3.33', 3, [$this->cashPayment('9.99')]);
        $item = $invoice->items->first();
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$item->id => 1]));
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$item->id => 1]));
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$item->id => 1]));

        $this->assertSame('9.99', (string) $invoice->fresh()->refunded_total);
        $this->assertSame(['3.33', '3.33', '3.33'], Refund::orderBy('id')->pluck('amount')->map(fn ($a) => (string) $a)->all());
    }

    public function test_refund_cannot_exceed_the_money_actually_paid(): void
    {
        $invoice = $this->sale('30.00', 3, [$this->cashPayment('40.00')], ['customer_id' => $this->customer()->id]);
        $item = $invoice->items->first();
        $url = "/invoices/{$invoice->id}/refunds";

        $this->actingAs($this->manager)->post($url, $this->refundPayload($invoice, [$item->id => 1]))->assertSessionDoesntHaveErrors();
        $this->assertSame('10.00', $invoice->fresh()->refundableAmount());

        $this->actingAs($this->manager)->post($url, $this->refundPayload($invoice, [$item->id => 1]))->assertSessionHasErrors('items');
        $this->assertSame('30.00', (string) $invoice->fresh()->refunded_total);
        $this->assertSame(1, Refund::count());
    }

    public function test_an_unpaid_invoice_has_nothing_to_refund(): void
    {
        $invoice = $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '30.00')->id, 'quantity' => 1]], [], ['customer_id' => $this->customer()->id]);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$invoice->items->first()->id => 1]))->assertSessionHasErrors('items');
        $this->assertSame(0, Refund::count());
    }

    public function test_refunds_must_go_back_through_a_method_the_customer_paid_with(): void
    {
        $service = $this->service(60, '100.00');
        $invoice = $this->issueInvoice($this->cashier, [['service_id' => $service->id, 'quantity' => 1]], [$this->cardPayment('100.00')]);
        $itemId = $invoice->items->first()->id;

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1], 'cash'))->assertSessionHasErrors('payment_method_id');
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1], 'card'))->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Refund::count());
    }

    public function test_split_payment_refund_is_capped_per_method(): void
    {
        $service = $this->service(60, '40.00');
        $invoice = $this->issueInvoice($this->cashier, [['service_id' => $service->id, 'quantity' => 2]], [$this->cashPayment('30.00'), $this->cardPayment('50.00')]);
        $itemId = $invoice->items->first()->id;

        // First unit (40.00) cannot be refunded to cash: only 30.00 was paid in cash.
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1], 'cash'))->assertSessionHasErrors('payment_method_id');
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1], 'card'))->assertSessionDoesntHaveErrors();
        $this->assertSame('40.00', (string) $invoice->fresh()->refunded_total);
    }

    public function test_cash_refund_needs_the_refunding_users_open_drawer_and_reduces_expected_cash(): void
    {
        $invoice = $this->sale('30.00', 1);
        $itemId = $invoice->items->first()->id;
        $noDrawer = User::factory()->withPermissions('pos.refund', 'pos.invoices')->create();
        $this->actingAs($noDrawer)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1]))->assertSessionHasErrors('session');
        $this->assertSame(0, Refund::count());

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1]))->assertSessionDoesntHaveErrors();
        $recon = app(CashDrawerService::class)->reconciliation(CashDrawerSession::where('opened_by', $this->manager->id)->firstOrFail());
        $this->assertSame('30.00', $recon['cash_refunds']);
        $this->assertSame('-30.00', $recon['expected']);
    }

    public function test_refund_requires_permission_and_a_reason(): void
    {
        $invoice = $this->sale('30.00', 1);
        $itemId = $invoice->items->first()->id;

        $this->actingAs($this->cashier)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1]))->assertForbidden();
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$itemId => 1], 'cash', ['reason' => '']))->assertSessionHasErrors('reason');
        $this->assertSame(0, Refund::count());
        $this->expectException(HttpException::class);
        app(RefundService::class)->refund($invoice, [$itemId => 1], $this->paymentMethod('cash'), 'x', null, (string) Str::uuid(), $this->cashier);
    }

    public function test_the_same_refund_submitted_twice_is_processed_once(): void
    {
        $invoice = $this->sale('30.00', 3);
        $payload = $this->refundPayload($invoice, [$invoice->items->first()->id => 1]);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $payload);
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $payload)->assertSessionDoesntHaveErrors();

        $this->assertSame(1, Refund::count());
        $this->assertSame('30.00', (string) $invoice->fresh()->refunded_total);
    }

    public function test_lines_from_another_invoice_cannot_be_refunded(): void
    {
        $mine = $this->sale('30.00', 1);
        $other = $this->sale('30.00', 1);

        $this->actingAs($this->manager)->post("/invoices/{$mine->id}/refunds", $this->refundPayload($mine, [$other->items->first()->id => 1]))->assertSessionHasErrors('items');
        $this->assertSame(0, Refund::count());
    }

    public function test_voided_invoices_cannot_be_refunded(): void
    {
        $invoice = $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '30.00')->id, 'quantity' => 1]], [], ['customer_id' => $this->customer()->id]);
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Duplicate entry']);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$invoice->items->first()->id => 1]))->assertSessionHasErrors('invoice');
    }

    public function test_refund_and_payment_records_are_append_only(): void
    {
        $invoice = $this->sale('30.00', 1);
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", $this->refundPayload($invoice, [$invoice->items->first()->id => 1]));

        $this->assertThrowsLogic(fn () => Refund::firstOrFail()->update(['amount' => '1.00']));
        $this->assertThrowsLogic(fn () => Refund::firstOrFail()->delete());
        $this->assertThrowsLogic(fn () => RefundItem::firstOrFail()->update(['quantity' => 9]));
    }

    public function test_issued_invoices_and_their_lines_are_frozen(): void
    {
        $invoice = $this->sale('30.00', 1);

        $this->assertThrowsLogic(fn () => $invoice->forceFill(['total' => '1.00'])->save());
        $this->assertThrowsLogic(fn () => $invoice->forceFill(['customer_id' => $this->customer()->id])->save());
        $this->assertThrowsLogic(fn () => $invoice->delete());
        $this->assertThrowsLogic(fn () => InvoiceItem::firstOrFail()->update(['unit_price' => '1.00']));
        $this->assertThrowsLogic(fn () => InvoiceItem::firstOrFail()->delete());
        $this->assertThrowsLogic(fn () => $invoice->items()->create(['description' => 'x', 'quantity' => 1, 'unit_price' => '1.00', 'line_total' => '1.00']));
        $this->assertSame('30.00', (string) $invoice->fresh()->total);
    }

    private function assertThrowsLogic(callable $fn): void
    {
        try {
            $fn();
        } catch (LogicException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the financial record to be immutable.');
    }
}
