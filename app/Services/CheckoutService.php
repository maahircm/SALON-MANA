<?php

namespace App\Services;

use App\Events\InvoiceIssued;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Atomic;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates drafts and turns carts into issued invoices (with payments) in a single transaction. */
class CheckoutService
{
    public function __construct(
        private InvoicePricingService $pricing,
        private PaymentService $payments,
        private DocumentNumberService $numbers,
        private SettingsService $settings,
        private AuditLogger $audit,
        private InventoryService $inventory,
    ) {}

    /**
     * @param  array<string, mixed>  $data  customer_id, appointment_id, notes, items[], draft_id
     */
    public function saveDraft(array $data, User $actor): Invoice
    {
        return Atomic::run(function () use ($data, $actor) {
            $invoice = $this->resolveDraft($data['draft_id'] ?? null, $actor);
            $priced = $this->pricing->price($data['items'] ?? [], $actor, $invoice?->id);
            $appointment = $this->appointmentFor($priced['lines']);
            $customer = $this->customer($appointment?->customer_id ?? ($data['customer_id'] ?? null), $data['customer_id'] ?? null);

            $invoice ??= new Invoice(['status' => Invoice::STATUS_DRAFT, 'currency' => config('salon.currency'), 'created_by' => $actor->id]);
            $this->fill($invoice, $priced, $customer, $appointment, $data, $actor)->save();
            $this->replaceItems($invoice, $priced['lines']);
            $this->audit->log('invoice.draft_saved', $invoice, null, ['total' => $invoice->total, 'lines' => count($priced['lines'])]);

            return $invoice;
        });
    }

    /**
     * Issue the sale. Returns the invoice and whether this was a replay of an earlier identical submit.
     *
     * @param  array<string, mixed>  $data  idempotency_key, customer_id, appointment_id, notes, items[], payments[], draft_id
     * @return array{invoice: Invoice, replay: bool}
     */
    public function checkout(array $data, User $actor): array
    {
        $key = (string) $data['idempotency_key'];
        if ($replayed = Invoice::where('checkout_key', $key)->first()) {
            if ($replayed->created_by !== $actor->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'This checkout reference belongs to another user.']);
            }

            return ['invoice' => $replayed, 'replay' => true];
        }

        try {
            return ['invoice' => $this->issue($data, $actor, $key), 'replay' => false];
        } catch (UniqueConstraintViolationException) {
            // A concurrent double-submit with the same key won the race: return its result.
            $winner = Invoice::where('checkout_key', $key)->first();
            if ($winner && $winner->created_by === $actor->id) {
                return ['invoice' => $winner, 'replay' => true];
            }

            throw ValidationException::withMessages(['idempotency_key' => 'This checkout reference was already used.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function issue(array $data, User $actor, string $key): Invoice
    {
        return Atomic::run(function () use ($data, $actor, $key) {
            $invoice = $this->resolveDraft($data['draft_id'] ?? null, $actor);
            $priced = $this->pricing->price($data['items'] ?? [], $actor, $invoice?->id);
            $appointment = $this->appointmentFor($priced['lines']);
            $customer = $this->customer($appointment?->customer_id ?? ($data['customer_id'] ?? null), $data['customer_id'] ?? null);
            $this->inventory->lockForSale($priced['lines']);
            $payments = array_values(array_filter($data['payments'] ?? [], fn ($p) => filled($p['amount'] ?? null) && (float) $p['amount'] > 0));

            $paying = $payments === [] ? '0.00' : Money::add(...array_map(fn ($p) => $this->amountOrFail($p['amount']), $payments));
            if (Money::compare($paying, $priced['total']) > 0) {
                throw ValidationException::withMessages(['payments' => "The payments ({$paying}) exceed the invoice total ({$priced['total']})."]);
            }
            if (Money::compare($paying, $priced['total']) < 0) {
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Choose a customer to leave a balance on account, or take the full payment.']);
                }
                if (! $this->settings->get('pos.allow_credit_sales', true)) {
                    throw ValidationException::withMessages(['payments' => 'Credit sales are disabled: the full amount must be paid.']);
                }
            }
            if (Money::compare($priced['discount_total'], '0') > 0 && ! $actor->can('pos.discount')) {
                throw ValidationException::withMessages(['items' => 'You are not allowed to apply discounts.']);
            }

            $invoice ??= new Invoice(['status' => Invoice::STATUS_DRAFT, 'currency' => config('salon.currency'), 'created_by' => $actor->id]);
            $this->fill($invoice, $priced, $customer, $appointment, $data, $actor)->save();
            $this->replaceItems($invoice, $priced['lines']);

            $invoice->forceFill([
                'invoice_number' => $this->numbers->next('invoice'),
                'status' => Invoice::STATUS_ISSUED,
                'payment_status' => Money::compare($priced['total'], '0') === 0 ? 'paid' : 'unpaid',
                'checkout_key' => $key,
                'issued_at' => now(),
                'issued_by' => $actor->id,
            ])->save();

            // Retail goods and service consumables leave stock in the same transaction as the sale.
            $this->inventory->deductForInvoice($invoice, $actor);

            if ($payments !== []) {
                $this->payments->record($invoice, $payments, $actor, $key);
                $invoice->refresh();
            }

            $this->audit->log('invoice.issued', $invoice, null, [
                'invoice_number' => $invoice->invoice_number, 'total' => $invoice->total, 'discount_total' => $invoice->discount_total,
                'discount_override' => $priced['discount_override'], 'paid' => $invoice->amount_paid, 'customer_id' => $invoice->customer_id,
            ]);
            DB::afterCommit(fn () => InvoiceIssued::dispatch($invoice));

            return $invoice;
        });
    }

    /** Owners edit their own drafts; pos.void holders may manage anyone's. */
    private function resolveDraft(int|string|null $draftId, User $actor): ?Invoice
    {
        if (! $draftId) {
            return null;
        }
        $draft = Invoice::whereKey($draftId)->lockForUpdate()->first();
        if (! $draft || ! $draft->isDraft()) {
            throw ValidationException::withMessages(['draft_id' => 'That draft no longer exists or has already been issued.']);
        }
        if ($draft->created_by !== $actor->id && ! $actor->can('pos.void')) {
            abort(403, 'This draft belongs to another user.');
        }

        return $draft;
    }

    /**
     * Booked lines tie the invoice to one appointment (and so to its customer).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function appointmentFor(array $lines): ?Appointment
    {
        $ids = array_filter(array_column($lines, 'appointment_service_id'));
        if ($ids === []) {
            return null;
        }
        $appointmentIds = AppointmentService::whereIn('id', $ids)->pluck('appointment_id')->unique();
        if ($appointmentIds->count() > 1) {
            throw ValidationException::withMessages(['items' => 'Invoice one appointment at a time.']);
        }

        return Appointment::find($appointmentIds->first());
    }

    private function customer(int|string|null $customerId, int|string|null $requestedId = null): ?Customer
    {
        if ($customerId && $requestedId && (int) $customerId !== (int) $requestedId) {
            throw ValidationException::withMessages(['customer_id' => 'The chosen customer does not match the appointment being invoiced.']);
        }
        if (! $customerId) {
            return null;
        }
        $customer = Customer::find($customerId);
        if (! $customer || $customer->anonymized_at || $customer->status === 'blocked') {
            throw ValidationException::withMessages(['customer_id' => 'This customer cannot be billed.']);
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $priced
     * @param  array<string, mixed>  $data
     */
    private function fill(Invoice $invoice, array $priced, ?Customer $customer, ?Appointment $appointment, array $data, User $actor): Invoice
    {
        return $invoice->forceFill([
            'customer_id' => $customer?->id,
            'appointment_id' => $appointment?->id,
            'notes' => $data['notes'] ?? null,
            'subtotal' => $priced['subtotal'],
            'discount_total' => $priced['discount_total'],
            'tax_total' => $priced['tax_total'],
            'total' => $priced['total'],
            'amount_paid' => $invoice->amount_paid ?? '0.00',
            'refunded_total' => $invoice->refunded_total ?? '0.00',
            'updated_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceItems(Invoice $invoice, array $lines): void
    {
        $invoice->items()->delete();
        foreach ($lines as $line) {
            unset($line['gross']);
            $invoice->items()->create($line);
        }
    }

    private function amountOrFail(mixed $amount): string
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', (string) $amount)) {
            throw ValidationException::withMessages(['payments' => 'Enter valid payment amounts (maximum two decimals).']);
        }

        return Money::fromMinor(Money::toMinor($amount));
    }
}
