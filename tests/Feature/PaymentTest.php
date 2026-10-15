<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = $this->cashier();
        $this->openDrawer($this->cashier);
        $this->invoice = $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '100.00')->id, 'quantity' => 1]], [], ['customer_id' => $this->customer()->id]);
    }

    /** @return array<string, mixed> */
    private function pay(string $amount, string $method = 'cash', array $extra = []): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'payments' => [array_merge(['payment_method_id' => $this->paymentMethod($method)->id, 'amount' => $amount], $extra)]];
    }

    public function test_partial_payments_update_the_balance_and_only_the_last_one_marks_the_invoice_paid(): void
    {
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $this->pay('30.00'))->assertSessionDoesntHaveErrors();
        $this->assertSame('partially_paid', $this->invoice->fresh()->payment_status);
        $this->assertSame('70.00', $this->invoice->fresh()->balanceDue());

        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $this->pay('69.99', 'card'));
        $this->assertSame('partially_paid', $this->invoice->fresh()->payment_status);
        $this->assertSame('0.01', $this->invoice->fresh()->balanceDue());

        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $this->pay('0.01'));
        $this->assertSame('paid', $this->invoice->fresh()->payment_status);
        $this->assertSame('100.00', (string) $this->invoice->fresh()->amount_paid);
        $this->assertSame(3, Payment::count());
    }

    public function test_payment_exceeding_the_balance_is_rejected(): void
    {
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $this->pay('100.01'))->assertSessionHasErrors('payments');
        $this->assertSame(0, Payment::count());
        $this->assertSame('unpaid', $this->invoice->fresh()->payment_status);
    }

    public function test_duplicate_submission_with_the_same_key_records_one_payment(): void
    {
        $payload = $this->pay('40.00');
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $payload);
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $payload)->assertSessionDoesntHaveErrors();

        $this->assertSame(1, Payment::count());
        $this->assertSame('60.00', $this->invoice->fresh()->balanceDue());
    }

    public function test_a_payment_key_cannot_be_reused_on_a_different_invoice(): void
    {
        $other = $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '50.00')->id, 'quantity' => 1]], [], ['customer_id' => $this->customer()->id]);
        $payload = $this->pay('10.00');
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $payload);

        $this->actingAs($this->cashier)->post("/invoices/{$other->id}/payments", $payload)->assertSessionHasErrors('idempotency_key');
        $this->assertSame('0.00', (string) $other->fresh()->amount_paid);
    }

    public function test_invalid_amounts_methods_and_cash_tendering_are_rejected(): void
    {
        $url = "/invoices/{$this->invoice->id}/payments";
        $this->actingAs($this->cashier)->post($url, $this->pay('0'))->assertSessionHasErrors('payments.0.amount');
        $this->actingAs($this->cashier)->post($url, $this->pay('-5'))->assertSessionHasErrors('payments.0.amount');
        $this->actingAs($this->cashier)->post($url, $this->pay('10.555'))->assertSessionHasErrors('payments.0.amount');
        $this->actingAs($this->cashier)->post($url, $this->pay('abc'))->assertSessionHasErrors('payments.0.amount');
        $this->actingAs($this->cashier)->post($url, $this->pay('50.00', 'cash', ['tendered' => '40.00']))->assertSessionHasErrors('payments.0.tendered');

        PaymentMethod::where('code', 'card')->update(['is_active' => false]);
        $this->actingAs($this->cashier)->post($url, $this->pay('10.00', 'card'))->assertSessionHasErrors('payments.0.payment_method_id');
        $this->actingAs($this->cashier)->post($url, $this->pay('10.00', 'cash', ['payment_method_id' => 9999]))->assertSessionHasErrors('payments.0.payment_method_id');
        $this->assertSame(0, Payment::count());
    }

    public function test_only_issued_invoices_accept_payments(): void
    {
        $voidable = $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '10.00')->id, 'quantity' => 1]], [], ['customer_id' => $this->customer()->id]);
        $manager = $this->manager();
        $this->openDrawer($manager);
        $this->actingAs($manager)->post("/invoices/{$voidable->id}/void", ['reason' => 'Entered by mistake']);

        $this->actingAs($this->cashier)->post("/invoices/{$voidable->id}/payments", $this->pay('10.00'))->assertSessionHasErrors('invoice');
        $this->assertSame(0, Payment::count());
    }

    public function test_payments_need_permission_and_an_open_drawer_session(): void
    {
        $viewer = User::factory()->withPermissions('pos.invoices')->create();
        $this->actingAs($viewer)->post("/invoices/{$this->invoice->id}/payments", $this->pay('10.00'))->assertForbidden();

        $noDrawer = $this->cashier();
        $this->actingAs($noDrawer)->post("/invoices/{$this->invoice->id}/payments", $this->pay('10.00'))->assertSessionHasErrors('session');
        $this->assertSame(0, Payment::count());
    }

    public function test_payment_history_and_invoice_filters(): void
    {
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $this->pay('25.00', 'card'));
        $this->actingAs($this->cashier)->get('/payments')->assertOk()->assertSee($this->invoice->invoice_number)->assertSee('$25.00');
        $this->actingAs($this->cashier)->get('/payments?method='.$this->paymentMethod('cash')->id)->assertOk()->assertDontSee('$25.00');
        $this->actingAs($this->cashier)->get('/invoices?payment_status=partially_paid')->assertSee($this->invoice->invoice_number);
        $this->actingAs($this->cashier)->get('/invoices?payment_status=paid')->assertDontSee($this->invoice->invoice_number);
        $this->actingAs(User::factory()->create())->get('/payments')->assertForbidden();
    }

    public function test_payments_are_append_only(): void
    {
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $this->pay('25.00'));
        $payment = Payment::firstOrFail();

        $this->expectException(LogicException::class);
        $payment->update(['amount' => '1.00']);
    }

    public function test_payments_cannot_be_deleted(): void
    {
        $this->actingAs($this->cashier)->post("/invoices/{$this->invoice->id}/payments", $this->pay('25.00'));

        $this->expectException(LogicException::class);
        Payment::firstOrFail()->delete();
    }
}
