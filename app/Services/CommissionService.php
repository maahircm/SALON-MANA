<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Commission rules (documented in the README):
 *  - Each invoice line with a service provider earns commission for that employee.
 *  - Base = line value after discounts, before tax. Percentage employees earn rate% of the base; fixed employees earn the rate per unit.
 *  - Retail product lines earn nothing unless "include products" is enabled.
 *  - Accrued when the invoice is issued (default) or once it is fully paid (setting commission.accrue_on).
 *  - The employee's rule and rate are copied onto the commission row, so later rate changes never alter history.
 *  - Refunds and voids add negative adjustments in proportion; nothing is ever edited or deleted.
 */
class CommissionService
{
    public function __construct(private SettingsService $settings, private AuditLogger $audit) {}

    /**
     * Idempotent: lines that already have a commission are skipped.
     */
    public function accrueForInvoice(Invoice $invoice): void
    {
        $mode = $this->settings->get('commission.accrue_on', 'issue');
        if (! $invoice->isIssued() || ($mode === 'full_payment' && $invoice->payment_status !== 'paid')) {
            return;
        }
        $includeProducts = (bool) $this->settings->get('commission.include_products', false);

        foreach ($invoice->items()->get() as $item) {
            if ($item->employee_id === null || $item->item_type === 'membership' || ($item->item_type === 'product' && ! $includeProducts)) {
                continue;
            }
            if (Commission::where('invoice_item_id', $item->id)->exists()) {
                continue;
            }
            $employee = Employee::withTrashed()->find($item->employee_id);
            if (! $employee || $employee->commission_type === 'none') {
                continue;
            }

            // Quantity already refunded before accrual (possible when accrual waits for full payment) earns nothing.
            $refundedQty = (int) RefundItem::where('invoice_item_id', $item->id)->sum('quantity');
            $quantity = $item->quantity - $refundedQty;
            if ($quantity <= 0) {
                continue;
            }
            $base = Money::share(Money::sub((string) $item->line_total, (string) $item->tax_amount), $quantity, $item->quantity);
            $amount = $employee->commission_type === 'percentage' ? Money::percent($base, (string) $employee->commission_rate) : Money::times((string) $employee->commission_rate, $quantity);

            $commission = Commission::create([
                'employee_id' => $employee->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'basis_amount' => $base,
                'rule_type' => $employee->commission_type, 'rate' => $employee->commission_rate, 'quantity' => $quantity, 'amount' => $amount, 'accrued_at' => now(),
            ]);
            $this->audit->log('commission.accrued', $commission, null, ['employee_id' => $employee->id, 'invoice' => $invoice->invoice_number, 'amount' => $amount]);
        }
    }

    /** Proportional negative adjustment for each refunded line that earned commission. */
    public function reverseForRefund(Refund $refund): void
    {
        foreach ($refund->items()->get() as $refundItem) {
            $commission = Commission::where('invoice_item_id', $refundItem->invoice_item_id)->first();
            if (! $commission) {
                continue;
            }
            $item = InvoiceItem::find($refundItem->invoice_item_id);
            $returnedSoFar = (int) RefundItem::where('invoice_item_id', $item->id)->where('id', '<=', $refundItem->id)->sum('quantity');
            $remainingEarned = $this->netOf($commission);
            // The final unit takes whatever is left so a fully refunded line nets to exactly zero.
            $reduction = $returnedSoFar >= $item->quantity ? $remainingEarned : Money::share((string) $commission->amount, $refundItem->quantity, $item->quantity);
            $reduction = Money::compare($reduction, $remainingEarned) > 0 ? $remainingEarned : $reduction;
            if (Money::compare($reduction, '0') <= 0) {
                continue;
            }
            $this->adjustment($commission->employee_id, $commission->id, '-'.$reduction, 'refund', "Refund {$refund->refund_number}", "refund_item:{$refundItem->id}", null);
        }
    }

    public function reverseForVoid(Invoice $invoice): void
    {
        foreach (Commission::where('invoice_id', $invoice->id)->get() as $commission) {
            $remaining = $this->netOf($commission);
            if (Money::compare($remaining, '0') > 0) {
                $this->adjustment($commission->employee_id, $commission->id, '-'.$remaining, 'void', "Voided invoice {$invoice->invoice_number}", "void:{$commission->id}", null);
            }
        }
    }

    /** A manual bonus (+) or deduction (-) by HR; reason mandatory and audited. */
    public function adjustManually(Employee $employee, string $amount, string $reason, User $actor): CommissionAdjustment
    {
        if (! $actor->can('employees.commissions')) {
            abort(403, 'You are not allowed to adjust commissions.');
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $amount) || Money::compare($amount, '0') === 0) {
            throw ValidationException::withMessages(['amount' => 'Enter a non-zero amount with at most two decimals (negative to deduct).']);
        }

        return $this->adjustment($employee->id, null, Money::fromMinor(Money::toMinor($amount)), 'manual', $reason, null, $actor);
    }

    /** Net amount a commission is currently worth (accrued plus its adjustments). */
    public function netOf(Commission $commission): string
    {
        return Money::add((string) $commission->amount, ...CommissionAdjustment::where('commission_id', $commission->id)->pluck('amount')->map(fn ($a) => (string) $a)->all());
    }

    private function adjustment(int $employeeId, ?int $commissionId, string $amount, string $type, ?string $reason, ?string $key, ?User $actor): CommissionAdjustment
    {
        if ($key !== null && ($existing = CommissionAdjustment::where('idempotency_key', $key)->first())) {
            return $existing;
        }
        $adjustment = CommissionAdjustment::create([
            'employee_id' => $employeeId, 'commission_id' => $commissionId, 'amount' => $amount, 'type' => $type, 'reason' => $reason,
            'idempotency_key' => $key, 'created_by' => $actor?->id, 'created_at' => now(),
        ]);
        $this->audit->log("commission.{$type}_adjustment", $adjustment, null, ['employee_id' => $employeeId, 'amount' => $amount], $reason);

        return $adjustment;
    }
}
