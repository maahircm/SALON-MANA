<?php

namespace App\Services;

use App\Events\RefundProcessed;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Item-based refunds. The original invoice and payments are never touched: a refund is a new, linked,
 * append-only record that raises invoice.refunded_total.
 *
 * Rules: refunded value <= money actually paid and not yet refunded; refunds go back through a method the
 * customer paid with, up to what was paid with that method; refunded quantity per line <= sold quantity.
 */
class RefundService
{
    public function __construct(private CashDrawerService $drawers, private DocumentNumberService $numbers, private AuditLogger $audit) {}

    /**
     * @param  array<int|string, int|string>  $quantities  invoice_item_id => quantity to refund
     */
    public function refund(Invoice $invoice, array $quantities, PaymentMethod $method, string $reason, ?string $reference, string $idempotencyKey, User $actor): Refund
    {
        if (! $actor->can('pos.refund')) {
            abort(403, 'You are not allowed to process refunds.');
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A refund reason is required.']);
        }

        return DB::transaction(function () use ($invoice, $quantities, $method, $reason, $reference, $idempotencyKey, $actor) {
            if ($existing = Refund::where('idempotency_key', $idempotencyKey)->first()) {
                if ($existing->invoice_id !== $invoice->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This refund reference was already used for another invoice.']);
                }

                return $existing;
            }

            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isIssued()) {
                throw ValidationException::withMessages(['invoice' => 'Only issued invoices can be refunded.']);
            }

            [$lines, $amount] = $this->priceRefund($invoice, $quantities);

            $refundable = $invoice->refundableAmount();
            if (Money::compare($amount, $refundable) > 0) {
                throw ValidationException::withMessages(['items' => "The refund ({$amount}) exceeds the amount paid and not yet refunded ({$refundable})."]);
            }
            $capacity = $this->methodCapacity($invoice, $method);
            if (Money::compare($amount, $capacity) > 0) {
                throw ValidationException::withMessages(['payment_method_id' => "Only {$capacity} can be refunded to {$method->name} (what was paid with it, less earlier refunds)."]);
            }

            $session = $this->drawers->sessionForTransaction($actor);
            if ($method->isCash() && ! $session) {
                throw ValidationException::withMessages(['session' => 'Open a cash drawer session to pay a cash refund.']);
            }

            $refund = Refund::create([
                'refund_number' => $this->numbers->next('refund'),
                'invoice_id' => $invoice->id,
                'payment_method_id' => $method->id,
                'cash_drawer_session_id' => $session?->id,
                'amount' => $amount,
                'reason' => $reason,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'refunded_by' => $actor->id,
                'refunded_at' => now(),
            ]);
            foreach ($lines as $line) {
                RefundItem::create(['refund_id' => $refund->id] + $line);
            }
            $invoice->forceFill(['refunded_total' => Money::add((string) $invoice->refunded_total, $amount), 'updated_by' => $actor->id])->save();

            $this->audit->log('refund.created', $refund, null, [
                'refund_number' => $refund->refund_number, 'invoice' => $invoice->invoice_number, 'amount' => $amount, 'method' => $method->code,
                'drawer_session' => $session?->id,
            ], $reason);
            DB::afterCommit(fn () => RefundProcessed::dispatch($refund));

            return $refund;
        });
    }

    /**
     * @param  array<int|string, int|string>  $quantities
     * @return array{0: array<int, array{invoice_item_id: int, quantity: int, amount: string}>, 1: string}
     */
    private function priceRefund(Invoice $invoice, array $quantities): array
    {
        $items = $invoice->items()->get()->keyBy('id');
        $lines = [];
        $errors = [];

        foreach ($quantities as $itemId => $qty) {
            $qty = (int) $qty;
            if ($qty === 0) {
                continue;
            }
            /** @var InvoiceItem|null $item */
            $item = $items->get((int) $itemId);
            if (! $item) {
                throw ValidationException::withMessages(['items' => 'A selected line does not belong to this invoice.']);
            }
            $refundedQty = (int) RefundItem::where('invoice_item_id', $item->id)->sum('quantity');
            $refundedAmount = Money::add(...RefundItem::where('invoice_item_id', $item->id)->pluck('amount')->all());
            $remaining = $item->quantity - $refundedQty;
            if ($qty < 0 || $qty > $remaining) {
                $errors["items.{$item->id}"] = "{$item->description}: only {$remaining} left to refund.";

                continue;
            }
            // The last unit takes any rounding remainder so the line is refunded to exactly its total.
            $amount = $qty === $remaining
                ? Money::sub((string) $item->line_total, $refundedAmount)
                : Money::share((string) $item->line_total, $qty, $item->quantity);
            $lines[] = ['invoice_item_id' => $item->id, 'quantity' => $qty, 'amount' => $amount];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Select at least one item and quantity to refund.']);
        }

        return [$lines, Money::add(...array_column($lines, 'amount'))];
    }

    /** Paid with this method minus already refunded to it. */
    public function methodCapacity(Invoice $invoice, PaymentMethod $method): string
    {
        $paid = Money::add(...Payment::where('invoice_id', $invoice->id)->where('payment_method_id', $method->id)->pluck('amount')->all());
        $refunded = Money::add(...Refund::where('invoice_id', $invoice->id)->where('payment_method_id', $method->id)->pluck('amount')->all());

        return Money::sub($paid, $refunded);
    }
}
