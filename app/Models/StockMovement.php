<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One immutable line of the stock ledger. Corrections are new, opposite movements. */
class StockMovement extends Model
{
    public const PURCHASE_RECEIPT = 'purchase_receipt';

    public const SALE = 'sale';

    public const SERVICE_USAGE = 'service_usage';

    public const SALE_RETURN = 'sale_return';

    public const VOID_REVERSAL = 'void_reversal';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public const DAMAGE = 'damage';

    public const EXPIRY = 'expiry';

    public const SUPPLIER_RETURN = 'supplier_return';

    /** @var array<string, string> */
    public const LABELS = [
        self::PURCHASE_RECEIPT => 'Goods received', self::SALE => 'Retail sale', self::SERVICE_USAGE => 'Used in service', self::SALE_RETURN => 'Customer return',
        self::VOID_REVERSAL => 'Invoice void', self::ADJUSTMENT_IN => 'Adjustment (+)', self::ADJUSTMENT_OUT => 'Adjustment (−)', self::DAMAGE => 'Damaged',
        self::EXPIRY => 'Expired', self::SUPPLIER_RETURN => 'Returned to supplier',
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'balance_after' => 'decimal:3', 'unit_cost' => 'decimal:2', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The stock ledger is immutable.'));
        static::deleting(fn () => throw new LogicException('The stock ledger is immutable.'));
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
