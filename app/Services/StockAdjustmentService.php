<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\Atomic;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/** Manual stock corrections, damage / expiry write-offs and returns to suppliers. Always with a reason. */
class StockAdjustmentService
{
    public function __construct(private InventoryService $inventory, private DocumentNumberService $numbers, private AuditLogger $audit) {}

    /**
     * @param  string  $quantity  counted quantity for count_correction; a positive amount for every other kind
     */
    public function adjust(Product $product, string $kind, string $quantity, string $reason, User $actor): InventoryAdjustment
    {
        if (! $actor->can('inventory.adjust')) {
            abort(403, 'You are not allowed to adjust stock.');
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for every stock adjustment.']);
        }
        if (! array_key_exists($kind, InventoryAdjustment::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Unknown adjustment type.']);
        }
        if (! preg_match('/^\d+(\.\d{1,3})?$/', $quantity)) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity with at most three decimals.']);
        }

        return Atomic::run(function () use ($product, $kind, $quantity, $reason, $actor) {
            $locked = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $previous = (string) $locked->current_stock;

            if ($kind === 'count_correction') {
                $delta = Quantity::sub($quantity, $previous);
                if (Quantity::compare($delta, '0') === 0) {
                    throw ValidationException::withMessages(['quantity' => 'The counted quantity equals the recorded stock; nothing to adjust.']);
                }
                $type = Quantity::compare($delta, '0') > 0 ? StockMovement::ADJUSTMENT_IN : StockMovement::ADJUSTMENT_OUT;
            } else {
                if (Quantity::compare($quantity, '0') <= 0) {
                    throw ValidationException::withMessages(['quantity' => 'Enter a quantity greater than zero.']);
                }
                [$type, $delta] = match ($kind) {
                    'damaged' => [StockMovement::DAMAGE, '-'.$quantity],
                    'expired' => [StockMovement::EXPIRY, '-'.$quantity],
                    'found' => [StockMovement::ADJUSTMENT_IN, $quantity],
                    default => [StockMovement::ADJUSTMENT_OUT, '-'.$quantity],
                };
            }

            $adjustment = InventoryAdjustment::create([
                'product_id' => $locked->id, 'kind' => $kind, 'previous_quantity' => $previous, 'quantity_delta' => $delta,
                'new_quantity' => Quantity::add($previous, $delta), 'reason' => $reason, 'user_id' => $actor->id,
            ]);
            $this->inventory->record($locked->id, $type, $delta, $actor, $reason, 'adjustment', $adjustment->id);
            $this->audit->log('inventory.adjusted', $locked, ['stock' => $previous], ['stock' => (string) $adjustment->new_quantity, 'kind' => $kind], $reason);

            return $adjustment;
        });
    }

    public function returnToSupplier(Supplier $supplier, Product $product, string $quantity, string $reason, User $actor): SupplierReturn
    {
        if (! $actor->can('inventory.purchasing')) {
            abort(403, 'You are not allowed to return goods to suppliers.');
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        if (! preg_match('/^\d+(\.\d{1,3})?$/', $quantity) || Quantity::compare($quantity, '0') <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity greater than zero.']);
        }

        return Atomic::run(function () use ($supplier, $product, $quantity, $reason, $actor) {
            $locked = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $return = SupplierReturn::create([
                'return_number' => $this->numbers->next('supplier_return', 'inventory.supplier_return_prefix', 'SR-'),
                'supplier_id' => $supplier->id, 'product_id' => $locked->id, 'quantity' => $quantity, 'unit_cost' => $locked->cost_price,
                'reason' => $reason, 'created_by' => $actor->id,
            ]);
            $this->inventory->record($locked->id, StockMovement::SUPPLIER_RETURN, '-'.$quantity, $actor, "{$return->return_number}: {$reason}", 'supplier_return', $return->id, null, "supplier_return:{$return->id}");
            $this->audit->log('inventory.supplier_return', $return, null, ['product' => $locked->sku, 'quantity' => $quantity, 'supplier' => $supplier->name], $reason);

            return $return;
        });
    }
}
