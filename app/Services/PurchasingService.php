<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Atomic;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/** Purchase orders and goods receipts. Receiving stock is the only way purchased goods enter the ledger. */
class PurchasingService
{
    public function __construct(private InventoryService $inventory, private DocumentNumberService $numbers, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  supplier_id, expected_on, notes
     * @param  array<int|string, array{quantity: string, unit_cost: string}>  $lines  keyed by product id
     */
    public function saveDraft(?PurchaseOrder $order, array $data, array $lines, User $actor): PurchaseOrder
    {
        $prepared = $this->prepareLines($lines);

        return Atomic::run(function () use ($order, $data, $prepared, $actor) {
            if ($order) {
                $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! $order->isEditable()) {
                    throw ValidationException::withMessages(['order' => 'Only draft purchase orders can be edited.']);
                }
            } else {
                $order = new PurchaseOrder;
                $order->created_by = $actor->id;
            }
            $order->fill(['supplier_id' => $data['supplier_id'], 'expected_on' => $data['expected_on'] ?? null, 'notes' => $data['notes'] ?? null]);
            $order->forceFill(['total' => Money::add(...array_map(fn ($l) => Quantity::value($l['quantity'], $l['unit_cost']), $prepared)), 'updated_by' => $actor->id])->save();

            $order->items()->delete();
            foreach ($prepared as $line) {
                $order->items()->create(['product_id' => $line['product_id'], 'quantity_ordered' => $line['quantity'], 'quantity_received' => '0.000', 'unit_cost' => $line['unit_cost']]);
            }
            $this->audit->log('purchase_order.draft_saved', $order, null, ['supplier_id' => $order->supplier_id, 'total' => (string) $order->total, 'lines' => count($prepared)]);

            return $order;
        });
    }

    public function place(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return Atomic::run(function () use ($order, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isEditable()) {
                throw ValidationException::withMessages(['order' => 'Only draft purchase orders can be placed.']);
            }
            if (! $order->items()->exists()) {
                throw ValidationException::withMessages(['order' => 'Add at least one product before placing the order.']);
            }
            $order->forceFill(['po_number' => $this->numbers->next('purchase_order', 'inventory.purchase_order_prefix', 'PO-'), 'status' => 'ordered', 'ordered_at' => now(), 'updated_by' => $actor->id])->save();
            $this->audit->log('purchase_order.placed', $order, ['status' => 'draft'], ['status' => 'ordered', 'po_number' => $order->po_number]);

            return $order;
        });
    }

    public function cancel(PurchaseOrder $order, string $reason, User $actor): PurchaseOrder
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to cancel an order.']);
        }

        return Atomic::run(function () use ($order, $reason, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['draft', 'ordered'], true) || $order->receipts()->exists()) {
                throw ValidationException::withMessages(['order' => 'Only orders that have not received any goods can be cancelled. Close a part-received order instead.']);
            }
            $before = $order->status;
            $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_by' => $actor->id])->save();
            $this->audit->log('purchase_order.cancelled', $order, ['status' => $before], ['status' => 'cancelled'], $reason);

            return $order;
        });
    }

    /** Accept a part-delivered order as complete; the undelivered balance is abandoned. */
    public function closeShort(PurchaseOrder $order, string $reason, User $actor): PurchaseOrder
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return Atomic::run(function () use ($order, $reason, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'partially_received') {
                throw ValidationException::withMessages(['order' => 'Only part-received orders can be closed short.']);
            }
            $order->forceFill(['status' => 'received', 'updated_by' => $actor->id])->save();
            $this->audit->log('purchase_order.closed_short', $order, ['status' => 'partially_received'], ['status' => 'received'], $reason);

            return $order;
        });
    }

    /**
     * Receive goods against an order: creates the receipt, the ledger entries and updates average cost.
     *
     * @param  array<int|string, array{quantity?: string|null, expiry_date?: string|null}>  $received  keyed by purchase order item id
     */
    public function receive(PurchaseOrder $order, array $received, ?string $supplierReference, ?string $notes, User $actor): GoodsReceipt
    {
        return Atomic::run(function () use ($order, $received, $supplierReference, $notes, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->canReceive()) {
                throw ValidationException::withMessages(['order' => 'Goods can only be received against an ordered purchase order.']);
            }
            $items = $order->items()->with('product:id,name')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $errors = [];
            foreach ($received as $itemId => $row) {
                $qty = trim((string) ($row['quantity'] ?? ''));
                if ($qty === '' || Quantity::compare(preg_match('/^\d+(\.\d{1,3})?$/', $qty) ? $qty : '0', '0') === 0) {
                    continue;
                }
                /** @var PurchaseOrderItem|null $item */
                $item = $items->get((int) $itemId);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => 'A line does not belong to this order.']);
                }
                if (! preg_match('/^\d+(\.\d{1,3})?$/', $qty)) {
                    $errors["items.{$itemId}"] = 'Enter a valid quantity.';

                    continue;
                }
                if (Quantity::compare($qty, $item->outstanding()) > 0) {
                    $errors["items.{$itemId}"] = "Cannot receive {$qty}: only {$item->outstanding()} still outstanding for {$item->product->name}.";

                    continue;
                }
                $lines[] = ['item' => $item, 'quantity' => Quantity::fromMilli(Quantity::toMilli($qty)), 'expiry' => ($row['expiry_date'] ?? null) ?: null];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'Enter the quantity received for at least one line.']);
            }

            $receipt = GoodsReceipt::create([
                'receipt_number' => $this->numbers->next('goods_receipt', 'inventory.goods_receipt_prefix', 'GR-'),
                'purchase_order_id' => $order->id, 'supplier_reference' => $supplierReference, 'notes' => $notes, 'received_by' => $actor->id, 'received_at' => now(),
            ]);
            foreach ($lines as $line) {
                /** @var PurchaseOrderItem $item */
                $item = $line['item'];
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $line['quantity'], 'unit_cost' => $item->unit_cost, 'expiry_date' => $line['expiry'],
                ]);
                $this->inventory->record($item->product_id, StockMovement::PURCHASE_RECEIPT, $line['quantity'], $actor, "{$receipt->receipt_number} ({$order->po_number})", 'goods_receipt', $receiptItem->id,
                    (string) $item->unit_cost, "receipt:{$receiptItem->id}", true);
                $item->forceFill(['quantity_received' => Quantity::add((string) $item->quantity_received, $line['quantity'])])->save();

                if ($line['expiry']) {
                    $product = Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->first();
                    if ($product->expiry_date === null || $product->expiry_date->gt($line['expiry'])) {
                        $product->forceFill(['expiry_date' => $line['expiry']])->save();
                    }
                }
            }

            $complete = $order->items()->get()->every(fn (PurchaseOrderItem $i) => Quantity::compare($i->quantity_received, $i->quantity_ordered) >= 0);
            $order->forceFill(['status' => $complete ? 'received' : 'partially_received', 'updated_by' => $actor->id])->save();
            $this->audit->log('goods_receipt.created', $receipt, null, ['receipt_number' => $receipt->receipt_number, 'po_number' => $order->po_number, 'lines' => count($lines)]);

            return $receipt;
        });
    }

    /**
     * @param  array<int|string, array{quantity?: string|null, unit_cost?: string|null}>  $lines
     * @return array<int, array{product_id: int, quantity: string, unit_cost: string}>
     */
    private function prepareLines(array $lines): array
    {
        $prepared = [];
        $errors = [];
        foreach ($lines as $productId => $row) {
            $qty = trim((string) ($row['quantity'] ?? ''));
            if ($qty === '' || (float) $qty == 0.0) {
                continue;
            }
            $cost = trim((string) ($row['unit_cost'] ?? ''));
            if (! preg_match('/^\d+(\.\d{1,3})?$/', $qty) || Quantity::compare($qty, '0') <= 0) {
                $errors["lines.{$productId}.quantity"] = 'Enter a quantity greater than zero (max 3 decimals).';

                continue;
            }
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $cost)) {
                $errors["lines.{$productId}.unit_cost"] = 'Enter a unit cost with at most two decimals.';

                continue;
            }
            if (! Product::whereKey($productId)->where('is_active', true)->exists()) {
                $errors["lines.{$productId}.quantity"] = 'That product is not active.';

                continue;
            }
            $prepared[] = ['product_id' => (int) $productId, 'quantity' => Quantity::fromMilli(Quantity::toMilli($qty)), 'unit_cost' => Money::fromMinor(Money::toMinor($cost))];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($prepared === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product with a quantity.']);
        }

        return $prepared;
    }
}
