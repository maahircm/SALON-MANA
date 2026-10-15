<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\RefundItem;
use App\Models\ServiceConsumable;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Atomic;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of products.current_stock. Every change is one immutable stock_movements row written in
 * the same transaction under a row lock, so the ledger and the balance cannot drift apart.
 */
class InventoryService
{
    public function __construct(private SettingsService $settings, private AuditLogger $audit) {}

    /**
     * Record one stock movement and update the product balance atomically.
     *
     * @param  string  $quantity  signed: positive receives stock, negative removes it
     * @param  string|null  $unitCost  defaults to the product's current average cost
     * @param  string|null  $idempotencyKey  a second call with the same key returns the first movement and changes nothing
     */
    public function record(
        int $productId,
        string $type,
        string $quantity,
        ?User $user,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $unitCost = null,
        ?string $idempotencyKey = null,
        bool $updateAverageCost = false,
    ): StockMovement {
        return Atomic::run(function () use ($productId, $type, $quantity, $user, $reason, $referenceType, $referenceId, $unitCost, $idempotencyKey, $updateAverageCost) {
            if ($idempotencyKey !== null && ($existing = StockMovement::where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }

            $product = Product::withTrashed()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $delta = Quantity::toMilli($quantity);
            if ($delta === 0) {
                throw ValidationException::withMessages(['quantity' => 'The quantity cannot be zero.']);
            }

            $newMilli = Quantity::toMilli($product->current_stock) + $delta;
            if ($newMilli < 0) {
                if (! $this->settings->get('inventory.allow_negative_stock', false)) {
                    throw ValidationException::withMessages(['stock' => "Not enough stock of {$product->name}: {$product->current_stock} {$product->unit} available, ".Quantity::fromMilli(abs($delta)).' needed.']);
                }
                $this->audit->log('inventory.negative_stock', $product, ['current_stock' => (string) $product->current_stock], ['current_stock' => Quantity::fromMilli($newMilli)], $reason);
            }

            $cost = $unitCost !== null ? $unitCost : (string) $product->cost_price;
            if ($updateAverageCost && $delta > 0) {
                $product->cost_price = Quantity::weightedAverageCost((string) $product->current_stock, (string) $product->cost_price, $quantity, $cost);
            }
            $product->current_stock = Quantity::fromMilli($newMilli);
            $product->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => Quantity::fromMilli($delta),
                'balance_after' => $product->current_stock,
                'unit_cost' => $cost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'user_id' => $user?->id,
            ]);
        });
    }

    /**
     * Lock every product a cart will touch, in id order, before anything else is locked. Consistent lock order
     * (products first, then document counters) is what keeps concurrent sales from deadlocking each other.
     *
     * @param  array<int, array<string, mixed>>  $pricedLines
     */
    public function lockForSale(array $pricedLines): void
    {
        $ids = [];
        foreach ($pricedLines as $line) {
            if (! empty($line['product_id'])) {
                $ids[] = (int) $line['product_id'];
            }
            if (! empty($line['service_id'])) {
                foreach (ServiceConsumable::where('service_id', $line['service_id'])->pluck('product_id') as $productId) {
                    $ids[] = (int) $productId;
                }
            }
        }
        if ($ids !== []) {
            Product::withTrashed()->whereIn('id', array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    /**
     * Deduct retail products and service consumables for an issued invoice. Runs inside the checkout
     * transaction, so a failure (e.g. insufficient stock) rolls the whole sale back. Idempotent per line.
     */
    public function deductForInvoice(Invoice $invoice, User $user): void
    {
        $invoice->loadMissing('items');
        $deductions = [];

        foreach ($invoice->items as $item) {
            if ($item->product_id !== null) {
                $deductions[] = ['product' => $item->product_id, 'qty' => (string) $item->quantity, 'type' => StockMovement::SALE, 'item' => $item->id, 'key' => "sale:{$item->id}"];
            }
            if ($item->service_id !== null) {
                foreach (ServiceConsumable::where('service_id', $item->service_id)->get() as $consumable) {
                    $deductions[] = [
                        'product' => $consumable->product_id,
                        'qty' => Quantity::fromMilli(Quantity::toMilli($consumable->quantity) * $item->quantity),
                        'type' => StockMovement::SERVICE_USAGE, 'item' => $item->id, 'key' => "usage:{$item->id}:{$consumable->product_id}",
                    ];
                }
            }
        }
        // Consistent lock order across concurrent sales avoids deadlocks.
        usort($deductions, fn ($a, $b) => [$a['product'], $a['key']] <=> [$b['product'], $b['key']]);

        foreach ($deductions as $d) {
            $this->record($d['product'], $d['type'], '-'.$d['qty'], $user, "Invoice {$invoice->invoice_number}", 'invoice_item', $d['item'], null, $d['key']);
        }
    }

    /**
     * Put stock back for a voided invoice: everything that left for it and has not already been returned.
     */
    public function reverseForInvoice(Invoice $invoice, User $user): void
    {
        $itemIds = $invoice->items()->pluck('id');
        $out = StockMovement::where('reference_type', 'invoice_item')->whereIn('reference_id', $itemIds)
            ->whereIn('type', [StockMovement::SALE, StockMovement::SERVICE_USAGE])->orderBy('id')->get();

        foreach ($out as $movement) {
            $returned = Quantity::add(...StockMovement::where('reference_type', 'invoice_item')->where('reference_id', $movement->reference_id)
                ->where('product_id', $movement->product_id)->where('type', StockMovement::SALE_RETURN)->pluck('quantity')->all());
            $remaining = Quantity::sub(ltrim((string) $movement->quantity, '-'), $returned);
            if (Quantity::compare($remaining, '0') > 0) {
                $this->record($movement->product_id, StockMovement::VOID_REVERSAL, $remaining, $user, "Void of invoice {$invoice->invoice_number}", 'invoice_item',
                    $movement->reference_id, (string) $movement->unit_cost, "void:{$movement->id}");
            }
        }
    }

    /**
     * Return refunded retail goods to stock at the cost they left it.
     */
    public function restockRefundedItem(InvoiceItem $item, RefundItem $refundItem, User $user): void
    {
        if ($item->product_id === null) {
            return;
        }
        $sale = StockMovement::where('idempotency_key', "sale:{$item->id}")->first();
        $this->record($item->product_id, StockMovement::SALE_RETURN, (string) $refundItem->quantity, $user, 'Customer return', 'invoice_item', $item->id,
            $sale ? (string) $sale->unit_cost : null, "return:{$refundItem->id}");
    }

    /**
     * Compare every product balance with the sum of its ledger.
     *
     * @return array<int, array{product_id: int, sku: string, balance: string, ledger: string}>
     */
    public function reconcile(): array
    {
        $ledger = StockMovement::query()->select('product_id', DB::raw('SUM(quantity) as total'))->groupBy('product_id')->pluck('total', 'product_id');
        $mismatches = [];
        Product::withTrashed()->orderBy('id')->each(function (Product $product) use ($ledger, &$mismatches): void {
            $sum = Quantity::fromMilli(Quantity::toMilli((string) ($ledger[$product->id] ?? '0')));
            if (Quantity::compare($sum, $product->current_stock) !== 0) {
                $mismatches[] = ['product_id' => $product->id, 'sku' => $product->sku, 'balance' => (string) $product->current_stock, 'ledger' => $sum];
            }
        });

        return $mismatches;
    }
}
