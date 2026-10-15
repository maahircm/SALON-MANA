<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\Invoice;
use App\Models\RefundItem;
use App\Models\User;
use App\Support\Atomic;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Membership purchases (sold as an invoice line), their benefits and their lifecycle. */
class MembershipService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Create the membership when an invoice containing a plan is issued. One membership per invoice; a renewal starts
     * the day after the current one ends.
     */
    public function activateForInvoice(Invoice $invoice, User $actor): ?CustomerMembership
    {
        $item = $invoice->items()->whereNotNull('membership_plan_id')->with('membershipPlan')->first();
        if (! $item || $invoice->customer_id === null) {
            return null;
        }
        if ($existing = CustomerMembership::where('invoice_id', $invoice->id)->first()) {
            return $existing;
        }
        $plan = $item->membershipPlan;
        $current = Customer::withTrashed()->find($invoice->customer_id)->activeMembership();
        $starts = $current ? CarbonImmutable::parse($current->ends_on)->addDay() : CarbonImmutable::today();
        $ends = $starts->addDays($plan->duration_days - 1);

        $membership = CustomerMembership::create([
            'customer_id' => $invoice->customer_id, 'membership_plan_id' => $plan->id, 'invoice_id' => $invoice->id, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString(),
            'status' => 'active', 'price_paid' => $item->unit_price, 'discount_percent' => $plan->discount_percent, 'points_multiplier' => $plan->points_multiplier, 'created_by' => $actor->id,
        ]);
        $this->audit->log('membership.activated', $membership, null, ['customer_id' => $invoice->customer_id, 'plan' => $plan->name, 'ends_on' => $membership->ends_on->toDateString()]);

        return $membership;
    }

    /** A voided sale, or a fully refunded membership line, ends the membership. */
    public function cancelForInvoice(Invoice $invoice, string $reason): void
    {
        $membership = CustomerMembership::where('invoice_id', $invoice->id)->where('status', 'active')->first();
        if ($membership) {
            $this->cancelRecord($membership, $reason);
        }
    }

    public function cancelIfRefunded(Invoice $invoice): void
    {
        $item = $invoice->items()->whereNotNull('membership_plan_id')->first();
        if ($item && (int) RefundItem::where('invoice_item_id', $item->id)->sum('quantity') >= $item->quantity) {
            $this->cancelForInvoice($invoice, 'Membership refunded');
        }
    }

    public function cancel(CustomerMembership $membership, string $reason, User $actor): CustomerMembership
    {
        if (! $actor->can('loyalty.manage')) {
            abort(403, 'You are not allowed to cancel memberships.');
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return Atomic::run(function () use ($membership, $reason) {
            $membership = CustomerMembership::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($membership->status !== 'active') {
                throw ValidationException::withMessages(['membership' => 'This membership is not active.']);
            }

            return $this->cancelRecord($membership, $reason);
        });
    }

    /** Marks memberships whose end date has passed as expired. Returns how many were updated. */
    public function expireOverdue(): int
    {
        $count = 0;
        foreach (CustomerMembership::where('status', 'active')->whereDate('ends_on', '<', today())->get() as $membership) {
            $membership->forceFill(['status' => 'expired'])->save();
            $this->audit->log('membership.expired', $membership, ['status' => 'active'], ['status' => 'expired']);
            $count++;
        }

        return $count;
    }

    private function cancelRecord(CustomerMembership $membership, string $reason): CustomerMembership
    {
        $membership->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        $this->audit->log('membership.cancelled', $membership, ['status' => 'active'], ['status' => 'cancelled'], $reason);

        return $membership;
    }
}
