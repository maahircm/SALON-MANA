<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ServiceConsumable;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\SettingsService;
use App\Support\Atomic;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private AuditLogger $audit, private InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $this->authorize('inventory.view');
        $products = Product::with('category:id,name', 'supplier:id,name')
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")->orWhere('barcode', 'like', "%{$s}%")))
            ->when($request->query('category'), fn ($q, $v) => $q->where('product_category_id', $v))
            ->when($request->query('supplier'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('status') === 'low', fn ($q) => $q->where('is_active', true)->where('reorder_level', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level'))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->query('status') === 'expiring', fn ($q) => $q->whereNotNull('expiry_date')->where('expiry_date', '<=', now()->addDays((int) app(SettingsService::class)->get('inventory.expiry_alert_days', 30))->toDateString()))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('inventory.products.index', ['products' => $products, 'categories' => ProductCategory::orderBy('name')->get(), 'suppliers' => Supplier::orderBy('name')->get()]);
    }

    public function create(): View
    {
        $this->authorize('inventory.manage');

        return view('inventory.products.form', ['product' => new Product(['unit' => 'pc', 'is_sellable' => true, 'is_active' => true, 'selling_price' => '0.00', 'tax_rate' => '0.00', 'reorder_level' => '0.000']), ...$this->lookups()]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $opening = (string) ($data['opening_quantity'] ?? '0');
        if (Quantity::compare($opening, '0') > 0) {
            $this->authorize('inventory.adjust');
        }

        $product = Atomic::run(function () use ($request, $data, $opening) {
            $product = new Product(collect($data)->except(['opening_quantity', 'opening_cost', 'is_sellable', 'is_active'])->all());
            $product->is_sellable = $request->boolean('is_sellable');
            $product->is_active = $request->boolean('is_active');
            $product->forceFill(['created_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();
            if (Quantity::compare($opening, '0') > 0) {
                $this->inventory->record($product->id, StockMovement::ADJUSTMENT_IN, $opening, $request->user(), 'Opening stock', 'opening', $product->id, (string) ($data['opening_cost'] ?? '0'), "opening:{$product->id}", true);
            }
            $this->audit->log('product.created', $product, null, ['sku' => $product->sku, 'name' => $product->name, 'opening_quantity' => $opening]);

            return $product;
        });

        return redirect()->route('products.show', $product)->with('success', "Product {$product->sku} created.");
    }

    public function show(Product $product): View
    {
        $this->authorize('inventory.view');
        $product->load('category', 'supplier');

        return view('inventory.products.show', [
            'product' => $product,
            'movements' => $product->movements()->with('user:id,name')->limit(25)->get(),
            'consumedBy' => ServiceConsumable::with('service:id,name')->where('product_id', $product->id)->get(),
        ]);
    }

    public function edit(Product $product): View
    {
        $this->authorize('inventory.manage');

        return view('inventory.products.form', ['product' => $product, ...$this->lookups()]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        Atomic::run(function () use ($request, $product) {
            $original = $product->getOriginal();
            $product->fill(collect($request->validated())->except(['is_sellable', 'is_active'])->all());
            $product->is_sellable = $request->boolean('is_sellable');
            $product->is_active = $request->boolean('is_active');
            $product->updated_by = $request->user()->id;
            $product->save();
            $changes = $product->getChanges();
            unset($changes['updated_at'], $changes['updated_by']);
            if ($changes) {
                $this->audit->log(array_key_exists('selling_price', $changes) ? 'product.price_changed' : 'product.updated', $product, array_intersect_key($original, $changes), $changes);
            }
        });

        return redirect()->route('products.show', $product)->with('success', 'Product updated.');
    }

    /** Products with any stock history are only deactivated; the ledger must keep its product. */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('inventory.manage');
        if (StockMovement::where('product_id', $product->id)->exists() || ServiceConsumable::where('product_id', $product->id)->exists()
            || InvoiceItem::where('product_id', $product->id)->exists()) {
            return back()->withErrors(['product' => 'This product has stock, sales or service history. Mark it inactive instead of deleting it.']);
        }
        $this->audit->log('product.deleted', $product, ['sku' => $product->sku], null);
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Product deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function lookups(): array
    {
        return ['categories' => ProductCategory::orderBy('name')->get(), 'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get()];
    }
}
