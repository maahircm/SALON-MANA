<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Money returned to a customer against an invoice. Append-only. */
class Refund extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refunds are immutable.'));
        static::deleting(fn () => throw new LogicException('Refunds can never be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashDrawerSession::class, 'cash_drawer_session_id');
    }
}
