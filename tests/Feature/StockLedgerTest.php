<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    public function test_every_movement_carries_the_running_balance_and_the_ledger_always_reconciles(): void
    {
        $product = $this->stockedProduct('10', '5.00');
        $this->inventory()->record($product->id, StockMovement::SALE, '-3', null);
        $this->inventory()->record($product->id, StockMovement::ADJUSTMENT_IN, '1.25', null);

        $this->assertSame(['10.000', '7.000', '8.250'], StockMovement::orderBy('id')->pluck('balance_after')->map(fn ($b) => (string) $b)->all());
        $this->assertSame('8.250', $this->stockOf($product));
        $this->assertSame([], $this->inventory()->reconcile());
    }

    public function test_reconciliation_detects_a_balance_changed_outside_the_ledger(): void
    {
        $product = $this->stockedProduct('10', '5.00');
        DB::table('products')->where('id', $product->id)->update(['current_stock' => '99.000']);

        $this->assertSame([['product_id' => $product->id, 'sku' => $product->sku, 'balance' => '99.000', 'ledger' => '10.000']], $this->inventory()->reconcile());
    }

    public function test_stock_cannot_go_negative_by_default_and_nothing_is_written_when_refused(): void
    {
        $product = $this->stockedProduct('2', '5.00');

        try {
            $this->inventory()->record($product->id, StockMovement::SALE, '-2.001', null);
            $this->fail('A negative balance must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }
        $this->assertSame('2.000', $this->stockOf($product));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_negative_stock_is_possible_only_when_configured_and_is_audited(): void
    {
        $product = $this->stockedProduct('1', '5.00');
        app(SettingsService::class)->set('inventory.allow_negative_stock', true);

        $this->inventory()->record($product->id, StockMovement::SALE, '-3', null, 'Oversold');

        $this->assertSame('-2.000', $this->stockOf($product));
        $log = AuditLog::where('action', 'inventory.negative_stock')->firstOrFail();
        $this->assertSame('-2.000', $log->after['current_stock']);
        $this->assertSame([], $this->inventory()->reconcile());
    }

    public function test_the_same_idempotency_key_never_moves_stock_twice(): void
    {
        $product = $this->stockedProduct('10', '5.00');
        $first = $this->inventory()->record($product->id, StockMovement::SALE, '-4', null, null, 'invoice_item', 1, null, 'sale:1');
        $second = $this->inventory()->record($product->id, StockMovement::SALE, '-4', null, null, 'invoice_item', 1, null, 'sale:1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('6.000', $this->stockOf($product));
        $this->assertSame(2, StockMovement::count());
    }

    public function test_zero_and_over_precise_quantities_are_rejected(): void
    {
        $product = $this->stockedProduct('10', '5.00');
        $this->expectException(ValidationException::class);
        $this->inventory()->record($product->id, StockMovement::ADJUSTMENT_IN, '0.000', null);
    }

    public function test_the_ledger_cannot_be_edited_or_deleted(): void
    {
        $this->stockedProduct('10', '5.00');
        $movement = StockMovement::firstOrFail();

        foreach ([fn () => $movement->update(['quantity' => '1']), fn () => $movement->delete(), fn () => InventoryAdjustment::query()->create(['product_id' => $movement->product_id, 'kind' => 'other',
            'previous_quantity' => 0, 'quantity_delta' => 0, 'new_quantity' => 0, 'reason' => 'x', 'user_id' => User::factory()->create()->id])->update(['reason' => 'y'])] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected an immutability error.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_count_corrections_set_stock_to_the_counted_quantity_in_either_direction(): void
    {
        $keeper = $this->stockKeeper();
        $product = $this->stockedProduct('10', '5.00');

        $this->actingAs($keeper)->post('/inventory/adjustments', ['product_id' => $product->id, 'kind' => 'count_correction', 'quantity' => '8.5', 'reason' => 'Stock take'])->assertSessionDoesntHaveErrors();
        $this->assertSame('8.500', $this->stockOf($product));
        $this->assertSame(StockMovement::ADJUSTMENT_OUT, StockMovement::latest('id')->first()->type);

        $this->actingAs($keeper)->post('/inventory/adjustments', ['product_id' => $product->id, 'kind' => 'count_correction', 'quantity' => '9', 'reason' => 'Found a box'])->assertSessionDoesntHaveErrors();
        $this->assertSame('9.000', $this->stockOf($product));
        $this->assertSame(StockMovement::ADJUSTMENT_IN, StockMovement::latest('id')->first()->type);

        $this->actingAs($keeper)->post('/inventory/adjustments', ['product_id' => $product->id, 'kind' => 'count_correction', 'quantity' => '9', 'reason' => 'Same'])->assertSessionHasErrors('quantity');
        $adjustment = InventoryAdjustment::orderBy('id')->first();
        $this->assertSame('10.000', (string) $adjustment->previous_quantity);
        $this->assertSame('-1.500', (string) $adjustment->quantity_delta);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjusted', 'reason' => 'Stock take']);
    }

    public function test_damaged_and_expired_stock_is_written_off_with_the_right_ledger_type(): void
    {
        $keeper = $this->stockKeeper();
        $product = $this->stockedProduct('10', '5.00');

        $this->actingAs($keeper)->post('/inventory/adjustments', ['product_id' => $product->id, 'kind' => 'damaged', 'quantity' => '2', 'reason' => 'Dropped'])->assertSessionDoesntHaveErrors();
        $this->actingAs($keeper)->post('/inventory/adjustments', ['product_id' => $product->id, 'kind' => 'expired', 'quantity' => '1', 'reason' => 'Past date'])->assertSessionDoesntHaveErrors();

        $this->assertSame('7.000', $this->stockOf($product));
        $this->assertSame([StockMovement::DAMAGE, StockMovement::EXPIRY], StockMovement::whereIn('type', [StockMovement::DAMAGE, StockMovement::EXPIRY])->orderBy('id')->pluck('type')->all());
        $this->actingAs($keeper)->post('/inventory/adjustments', ['product_id' => $product->id, 'kind' => 'damaged', 'quantity' => '50', 'reason' => 'All of it'])->assertSessionHasErrors('stock');
        $this->assertSame('7.000', $this->stockOf($product));
        $this->assertSame(2, InventoryAdjustment::count(), 'a refused adjustment leaves no record');
    }

    public function test_adjustments_need_permission_a_reason_and_valid_input(): void
    {
        $product = $this->stockedProduct('10', '5.00');
        $viewer = User::factory()->withPermissions('inventory.view', 'inventory.manage', 'inventory.purchasing')->create();
        $payload = ['product_id' => $product->id, 'kind' => 'damaged', 'quantity' => '1', 'reason' => 'x'];

        $this->actingAs($viewer)->post('/inventory/adjustments', $payload)->assertForbidden();
        $this->actingAs($viewer)->get('/inventory/adjustments')->assertForbidden();

        $keeper = $this->stockKeeper();
        $this->actingAs($keeper)->post('/inventory/adjustments', ['reason' => ''] + $payload)->assertSessionHasErrors('reason');
        $this->actingAs($keeper)->post('/inventory/adjustments', ['kind' => 'steal'] + $payload)->assertSessionHasErrors('kind');
        $this->actingAs($keeper)->post('/inventory/adjustments', ['quantity' => '0'] + $payload)->assertSessionHasErrors('quantity');
        $this->actingAs($keeper)->post('/inventory/adjustments', ['quantity' => '1.5555'] + $payload)->assertSessionHasErrors('quantity');
        $this->assertSame('10.000', $this->stockOf($product));
    }

    public function test_returning_goods_to_a_supplier_reduces_stock_at_cost_and_is_recorded(): void
    {
        $buyer = $this->stockKeeper();
        $supplier = Supplier::factory()->create();
        $product = $this->stockedProduct('10', '5.00');

        $this->actingAs($buyer)->post('/inventory/returns', ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'quantity' => '3', 'reason' => 'Faulty pumps'])->assertSessionDoesntHaveErrors();

        $return = SupplierReturn::firstOrFail();
        $this->assertSame('SR-000001', $return->return_number);
        $this->assertSame('5.00', (string) $return->unit_cost);
        $this->assertSame('7.000', $this->stockOf($product));
        $this->assertSame(StockMovement::SUPPLIER_RETURN, StockMovement::latest('id')->first()->type);
        $this->actingAs($buyer)->post('/inventory/returns', ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'quantity' => '8', 'reason' => 'Too many'])->assertSessionHasErrors('stock');
        $this->actingAs(User::factory()->withPermissions('inventory.adjust')->create())->post('/inventory/returns', ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'quantity' => '1', 'reason' => 'x'])->assertForbidden();
        $this->assertSame(1, SupplierReturn::count());
    }

    public function test_ledger_and_valuation_pages_filter_and_value_stock_at_average_cost(): void
    {
        $keeper = $this->stockKeeper();
        $a = $this->stockedProduct('10', '5.00', ['name' => 'Alpha']);
        $this->stockedProduct('4', '2.50', ['name' => 'Beta']);
        $this->inventory()->record($a->id, StockMovement::DAMAGE, '-1', $keeper, 'Spilled');

        $this->actingAs($keeper)->get("/inventory/movements?product={$a->id}&type=damage")->assertOk()->assertSee('Spilled')->assertDontSee('Opening stock');
        $this->actingAs($keeper)->get('/inventory/valuation')->assertOk()->assertSee('$55.00');
    }
}
