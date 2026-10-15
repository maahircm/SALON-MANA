<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One immutable line of the points ledger. */
class LoyaltyTransaction extends Model
{
    public const LABELS = ['earn' => 'Points earned', 'redeem' => 'Points redeemed', 'earn_reversal' => 'Earned points reversed', 'redeem_refund' => 'Redeemed points returned', 'adjustment' => 'Manual adjustment'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The loyalty ledger is immutable.'));
        static::deleting(fn () => throw new LogicException('The loyalty ledger is immutable.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
