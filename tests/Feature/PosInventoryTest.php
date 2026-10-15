<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ServiceConsumable;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns;
use Tests\TestCase;

class PosInventoryTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = $this->cashier();
        $this->manager = $this->manager();
        $this->openDrawer($this->cashier);
        $this->openDrawer($this->manager, '0.00');
    }

    /** @return array<string, mixed> */
    private function productLine(Product $product, int $qty = 1): array
    {
        return ['product_id' => $product->id, 'quantity' => $qty];
    }

    private function refund(Invoice $invoice, array $quantities, array $restock = [], string $method = 'cash'): void
    {
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod($method)->id,
            'reason' => 'Customer return', 'quantities' => $quantities, 'restock' => $restock])->assertSessionDoesntHaveErrors();
    }

    public function test_selling_a_product_deducts_stock_in_the_same_transaction_and_records_cost_and_tax(): void
    {
        $product = $this->stockedProduct('10', '6.00', ['selling_price' => '15.00', 'tax_rate' => '10.00']);

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([$this->productLine($product, 3)], [$this->cashPayment('49.50')]))->assertSessionDoesntHaveErrors()->assertRedirect();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('49.50', (string) $invoice->total);
        $this->assertSame('product', $invoice->items->first()->item_type);
        $this->assertSame($product->id, $invoice->items->first()->product_id);
        $this->assertSame('7.000', $this->stockOf($product));
        $sale = StockMovement::where('type', StockMovement::SALE)->firstOrFail();
        $this->assertSame('-3.000', (string) $sale->quantity);
        $this->assertSame('6.00', (string) $sale->unit_cost, 'cost at the moment of sale is kept for profit reporting');
        $this->assertSame('invoice_item', $sale->reference_type);
        $this->assertSame($invoice->items->first()->id, $sale->reference_id);
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_insufficient_stock_rolls_the_entire_sale_back(): void
    {
        $scarce = $this->stockedProduct('2', '6.00', ['selling_price' => '15.00']);
        $plenty = $this->stockedProduct('10', '1.00', ['selling_price' => '5.00']);
        $service = $this->service(60, '30.00');

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['service_id' => $service->id, 'quantity' => 1], $this->productLine($plenty, 2), $this->productLine($scarce, 3)], [$this->cashPayment('85.00')]))
            ->assertSessionHasErrors('stock');

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame('2.000', $this->stockOf($scarce));
        $this->assertSame('10.000', $this->stockOf($plenty), 'stock deducted earlier in the same request is rolled back too');
        $this->assertSame(0, StockMovement::where('type', StockMovement::SALE)->count());
        $this->assertSame(0, (int) DB::table('document_sequences')->where('key', 'invoice')->value('last_number'), 'a rolled-back sale must not consume an invoice number');
    }

    public function test_service_consumables_are_deducted_per_service_quantity_and_block_the_sale_when_short(): void
    {
        $dye = $this->stockedProduct('100', '0.05', ['unit' => 'ml', 'is_sellable' => false]);
        $service = $this->service(60, '40.00');
        ServiceConsumable::create(['service_id' => $service->id, 'product_id' => $dye->id, 'quantity' => '30.000']);

        $this->issueInvoice($this->cashier, [['service_id' => $service->id, 'quantity' => 2]], [$this->cashPayment('80.00')]);
        $this->assertSame('40.000', $this->stockOf($dye));
        $usage = StockMovement::where('type', StockMovement::SERVICE_USAGE)->firstOrFail();
        $this->assertSame('-60.000', (string) $usage->quantity);
        $this->assertSame('0.05', (string) $usage->unit_cost);

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['service_id' => $service->id, 'quantity' => 2]], [$this->cashPayment('80.00')]))->assertSessionHasErrors('stock');
        $this->assertSame(1, Invoice::count());
        $this->assertSame('40.000', $this->stockOf($dye));
    }

    public function test_a_repeated_checkout_or_repeated_deduction_never_removes_stock_twice(): void
    {
        $product = $this->stockedProduct('10', '6.00', ['selling_price' => '15.00']);
        $payload = $this->checkoutPayload([$this->productLine($product, 2)], [$this->cashPayment('30.00')]);

        $this->actingAs($this->cashier)->post('/pos', $payload);
        $this->actingAs($this->cashier)->post('/pos', $payload)->assertSessionHas('success');
        $this->assertSame('8.000', $this->stockOf($product));

        $invoice = Invoice::firstOrFail();
        app(InventoryService::class)->deductForInvoice($invoice, $this->cashier);
        app(InventoryService::class)->deductForInvoice($invoice, $this->cashier);
        $this->assertSame('8.000', $this->stockOf($product));
        $this->assertSame(1, StockMovement::where('type', StockMovement::SALE)->count());
    }

    public function test_only_active_sellable_products_can_be_sold(): void
    {
        $inactive = $this->stockedProduct('5', '1.00', ['is_active' => false]);
        $consumable = $this->stockedProduct('5', '1.00', ['is_sellable' => false]);

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([$this->productLine($inactive)]))->assertSessionHasErrors('items.0.product_id');
        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([$this->productLine($consumable)]))->assertSessionHasErrors('items.0.product_id');
        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([['product_id' => 999999, 'quantity' => 1]]))->assertSessionHasErrors('items.0.product_id');
        $this->assertSame(0, Invoice::count());
    }

    public function test_drafts_hold_products_without_touching_stock_until_issued(): void
    {
        $product = $this->stockedProduct('5', '2.00', ['selling_price' => '10.00']);

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([$this->productLine($product, 2)], [], ['action' => 'save_draft']))->assertRedirect();
        $draft = Invoice::firstOrFail();
        $this->assertSame('5.000', $this->stockOf($product));
        $this->actingAs($this->cashier)->get("/pos?draft={$draft->id}")->assertOk()->assertSee($product->name);

        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([$this->productLine($product, 2)], [$this->cashPayment('20.00')], ['draft_id' => $draft->id]))->assertSessionDoesntHaveErrors();
        $this->assertSame('3.000', $this->stockOf($product));
    }

    public function test_a_refund_returns_goods_to_stock_at_the_original_cost_only_when_asked(): void
    {
        $product = $this->stockedProduct('10', '6.00', ['selling_price' => '15.00']);
        $invoice = $this->issueInvoice($this->cashier, [$this->productLine($product, 3)], [$this->cashPayment('45.00')]);
        $itemId = $invoice->items->first()->id;
        // average cost moves after the sale; the return must use the cost the goods left at
        app(InventoryService::class)->record($product->id, StockMovement::PURCHASE_RECEIPT, '10', null, null, null, null, '10.00', 'receipt:test', true);

        $this->refund($invoice, [$itemId => 1], [$itemId => 0]);
        $this->assertSame('17.000', $this->stockOf($product), 'a refund without "return to stock" leaves stock alone');

        $this->refund($invoice, [$itemId => 1], [$itemId => 1]);
        $this->assertSame('18.000', $this->stockOf($product));
        $return = StockMovement::where('type', StockMovement::SALE_RETURN)->firstOrFail();
        $this->assertSame('1.000', (string) $return->quantity);
        $this->assertSame('6.00', (string) $return->unit_cost);
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_voiding_restores_everything_not_already_returned(): void
    {
        $product = $this->stockedProduct('10', '6.00', ['selling_price' => '15.00']);
        $dye = $this->stockedProduct('100', '0.05', ['unit' => 'ml', 'is_sellable' => false]);
        $service = $this->service(60, '40.00');
        ServiceConsumable::create(['service_id' => $service->id, 'product_id' => $dye->id, 'quantity' => '25']);

        $invoice = $this->issueInvoice($this->cashier, [$this->productLine($product, 3), ['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('85.00')]);
        $productItem = $invoice->items->firstWhere('product_id', $product->id);
        $this->assertSame('7.000', $this->stockOf($product));
        $this->assertSame('75.000', $this->stockOf($dye));

        $this->refund($invoice, [$productItem->id => 1, $invoice->items->firstWhere('service_id', $service->id)->id => 1], [$productItem->id => 1]);
        $this->refund($invoice, [$productItem->id => 2], [$productItem->id => 0]);
        $this->assertSame('8.000', $this->stockOf($product));

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Test'])->assertSessionDoesntHaveErrors();
        $this->assertSame('10.000', $this->stockOf($product), 'one unit was returned earlier; the 2 refunded without restock come back on void');
        $this->assertSame('100.000', $this->stockOf($dye));
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_a_voided_credit_sale_puts_its_stock_back_once(): void
    {
        $product = $this->stockedProduct('10', '6.00', ['selling_price' => '15.00']);
        $invoice = $this->issueInvoice($this->cashier, [$this->productLine($product, 4)], [], ['customer_id' => $this->customer()->id]);
        $this->assertSame('6.000', $this->stockOf($product));

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Customer cancelled']);
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/void", ['reason' => 'Again'])->assertSessionHasErrors('invoice');

        $this->assertSame('10.000', $this->stockOf($product));
        $this->assertSame(1, StockMovement::where('type', StockMovement::VOID_REVERSAL)->count());
    }

    public function test_mixed_carts_total_correctly_and_pos_exposes_products_and_barcodes(): void
    {
        $product = $this->stockedProduct('10', '6.00', ['name' => 'Argan Oil', 'selling_price' => '15.00', 'tax_rate' => '10.00', 'barcode' => '6291041500213']);
        $service = $this->service(60, '40.00', ['tax_rate' => '5.00']);

        $invoice = $this->issueInvoice($this->cashier, [$this->productLine($product, 2), ['service_id' => $service->id, 'quantity' => 1]], [$this->cashPayment('75.00')]);
        $this->assertSame('75.00', (string) $invoice->total);
        $this->assertSame('3.00', (string) $invoice->items->firstWhere('product_id', $product->id)->tax_amount);

        $this->actingAs($this->cashier)->get('/pos')->assertOk()->assertSee('Argan Oil')->assertSee('6291041500213')->assertSee('Scan barcode');
    }

    public function test_cashiers_see_stock_but_not_costs_and_the_service_page_lists_consumables(): void
    {
        $dye = $this->stockedProduct('100', '0.05', ['name' => 'Colour Tube', 'unit' => 'ml', 'is_sellable' => false]);
        $service = $this->service(60, '40.00');
        $manager = User::factory()->withPermissions('services.view', 'services.manage', 'inventory.view')->create();

        $this->actingAs($manager)->post("/services/{$service->id}/consumables", ['product_id' => $dye->id, 'quantity' => '30'])->assertSessionDoesntHaveErrors();
        $this->actingAs($manager)->post("/services/{$service->id}/consumables", ['product_id' => $dye->id, 'quantity' => '10'])->assertSessionHasErrors('product_id');
        $this->actingAs($manager)->post("/services/{$service->id}/consumables", ['product_id' => $dye->id, 'quantity' => '0'])->assertSessionHasErrors();
        $this->actingAs($manager)->get("/services/{$service->id}")->assertOk()->assertSee('Colour Tube');
        $consumable = ServiceConsumable::firstOrFail();
        $this->actingAs(User::factory()->withPermissions('services.view')->create())->post("/services/{$service->id}/consumables", ['product_id' => $dye->id, 'quantity' => '1'])->assertForbidden();
        $this->actingAs($manager)->delete("/services/{$service->id}/consumables/{$consumable->id}")->assertSessionDoesntHaveErrors();
        $this->assertSame(0, ServiceConsumable::count());
    }

    public function test_the_checkout_service_deducts_through_the_same_path_as_the_pos_screen(): void
    {
        $product = $this->stockedProduct('5', '2.00', ['selling_price' => '10.00']);
        $result = app(CheckoutService::class)->checkout($this->checkoutPayload([$this->productLine($product, 5)], [$this->cashPayment('50.00')]), $this->cashier);

        $this->assertSame('0.000', $this->stockOf($product));
        $this->assertSame('paid', $result['invoice']->payment_status);
        $this->actingAs($this->cashier)->post('/pos', $this->checkoutPayload([$this->productLine($product, 1)], [$this->cashPayment('10.00')]))->assertSessionHasErrors('stock');
    }
}
