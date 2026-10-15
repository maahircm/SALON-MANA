<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Support\Atomic;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Points ledger. Rules (see README): points are earned once per invoice, when it becomes fully paid, on the net spend
 * (after discounts, before tax, excluding memberships and the part paid with points). Redeeming is a payment method.
 * The balance is unsigned and every movement is an immutable ledger row with a unique key, so points can neither go
 * negative nor be awarded twice.
 */
class LoyaltyService
{
    public function __construct(private SettingsService $settings, private AuditLogger $audit) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('loyalty.enabled', true);
    }

    /** Points needed to cover a currency amount (rounded up in the customer's disfavour only by a fraction of a point). */
    public function pointsForAmount(string $amount): int
    {
        $perUnit = Money::toMinor((string) $this->settings->get('loyalty.redeem_points_per_unit', '100'));

        return (int) ceil(Money::toMinor($amount) * $perUnit / 10000);
    }

    public function balanceOf(Customer $customer): int
    {
        return (int) (LoyaltyAccount::where('customer_id', $customer->id)->value('balance') ?? 0);
    }

    public function awardForInvoice(Invoice $invoice): ?LoyaltyTransaction
    {
        if (! $this->enabled() || ! $invoice->isIssued() || $invoice->customer_id === null || $invoice->payment_status !== 'paid') {
            return null;
        }
        $key = "earn:invoice:{$invoice->id}";
        if ($existing = LoyaltyTransaction::where('idempotency_key', $key)->first()) {
            return $existing;
        }

        $qualifying = '0.00';
        foreach ($invoice->items()->get() as $item) {
            if ($item->item_type !== 'membership') {
                $qualifying = Money::add($qualifying, Money::sub((string) $item->line_total, (string) $item->tax_amount));
            }
        }
        $paidWithPoints = Money::add(...Payment::where('invoice_id', $invoice->id)->whereHas('method', fn ($q) => $q->where('type', 'loyalty'))->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $qualifyingMinor = max(0, Money::toMinor($qualifying) - Money::toMinor($paidWithPoints));

        $customer = Customer::withTrashed()->find($invoice->customer_id);
        $multiplier = Money::toMinor((string) ($customer->activeMembership()?->points_multiplier ?? '1'));
        $rate = Money::toMinor((string) $this->settings->get('loyalty.earn_points_per_unit', '1'));
        $points = intdiv($qualifyingMinor * $rate * $multiplier, 1000000);
        if ($points <= 0) {
            return null;
        }

        return $this->post($customer, 'earn', $points, "Invoice {$invoice->invoice_number}", $key, invoiceId: $invoice->id);
    }

    /** Redeeming happens as a payment; the caller has the invoice row locked. */
    public function redeemForPayment(Payment $payment, Invoice $invoice): LoyaltyTransaction
    {
        $customer = $invoice->customer_id ? Customer::withTrashed()->find($invoice->customer_id) : null;
        if (! $customer) {
            throw ValidationException::withMessages(['payments' => 'Loyalty points can only be used on an invoice that has a customer.']);
        }
        $points = $this->pointsForAmount((string) $payment->amount);
        $min = (int) $this->settings->get('loyalty.min_redeem_points', 100);
        if ($points < $min) {
            throw ValidationException::withMessages(['payments' => "The minimum redemption is {$min} points."]);
        }
        $maxPercent = (string) $this->settings->get('loyalty.max_redeem_percent', '100');
        $alreadyWithPoints = Money::add(...Payment::where('invoice_id', $invoice->id)->where('id', '!=', $payment->id)->whereHas('method', fn ($q) => $q->where('type', 'loyalty'))->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $cap = Money::percent((string) $invoice->total, $maxPercent);
        if (Money::compare(Money::add($alreadyWithPoints, (string) $payment->amount), $cap) > 0) {
            throw ValidationException::withMessages(['payments' => "At most {$maxPercent}% of an invoice can be paid with loyalty points (up to {$cap})."]);
        }

        return $this->post($customer, 'redeem', -$points, "Payment on invoice {$invoice->invoice_number}", "redeem:payment:{$payment->id}", invoiceId: $invoice->id, paymentId: $payment->id);
    }

    /** Points go back when a loyalty-method refund is paid. */
    public function creditForRefund(Refund $refund, Invoice $invoice): ?LoyaltyTransaction
    {
        $customer = $invoice->customer_id ? Customer::withTrashed()->find($invoice->customer_id) : null;
        $points = (int) floor(Money::toMinor((string) $refund->amount) * Money::toMinor((string) $this->settings->get('loyalty.redeem_points_per_unit', '100')) / 10000);
        if (! $customer || $points <= 0) {
            return null;
        }

        return $this->post($customer, 'redeem_refund', $points, "Refund {$refund->refund_number}", "redeem_refund:refund:{$refund->id}", invoiceId: $invoice->id, refundId: $refund->id);
    }

    /** Claw back earned points in proportion to the refunded share of the invoice (never below zero, never more than earned). */
    public function reverseForRefund(Refund $refund, Invoice $invoice): ?LoyaltyTransaction
    {
        $earn = LoyaltyTransaction::where('idempotency_key', "earn:invoice:{$invoice->id}")->first();
        if (! $earn || Money::compare((string) $invoice->total, '0') <= 0) {
            return null;
        }
        $reversible = $earn->points - $this->alreadyReversed($invoice);
        $points = min($reversible, intdiv($earn->points * Money::toMinor((string) $refund->amount), max(1, Money::toMinor((string) $invoice->total))));

        return $this->reverse($invoice, $points, "Refund {$refund->refund_number}", "earn_reversal:refund:{$refund->id}", $refund->id);
    }

    public function reverseForVoid(Invoice $invoice): ?LoyaltyTransaction
    {
        $earn = LoyaltyTransaction::where('idempotency_key', "earn:invoice:{$invoice->id}")->first();
        if (! $earn) {
            return null;
        }

        return $this->reverse($invoice, $earn->points - $this->alreadyReversed($invoice), "Voided invoice {$invoice->invoice_number}", "earn_reversal:void:{$invoice->id}", null);
    }

    /** Manual correction by loyalty managers; mandatory reason; cannot take the balance below zero. */
    public function adjust(Customer $customer, int $points, string $reason, User $actor): LoyaltyTransaction
    {
        if (! $actor->can('loyalty.manage')) {
            abort(403, 'You are not allowed to adjust loyalty points.');
        }
        if ($points === 0 || abs($points) > 1000000) {
            throw ValidationException::withMessages(['points' => 'Enter a non-zero number of points (maximum 1,000,000).']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return $this->post($customer, 'adjustment', $points, $reason, null, createdBy: $actor->id);
    }

    private function reverse(Invoice $invoice, int $points, string $reason, string $key, ?int $refundId): ?LoyaltyTransaction
    {
        $customer = Customer::withTrashed()->find($invoice->customer_id);
        // Points already spent cannot be clawed back below a zero balance.
        $points = min($points, $this->balanceOf($customer));
        if ($points <= 0) {
            return null;
        }

        return $this->post($customer, 'earn_reversal', -$points, $reason, $key, invoiceId: $invoice->id, refundId: $refundId);
    }

    private function alreadyReversed(Invoice $invoice): int
    {
        return (int) abs(LoyaltyTransaction::where('invoice_id', $invoice->id)->where('type', 'earn_reversal')->sum('points'));
    }

    /**
     * The single writer of the points ledger and balance.
     */
    private function post(Customer $customer, string $type, int $points, ?string $reason, ?string $key, ?int $invoiceId = null, ?int $paymentId = null, ?int $refundId = null, ?int $createdBy = null): LoyaltyTransaction
    {
        return Atomic::run(function () use ($customer, $type, $points, $reason, $key, $invoiceId, $paymentId, $refundId, $createdBy) {
            if ($key !== null && ($existing = LoyaltyTransaction::where('idempotency_key', $key)->first())) {
                return $existing;
            }
            LoyaltyAccount::query()->insertOrIgnore(['customer_id' => $customer->id, 'balance' => 0, 'lifetime_earned' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $account = LoyaltyAccount::where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();

            $balance = $account->balance + $points;
            if ($balance < 0) {
                throw ValidationException::withMessages(['points' => "Not enough points: {$account->balance} available, ".abs($points).' needed.']);
            }
            $account->forceFill(['balance' => $balance, 'lifetime_earned' => $account->lifetime_earned + ($type === 'earn' ? $points : 0)])->save();

            $tx = LoyaltyTransaction::create([
                'customer_loyalty_account_id' => $account->id, 'customer_id' => $customer->id, 'type' => $type, 'points' => $points, 'balance_after' => $balance,
                'invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'refund_id' => $refundId, 'reason' => $reason, 'idempotency_key' => $key,
                'created_by' => $createdBy ?? auth()->id(), 'created_at' => now(),
            ]);
            $this->audit->log("loyalty.{$type}", $tx, null, ['customer_id' => $customer->id, 'points' => $points, 'balance_after' => $balance], $reason);

            return $tx;
        });
    }

    /**
     * Compare each account balance with the sum of its ledger.
     *
     * @return array<int, array{customer_id: int, balance: int, ledger: int}>
     */
    public function reconcile(): array
    {
        $out = [];
        foreach (LoyaltyAccount::all() as $account) {
            $ledger = (int) LoyaltyTransaction::where('customer_loyalty_account_id', $account->id)->sum('points');
            if ($ledger !== $account->balance) {
                $out[] = ['customer_id' => $account->customer_id, 'balance' => $account->balance, 'ledger' => $ledger];
            }
        }

        return $out;
    }
}
