<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Validates and redeems coupons. Validation runs while pricing a cart (preview) and again, under a row lock,
 * when the invoice is issued, so limits hold even when many tills redeem the same code at once.
 */
class CouponService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  string  $eligibleSubtotal  value of the lines the coupon applies to (after line discounts, before tax)
     * @return array{coupon: Coupon, discount: string}
     */
    public function evaluate(string $code, ?Customer $customer, string $eligibleSubtotal, bool $lock = false, ?int $ignoreInvoiceId = null): array
    {
        $query = Coupon::with('promotion')->where('code', strtoupper(trim($code)));
        $coupon = $lock ? $query->lockForUpdate()->first() : $query->first();
        if (! $coupon || ! $coupon->is_active) {
            $this->fail('This coupon code is not valid.');
        }
        $promotion = $coupon->promotion;
        if (! $promotion->is_active) {
            $this->fail('This promotion is not active.');
        }
        if (now()->lt($promotion->starts_at)) {
            $this->fail('This promotion has not started yet.');
        }
        if (now()->gt($promotion->ends_at)) {
            $this->fail('This promotion has ended.');
        }
        if (Money::compare($eligibleSubtotal, (string) $promotion->min_purchase) < 0) {
            $this->fail('This coupon needs a minimum purchase of '.money($promotion->min_purchase).'.');
        }
        $redeemed = fn ($q) => $q->where('status', 'redeemed')->when($ignoreInvoiceId, fn ($w) => $w->where('invoice_id', '!=', $ignoreInvoiceId));
        if ($coupon->max_uses !== null && $redeemed(CouponRedemption::where('coupon_id', $coupon->id))->count() >= $coupon->max_uses) {
            $this->fail('This coupon has reached its usage limit.');
        }
        if ($promotion->max_redemptions !== null && $redeemed(CouponRedemption::where('promotion_id', $promotion->id))->count() >= $promotion->max_redemptions) {
            $this->fail('This promotion has reached its redemption limit.');
        }
        if ($promotion->per_customer_limit !== null) {
            if (! $customer) {
                $this->fail('Choose a customer to use this coupon (it has a per-customer limit).');
            }
            if ($redeemed(CouponRedemption::where('promotion_id', $promotion->id)->where('customer_id', $customer->id))->count() >= $promotion->per_customer_limit) {
                $this->fail('This customer has already used this promotion the maximum number of times.');
            }
        }

        $discount = $promotion->discount_type === 'percent'
            ? Money::percent($eligibleSubtotal, (string) $promotion->discount_value)
            : (Money::compare((string) $promotion->discount_value, $eligibleSubtotal) > 0 ? $eligibleSubtotal : (string) $promotion->discount_value);

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    /** Called inside the checkout transaction; the unique (coupon, invoice) key makes a repeat impossible. */
    public function redeem(Invoice $invoice, string $eligibleSubtotal): ?CouponRedemption
    {
        if ($invoice->coupon_id === null) {
            return null;
        }
        $coupon = Coupon::find($invoice->coupon_id);
        $customer = $invoice->customer_id ? Customer::withTrashed()->find($invoice->customer_id) : null;
        $result = $this->evaluate($coupon->code, $customer, $eligibleSubtotal, true, $invoice->id);

        $redemption = CouponRedemption::create([
            'coupon_id' => $coupon->id, 'promotion_id' => $coupon->promotion_id, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'discount_amount' => $invoice->coupon_discount_total, 'status' => 'redeemed', 'created_at' => now(),
        ]);
        $result['coupon']->forceFill(['used_count' => $result['coupon']->used_count + 1])->save();
        $this->audit->log('coupon.redeemed', $redemption, null, ['code' => $coupon->code, 'invoice' => $invoice->invoice_number, 'discount' => (string) $invoice->coupon_discount_total]);

        return $redemption;
    }

    public function reverseForVoid(Invoice $invoice): void
    {
        $redemption = CouponRedemption::where('invoice_id', $invoice->id)->where('status', 'redeemed')->first();
        if (! $redemption) {
            return;
        }
        $redemption->forceFill(['status' => 'reversed', 'reversed_at' => now()])->save();
        $coupon = Coupon::whereKey($redemption->coupon_id)->lockForUpdate()->first();
        $coupon->forceFill(['used_count' => max(0, $coupon->used_count - 1)])->save();
        $this->audit->log('coupon.reversed', $redemption, null, ['invoice' => $invoice->invoice_number]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['coupon_code' => $message]);
    }
}
