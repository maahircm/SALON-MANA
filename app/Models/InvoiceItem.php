<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class InvoiceItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $item): void {
            $status = Invoice::whereKey($item->invoice_id)->value('status');
            if ($status !== null && $status !== Invoice::STATUS_DRAFT) {
                throw new LogicException('Lines of an issued invoice are immutable.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }
}
