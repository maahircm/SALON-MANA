<?php

namespace App\Models;

use App\Support\Quantity;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * cost_price and current_stock are deliberately not fillable: they change only through the stock ledger
 * (InventoryService), never by editing the product form.
 */
#[Fillable(['sku', 'barcode', 'name', 'product_category_id', 'supplier_id', 'description', 'unit', 'selling_price', 'tax_rate', 'reorder_level', 'expiry_date', 'is_sellable', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    public const UNITS = ['pc' => 'Piece', 'ml' => 'Millilitre', 'l' => 'Litre', 'g' => 'Gram', 'kg' => 'Kilogram', 'box' => 'Box', 'set' => 'Set'];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'current_stock' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'expiry_date' => 'date',
            'is_sellable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('id');
    }

    public function isLowStock(): bool
    {
        return $this->is_active && Quantity::compare($this->current_stock, $this->reorder_level) <= 0 && Quantity::compare($this->reorder_level, '0') > 0;
    }

    public function stockValue(): string
    {
        return Quantity::value($this->current_stock, $this->cost_price);
    }
}
