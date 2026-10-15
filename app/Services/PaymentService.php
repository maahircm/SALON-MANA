<?php

namespace App\Services;

use App\Events\PaymentRecorded;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Atomic;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Records payments against issued invoices and keeps the invoice's paid amount and status consistent. */
class PaymentService
{
    public function __construct(private CashDrawerService $drawers, private AuditLogger $audit, private LoyaltyService $loyalty, private CommissionService $commissions) {}

    /**
     * Standalone entry point (settling a balance later). Idempotent per $idempotencyKey.
     *
     * @param  array<int, array{payment_method_id: int|string, amount: string, reference?: ?string, tendered?: ?string}>  $payments
     * @return Collection<int, Payment>
     */
    public function addPayments(Invoice $invoice, array $payments, User $actor, string $idempotencyKey): Collection
    {
        return Atomic::run(function () use ($invoice, $payments, $actor, $idempotencyKey) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            return $this->record($invoice, $payments, $actor, $idempotencyKey);
        });
    }

    /**
     * Must run inside a transaction with the invoice row locked.
     *
     * @param  array<int, array{payment_method_id: int|string, amount: string, reference?: ?string, tendered?: ?string}>  $payments
     * @return Collection<int, Payment>
     */
    public function record(Invoice $invoice, array $payments, User $actor, string $idempotencyKey): Collection
    {
        if (! $invoice->isIssued()) {
            throw ValidationException::withMessages(['invoice' => 'Payments can only be taken on issued invoices.']);
        }

        $keys = [];
        foreach (array_values($payments) as $i => $row) {
            $keys[$i] = substr($idempotencyKey, 0, 60).'-'.$i;
        }
        $existing = Payment::whereIn('idempotency_key', $keys)->get()->keyBy('idempotency_key');
        if ($existing->isNotEmpty()) {
            // Replay of an already processed submission: return what was recorded, change nothing.
            foreach ($existing as $payment) {
                if ($payment->invoice_id !== $invoice->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This payment reference was already used for another invoice.']);
                }
            }

            return $existing->values();
        }

        $prepared = [];
        $errors = [];
        foreach (array_values($payments) as $i => $row) {
            $method = PaymentMethod::find($row['payment_method_id'] ?? null);
            $amount = (string) ($row['amount'] ?? '');
            if (! $method || ! $method->is_active) {
                $errors["payments.$i.payment_method_id"] = 'Choose an active payment method.';

                continue;
            }
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || Money::compare($amount, '0') <= 0) {
                $errors["payments.$i.amount"] = 'Enter a payment amount greater than zero.';

                continue;
            }
            $amount = Money::fromMinor(Money::toMinor($amount));
            $tendered = null;
            $change = '0.00';
            if ($method->isCash() && filled($row['tendered'] ?? null)) {
                $tendered = Money::fromMinor(Money::toMinor($row['tendered']));
                if (Money::compare($tendered, $amount) < 0) {
                    $errors["payments.$i.tendered"] = 'The cash handed over is less than the amount.';

                    continue;
                }
                $change = Money::sub($tendered, $amount);
            }
            $prepared[] = ['method' => $method, 'amount' => $amount, 'tendered' => $tendered, 'change' => $change, 'reference' => $row['reference'] ?? null, 'key' => $keys[$i]];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($prepared === []) {
            throw ValidationException::withMessages(['payments' => 'Enter at least one payment.']);
        }

        $sum = Money::add(...array_column($prepared, 'amount'));
        $due = $invoice->balanceDue();
        if (Money::compare($sum, $due) > 0) {
            throw ValidationException::withMessages(['payments' => "The payments ({$sum}) exceed the balance due ({$due})."]);
        }

        // Points payments never touch a cash drawer; every other method needs the cashier's open session.
        $needsDrawer = collect($prepared)->contains(fn ($p) => ! $p['method']->isLoyalty());
        $session = $needsDrawer ? $this->drawers->sessionForTransaction($actor) : null;
        $created = new Collection;
        foreach ($prepared as $p) {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'payment_method_id' => $p['method']->id,
                'cash_drawer_session_id' => $p['method']->isLoyalty() ? null : $session?->id,
                'amount' => $p['amount'],
                'tendered_amount' => $p['tendered'],
                'change_given' => $p['change'],
                'reference' => $p['reference'],
                'idempotency_key' => $p['key'],
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
            $created->push($payment);
            if ($p['method']->isLoyalty()) {
                $this->loyalty->redeemForPayment($payment, $invoice);
            }
            $this->audit->log('payment.recorded', $payment, null, [
                'invoice' => $invoice->invoice_number, 'method' => $p['method']->code, 'amount' => $p['amount'], 'drawer_session' => $session?->id,
            ]);
        }

        $paid = Money::add((string) $invoice->amount_paid, $sum);
        $invoice->forceFill([
            'amount_paid' => $paid,
            'payment_status' => $this->statusFor($invoice->total, $paid),
            'updated_by' => $actor->id,
        ])->save();

        if ($invoice->payment_status === 'paid') {
            $this->loyalty->awardForInvoice($invoice);
            $this->commissions->accrueForInvoice($invoice);
        }

        DB::afterCommit(function () use ($created): void {
            $created->each(fn (Payment $payment) => PaymentRecorded::dispatch($payment));
        });

        return $created;
    }

    public function statusFor(string $total, string $paid): string
    {
        if (Money::compare($paid, $total) >= 0) {
            return 'paid';
        }

        return Money::compare($paid, '0') > 0 ? 'partially_paid' : 'unpaid';
    }
}
