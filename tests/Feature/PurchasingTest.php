<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $buyer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = $this->stockKeeper();
        $this->supplier = Supplier::factory()->create();
    }

    /** Draft order for the given product => [quantity, unit cost] lines, placed unless $place is false. */
    private function order(array $lines, bool $place = true): PurchaseOrder
    {
        $payload = ['supplier_id' => $this->supplier->id, 'lines' => collect($lines)->map(fn ($l) => ['quantity' => $l[0], 'unit_cost' => $l[1]])->all()];
        $this->actingAs($this->buyer)->post('/inventory/purchase-orders', $payload)->assertSessionDoesntHaveErrors();
        $order = PurchaseOrder::latest('id')->firstOrFail();
        if ($place) {
            $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/place")->assertSessionDoesntHaveErrors();
        }

        return $order->fresh();
    }

    /** @return array<string, mixed> */
    private function receiving(PurchaseOrder $order, array $quantities, array $extra = []): array
    {
        $items = [];
        foreach ($order->items as $item) {
            $items[$item->id] = ['quantity' => $quantities[$item->product_id] ?? ''] + ($extra['expiry'][$item->product_id] ?? false ? ['expiry_date' => $extra['expiry'][$item->product_id]] : []);
        }

        return ['items' => $items, 'supplier_reference' => $extra['ref'] ?? null];
    }

    public function test_a_draft_order_is_totalled_numbered_when_placed_and_then_locked(): void
    {
        $shampoo = Product::factory()->create();
        $oil = Product::factory()->create();
        $order = $this->order([$shampoo->id => ['10', '4.50'], $oil->id => ['2.5', '8.00']], place: false);

        $this->assertSame('draft', $order->status);
        $this->assertNull($order->po_number);
        $this->assertSame('65.00', (string) $order->total);

        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/place")->assertRedirect();
        $order->refresh();
        $this->assertSame('ordered', $order->status);
        $this->assertSame('PO-000001', $order->po_number);
        $this->actingAs($this->buyer)->get("/inventory/purchase-orders/{$order->id}/edit")->assertForbidden();
        $this->actingAs($this->buyer)->put("/inventory/purchase-orders/{$order->id}", ['supplier_id' => $this->supplier->id, 'lines' => [$shampoo->id => ['quantity' => '1', 'unit_cost' => '1']]])->assertSessionHasErrors('order');
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/place")->assertSessionHasErrors('order');
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_order.placed']);
    }

    public function test_draft_validation(): void
    {
        $product = Product::factory()->create();
        $inactive = Product::factory()->create(['is_active' => false]);
        $url = '/inventory/purchase-orders';

        $this->actingAs($this->buyer)->post($url, ['supplier_id' => $this->supplier->id, 'lines' => [$product->id => ['quantity' => '', 'unit_cost' => '']]])->assertSessionHasErrors('lines');
        $this->actingAs($this->buyer)->post($url, ['supplier_id' => $this->supplier->id, 'lines' => [$product->id => ['quantity' => '1.2345', 'unit_cost' => '1']]])->assertSessionHasErrors("lines.{$product->id}.quantity");
        $this->actingAs($this->buyer)->post($url, ['supplier_id' => $this->supplier->id, 'lines' => [$product->id => ['quantity' => '1', 'unit_cost' => '1.999']]])->assertSessionHasErrors("lines.{$product->id}.unit_cost");
        $this->actingAs($this->buyer)->post($url, ['supplier_id' => $this->supplier->id, 'lines' => [$inactive->id => ['quantity' => '1', 'unit_cost' => '1']]])->assertSessionHasErrors("lines.{$inactive->id}.quantity");
        $this->actingAs($this->buyer)->post($url, ['supplier_id' => 9999, 'lines' => [$product->id => ['quantity' => '1', 'unit_cost' => '1']]])->assertSessionHasErrors('supplier_id');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_partial_then_final_receipt_updates_stock_ledger_average_cost_and_status(): void
    {
        $product = $this->stockedProduct('10', '5.00');
        $order = $this->order([$product->id => ['10', '7.00']]);

        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/receive", $this->receiving($order, [$product->id => '4'], ['ref' => 'DN-77']))->assertSessionDoesntHaveErrors();
        $product->refresh();
        $this->assertSame('14.000', (string) $product->current_stock);
        $this->assertSame('5.57', (string) $product->cost_price, '(10 x 5.00 + 4 x 7.00) / 14');
        $this->assertSame('partially_received', $order->fresh()->status);
        $movement = StockMovement::where('type', StockMovement::PURCHASE_RECEIPT)->firstOrFail();
        $this->assertSame('4.000', (string) $movement->quantity);
        $this->assertSame('14.000', (string) $movement->balance_after);
        $this->assertSame('7.00', (string) $movement->unit_cost);
        $receipt = GoodsReceipt::firstOrFail();
        $this->assertSame('GR-000001', $receipt->receipt_number);
        $this->assertSame('DN-77', $receipt->supplier_reference);

        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/receive", $this->receiving($order->fresh(), [$product->id => '6']))->assertSessionDoesntHaveErrors();
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame('20.000', $this->stockOf($product));
        $this->assertSame('6.00', (string) $product->fresh()->cost_price);
        $this->assertSame([], app(InventoryService::class)->reconcile());
        $this->assertDatabaseHas('audit_logs', ['action' => 'goods_receipt.created']);
    }

    public function test_receiving_more_than_ordered_nothing_or_against_the_wrong_state_is_rejected(): void
    {
        $product = Product::factory()->create();
        $order = $this->order([$product->id => ['5', '2.00']]);
        $url = "/inventory/purchase-orders/{$order->id}/receive";

        $this->actingAs($this->buyer)->post($url, $this->receiving($order, [$product->id => '5.001']))->assertSessionHasErrors();
        $this->actingAs($this->buyer)->post($url, $this->receiving($order, [$product->id => '']))->assertSessionHasErrors('items');
        $this->actingAs($this->buyer)->post($url, $this->receiving($order, [$product->id => '1.2345']))->assertSessionHasErrors();
        $this->assertSame('0.000', $this->stockOf($product));
        $this->assertSame(0, GoodsReceipt::count());

        $draft = $this->order([$product->id => ['1', '1.00']], place: false);
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$draft->id}/receive", $this->receiving($draft, [$product->id => '1']))->assertSessionHasErrors('order');
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/cancel", ['reason' => 'Supplier out of stock']);
        $this->actingAs($this->buyer)->post($url, $this->receiving($order, [$product->id => '1']))->assertSessionHasErrors('order');
        $this->assertSame('0.000', $this->stockOf($product));
    }

    public function test_receiving_more_than_ordered_leaves_no_partial_receipt_behind(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $order = $this->order([$a->id => ['5', '1.00'], $b->id => ['5', '1.00']]);

        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/receive", $this->receiving($order, [$a->id => '5', $b->id => '6']))->assertSessionHasErrors();

        $this->assertSame('0.000', $this->stockOf($a));
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_fractional_quantities_and_expiry_dates_are_supported(): void
    {
        $dye = Product::factory()->consumable()->create(['unit' => 'l']);
        $order = $this->order([$dye->id => ['2.5', '12.40']]);
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/receive", $this->receiving($order, [$dye->id => '2.5'], ['expiry' => [$dye->id => '2027-03-01']]))->assertSessionDoesntHaveErrors();

        $dye->refresh();
        $this->assertSame('2.500', (string) $dye->current_stock);
        $this->assertSame('2027-03-01', $dye->expiry_date->toDateString());
        $this->assertSame('31.00', (string) $order->fresh()->total);
    }

    public function test_cancel_and_close_short_rules(): void
    {
        $product = Product::factory()->create();
        $order = $this->order([$product->id => ['10', '1.00']]);

        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/cancel", [])->assertSessionHasErrors('reason');
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/close", ['reason' => 'x'])->assertSessionHasErrors('order');

        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/receive", $this->receiving($order, [$product->id => '4']));
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/cancel", ['reason' => 'x'])->assertSessionHasErrors('order');
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/close", ['reason' => 'Supplier discontinued the rest'])->assertSessionDoesntHaveErrors();
        $this->assertSame('received', $order->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_order.closed_short', 'reason' => 'Supplier discontinued the rest']);

        $another = $this->order([$product->id => ['1', '1.00']]);
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$another->id}/cancel", ['reason' => 'Changed mind'])->assertSessionDoesntHaveErrors();
        $this->assertSame('cancelled', $another->fresh()->status);
    }

    public function test_purchasing_requires_the_purchasing_permission(): void
    {
        $product = Product::factory()->create();
        $order = $this->order([$product->id => ['5', '1.00']]);
        $viewer = User::factory()->withPermissions('inventory.view', 'inventory.suppliers', 'inventory.adjust')->create();

        $this->actingAs($viewer)->get('/inventory/purchase-orders')->assertForbidden();
        $this->actingAs($viewer)->get("/inventory/purchase-orders/{$order->id}")->assertForbidden();
        $this->actingAs($viewer)->post('/inventory/purchase-orders', ['supplier_id' => $this->supplier->id, 'lines' => [$product->id => ['quantity' => '1', 'unit_cost' => '1']]])->assertForbidden();
        $this->actingAs($viewer)->post("/inventory/purchase-orders/{$order->id}/receive", $this->receiving($order, [$product->id => '5']))->assertForbidden();
        $this->actingAs($viewer)->post("/inventory/purchase-orders/{$order->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->assertSame('0.000', $this->stockOf($product));
    }

    public function test_goods_receipts_and_ledger_lines_are_immutable(): void
    {
        $product = Product::factory()->create();
        $order = $this->order([$product->id => ['5', '1.00']]);
        $this->actingAs($this->buyer)->post("/inventory/purchase-orders/{$order->id}/receive", $this->receiving($order, [$product->id => '5']));

        foreach ([fn () => GoodsReceipt::firstOrFail()->update(['notes' => 'x']), fn () => GoodsReceipt::firstOrFail()->delete(),
            fn () => GoodsReceiptItem::firstOrFail()->update(['quantity' => 99]), fn () => StockMovement::firstOrFail()->update(['quantity' => 99])] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected an immutability error.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
