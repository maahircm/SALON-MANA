<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Sales invoice. Drafts are editable; once issued the lines, prices and totals are frozen and only the
 * payment/refund/void bookkeeping columns change, exclusively through the POS services.
 */
class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_VOIDED = 'voided';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'coupon_discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'last_printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $invoice): void {
            if ($invoice->getOriginal('status') !== self::STATUS_DRAFT) {
                throw new LogicException('Issued invoices can never be deleted; void or refund them instead.');
            }
        });
        static::updating(function (self $invoice): void {
            if ($invoice->getOriginal('status') !== self::STATUS_DRAFT) {
                $frozen = ['customer_id', 'appointment_id', 'coupon_id', 'currency', 'subtotal', 'discount_total', 'coupon_discount_total', 'tax_total', 'total', 'invoice_number', 'issued_at', 'issued_by', 'created_by', 'checkout_key'];
                if ($invoice->isDirty($frozen)) {
                    throw new LogicException('Issued invoice amounts and identity cannot be changed.');
                }
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /** Amount still owed by the customer (refunds do not change it: they return money already paid). */
    public function balanceDue(): string
    {
        return $this->isIssued() ? Money::sub($this->total, $this->amount_paid) : '0.00';
    }

    /** Money paid and not refunded. */
    public function netPaid(): string
    {
        return Money::sub($this->amount_paid, $this->refunded_total);
    }

    /** Total that can still be refunded in cash terms. */
    public function refundableAmount(): string
    {
        return $this->isIssued() ? Money::sub($this->amount_paid, $this->refunded_total) : '0.00';
    }

    public function refundStatus(): string
    {
        if (Money::compare($this->refunded_total, '0') === 0) {
            return 'none';
        }

        return Money::compare($this->refunded_total, $this->total) >= 0 ? 'full' : 'partial';
    }
}
