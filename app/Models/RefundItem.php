<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RefundItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refund lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Refund lines are immutable.'));
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}
