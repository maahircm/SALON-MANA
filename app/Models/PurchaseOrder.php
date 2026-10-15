<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['supplier_id', 'expected_on', 'notes'])]
class PurchaseOrder extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'ordered' => 'Ordered', 'partially_received' => 'Partially received', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return ['expected_on' => 'date', 'total' => 'decimal:2', 'ordered_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function canReceive(): bool
    {
        return in_array($this->status, ['ordered', 'partially_received'], true);
    }
}
