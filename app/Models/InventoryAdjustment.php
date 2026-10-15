<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryAdjustment extends Model
{
    /** @var array<string, string> */
    public const KINDS = ['count_correction' => 'Stock count correction', 'damaged' => 'Damaged', 'expired' => 'Expired', 'found' => 'Found / unrecorded stock', 'other' => 'Other loss'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous_quantity' => 'decimal:3', 'quantity_delta' => 'decimal:3', 'new_quantity' => 'decimal:3', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Adjustments are immutable.'));
        static::deleting(fn () => throw new LogicException('Adjustments are immutable.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
