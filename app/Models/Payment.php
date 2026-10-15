<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A completed payment against an invoice. Append-only: mistakes are corrected with refunds. */
class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'tendered_amount' => 'decimal:2', 'change_given' => 'decimal:2', 'received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments are immutable; use a refund to reverse one.'));
        static::deleting(fn () => throw new LogicException('Payments can never be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashDrawerSession::class, 'cash_drawer_session_id');
    }
}
