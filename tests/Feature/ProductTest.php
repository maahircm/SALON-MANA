<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use Concerns, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['sku' => 'SH-250', 'name' => 'Shampoo 250ml', 'unit' => 'pc', 'selling_price' => '12.50', 'tax_rate' => '5', 'reorder_level' => '4', 'is_sellable' => 1, 'is_active' => 1], $overrides);
    }

    public function test_a_stock_keeper_creates_a_product_with_opening_stock_recorded_in_the_ledger(): void
    {
        $keeper = $this->stockKeeper();
        $this->actingAs($keeper)->post('/inventory/products', $this->payload(['opening_quantity' => '12', 'opening_cost' => '6.50']))->assertRedirect();

        $product = Product::firstOrFail();
        $this->assertSame('12.000', (string) $product->current_stock);
        $this->assertSame('6.50', (string) $product->cost_price);
        $movement = StockMovement::firstOrFail();
        $this->assertSame(StockMovement::ADJUSTMENT_IN, $movement->type);
        $this->assertSame('12.000', (string) $movement->balance_after);
        $this->assertSame('Opening stock', $movement->reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'user_id' => $keeper->id]);
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_opening_stock_requires_the_adjust_permission_and_nothing_is_created_without_it(): void
    {
        $manager = User::factory()->withPermissions('inventory.view', 'inventory.manage')->create();
        $this->actingAs($manager)->post('/inventory/products', $this->payload(['opening_quantity' => '5', 'opening_cost' => '1']))->assertForbidden();
        $this->assertSame(0, Product::count());

        $this->actingAs($manager)->post('/inventory/products', $this->payload())->assertRedirect();
        $this->assertSame('0.000', (string) Product::firstOrFail()->current_stock);
    }

    public function test_stock_and_cost_cannot_be_set_through_the_product_form(): void
    {
        $keeper = $this->stockKeeper();
        $this->actingAs($keeper)->post('/inventory/products', $this->payload(['current_stock' => '999', 'cost_price' => '1.00']))->assertRedirect();
        $product = Product::firstOrFail();
        $this->assertSame('0.000', (string) $product->current_stock);
        $this->assertSame('0.00', (string) $product->cost_price);

        $stocked = $this->stockedProduct('10', '5.00');
        $this->actingAs($keeper)->put("/inventory/products/{$stocked->id}", $this->payload(['sku' => $stocked->sku, 'current_stock' => '1', 'cost_price' => '99']))->assertRedirect();
        $this->assertSame('10.000', $this->stockOf($stocked));
        $this->assertSame('5.00', (string) $stocked->fresh()->cost_price);
    }

    public function test_sku_and_barcode_must_be_unique_and_well_formed(): void
    {
        $keeper = $this->stockKeeper();
        Product::factory()->create(['sku' => 'SH-250', 'barcode' => '5901234123457']);

        $this->actingAs($keeper)->post('/inventory/products', $this->payload())->assertSessionHasErrors('sku');
        $this->actingAs($keeper)->post('/inventory/products', $this->payload(['sku' => 'NEW-1', 'barcode' => '5901234123457']))->assertSessionHasErrors('barcode');
        $this->actingAs($keeper)->post('/inventory/products', $this->payload(['sku' => 'bad sku!']))->assertSessionHasErrors('sku');
        $this->actingAs($keeper)->post('/inventory/products', $this->payload(['sku' => 'NEW-2', 'selling_price' => '-1', 'reorder_level' => 'x', 'unit' => 'bucket']))->assertSessionHasErrors(['selling_price', 'reorder_level', 'unit']);
        $this->actingAs($keeper)->from('/inventory/products/create')->post('/inventory/products', $this->payload(['sku' => '', 'name' => 'Keep me']))->assertSessionHasInput('name', 'Keep me');
        $this->assertSame(1, Product::count());
    }

    public function test_price_changes_are_audited_and_do_not_touch_stock(): void
    {
        $keeper = $this->stockKeeper();
        $product = $this->stockedProduct('10', '5.00', ['sku' => 'P-1', 'selling_price' => '10.00']);

        $this->actingAs($keeper)->put("/inventory/products/{$product->id}", $this->payload(['sku' => 'P-1', 'selling_price' => '12.00']))->assertRedirect();

        $log = AuditLog::where('action', 'product.price_changed')->firstOrFail();
        $this->assertSame('10.00', $log->before['selling_price']);
        $this->assertSame('12.00', $log->after['selling_price']);
        $this->assertSame('10.000', $this->stockOf($product));
    }

    public function test_access_is_limited_by_permission_and_costs_are_hidden_without_the_costs_permission(): void
    {
        $product = $this->stockedProduct('10', '5.25');
        $nobody = User::factory()->create();
        $viewer = User::factory()->withPermissions('inventory.view')->create();

        $this->actingAs($nobody)->get('/inventory/products')->assertForbidden();
        $this->actingAs($viewer)->get('/inventory/products')->assertOk()->assertDontSee('$5.25');
        $this->actingAs($viewer)->get("/inventory/products/{$product->id}")->assertOk()->assertDontSee('Average cost')->assertDontSee('$5.25');
        $this->actingAs($viewer)->get('/inventory/products/create')->assertForbidden();
        $this->actingAs($viewer)->post('/inventory/products', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->get('/inventory/valuation')->assertForbidden();
        $this->actingAs($viewer)->get('/inventory/movements')->assertOk()->assertDontSee('Unit cost');

        $this->actingAs($this->stockKeeper())->get("/inventory/products/{$product->id}")->assertSee('Average cost')->assertSee('$5.25');
        $this->actingAs($this->stockKeeper())->get('/inventory/valuation')->assertOk()->assertSee('$52.50');
    }

    public function test_search_and_stock_filters(): void
    {
        $keeper = $this->stockKeeper();
        $low = $this->stockedProduct('2', '1.00', ['name' => 'Running Low Conditioner', 'reorder_level' => '5', 'sku' => 'LOW-1']);
        $this->stockedProduct('50', '1.00', ['name' => 'Plentiful Gel', 'reorder_level' => '5', 'barcode' => '4006381333931', 'sku' => 'OK-1']);
        $expiring = Product::factory()->create(['name' => 'Soon Expiring Oil', 'expiry_date' => now()->addDays(10)->toDateString(), 'sku' => 'EXP-1']);
        Product::factory()->create(['name' => 'Retired Brush', 'is_active' => false, 'sku' => 'OLD-1']);

        $this->actingAs($keeper)->get('/inventory/products?status=low')->assertSee($low->name)->assertDontSee('Plentiful Gel');
        $this->actingAs($keeper)->get('/inventory/products?q=4006381333931')->assertSee('Plentiful Gel')->assertDontSee('Running Low');
        $this->actingAs($keeper)->get('/inventory/products?status=expiring')->assertSee($expiring->name)->assertDontSee('Plentiful Gel');
        $this->actingAs($keeper)->get('/inventory/products?status=inactive')->assertSee('Retired Brush')->assertDontSee('Plentiful Gel');
        $this->assertTrue($low->isLowStock());
    }

    public function test_products_with_history_cannot_be_deleted_but_fresh_ones_can(): void
    {
        $keeper = $this->stockKeeper();
        $used = $this->stockedProduct();
        $fresh = Product::factory()->create();

        $this->actingAs($keeper)->delete("/inventory/products/{$used->id}")->assertSessionHasErrors('product');
        $this->assertNotSoftDeleted($used);
        $this->actingAs($keeper)->delete("/inventory/products/{$fresh->id}")->assertRedirect('/inventory/products');
        $this->assertSoftDeleted($fresh);
    }

    public function test_categories_can_be_managed_but_not_deleted_while_in_use(): void
    {
        $keeper = $this->stockKeeper();
        $this->actingAs($keeper)->post('/inventory/categories', ['name' => 'Hair care'])->assertSessionDoesntHaveErrors();
        $this->actingAs($keeper)->post('/inventory/categories', ['name' => 'Hair care'])->assertSessionHasErrors('name');
        $category = ProductCategory::firstOrFail();
        Product::factory()->create(['product_category_id' => $category->id]);

        $this->actingAs($keeper)->delete("/inventory/categories/{$category->id}")->assertSessionHasErrors('category');
        $this->actingAs(User::factory()->withPermissions('inventory.view')->create())->post('/inventory/categories', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_suppliers_are_managed_with_permission_and_visible_to_purchasers(): void
    {
        $keeper = $this->stockKeeper();
        $this->actingAs($keeper)->post('/inventory/suppliers', ['name' => 'Beauty Wholesale', 'email' => 'orders@bw.example', 'phone' => '+252615550000'])->assertRedirect();
        $this->actingAs($keeper)->post('/inventory/suppliers', ['name' => 'Beauty Wholesale'])->assertSessionHasErrors('name');
        $this->actingAs($keeper)->post('/inventory/suppliers', ['name' => 'Bad', 'email' => 'nope'])->assertSessionHasErrors('email');

        $purchaser = User::factory()->withPermissions('inventory.purchasing')->create();
        $this->actingAs($purchaser)->get('/inventory/suppliers')->assertOk()->assertSee('Beauty Wholesale');
        $this->actingAs($purchaser)->get('/inventory/suppliers/create')->assertForbidden();
        $this->actingAs(User::factory()->withPermissions('inventory.view')->create())->get('/inventory/suppliers')->assertForbidden();
    }

    public function test_inventory_settings_are_protected_validated_and_audited(): void
    {
        $this->actingAs($this->stockKeeper())->get('/settings/inventory')->assertForbidden();
        $owner = User::factory()->withPermissions('settings.manage')->create();
        $this->actingAs($owner)->get('/settings/inventory')->assertOk();
        $this->actingAs($owner)->put('/settings/inventory', ['expiry_alert_days' => 0, 'purchase_order_prefix' => 'P O', 'goods_receipt_prefix' => 'GR-', 'supplier_return_prefix' => 'SR-'])->assertSessionHasErrors(['expiry_alert_days', 'purchase_order_prefix']);
        $this->actingAs($owner)->put('/settings/inventory', ['allow_negative_stock' => 1, 'expiry_alert_days' => 14, 'purchase_order_prefix' => 'ORD-', 'goods_receipt_prefix' => 'REC-', 'supplier_return_prefix' => 'RET-'])->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.inventory_updated']);
        $this->assertTrue(app(SettingsService::class)->get('inventory.allow_negative_stock'));
    }
}
