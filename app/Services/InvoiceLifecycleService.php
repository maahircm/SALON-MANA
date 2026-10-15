<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Atomic;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** Voiding issued invoices and discarding drafts. Nothing financial is ever deleted. */
class InvoiceLifecycleService
{
    public function __construct(
        private AuditLogger $audit,
        private InventoryService $inventory,
        private CommissionService $commissions,
        private LoyaltyService $loyalty,
        private CouponService $coupons,
        private MembershipService $memberships,
    ) {}

    /**
     * Void an invoice that holds no net money. Paid invoices must be refunded first, so cash history stays whole.
     */
    public function void(Invoice $invoice, string $reason, User $actor): Invoice
    {
        if (! $actor->can('pos.void')) {
            abort(403, 'You are not allowed to void invoices.');
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to void an invoice.']);
        }

        return Atomic::run(function () use ($invoice, $reason, $actor) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isIssued()) {
                throw ValidationException::withMessages(['invoice' => 'Only issued invoices can be voided.']);
            }
            if (Money::compare($invoice->netPaid(), '0') !== 0) {
                throw ValidationException::withMessages(['invoice' => "This invoice still holds {$invoice->netPaid()} of customer payments. Refund it before voiding."]);
            }
            $invoice->forceFill(['status' => Invoice::STATUS_VOIDED, 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason, 'updated_by' => $actor->id])->save();
            $this->inventory->reverseForInvoice($invoice, $actor);
            $this->commissions->reverseForVoid($invoice);
            $this->loyalty->reverseForVoid($invoice);
            $this->coupons->reverseForVoid($invoice);
            $this->memberships->cancelForInvoice($invoice, "Invoice {$invoice->invoice_number} voided");
            $this->audit->log('invoice.voided', $invoice, ['status' => Invoice::STATUS_ISSUED], ['status' => Invoice::STATUS_VOIDED, 'invoice_number' => $invoice->invoice_number], $reason);

            return $invoice;
        });
    }

    public function discardDraft(Invoice $invoice, User $actor): void
    {
        Atomic::run(function () use ($invoice, $actor) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['invoice' => 'Only drafts can be discarded.']);
            }
            if ($invoice->created_by !== $actor->id && ! $actor->can('pos.void')) {
                abort(403, 'This draft belongs to another user.');
            }
            $this->audit->log('invoice.draft_discarded', $invoice, ['total' => $invoice->total], null);
            $invoice->items()->delete();
            $invoice->delete();
        });
    }
}
