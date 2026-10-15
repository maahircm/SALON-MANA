<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchasingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchasingService $purchasing) {}

    public function index(Request $request): View
    {
        $this->authorize('inventory.purchasing');
        $orders = PurchaseOrder::with('supplier:id,name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('supplier'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('q'), fn ($q, $s) => $q->where('po_number', 'like', "%{$s}%"))
            ->latest('id')->paginate(20)->withQueryString();

        return view('inventory.orders.index', ['orders' => $orders, 'suppliers' => Supplier::orderBy('name')->get()]);
    }

    public function create(): View
    {
        $this->authorize('inventory.purchasing');

        return view('inventory.orders.form', ['order' => new PurchaseOrder, 'lines' => [], ...$this->lookups()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.purchasing');
        $order = $this->purchasing->saveDraft(null, $this->validated($request), $request->input('lines', []), $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', 'Draft purchase order saved.');
    }

    public function show(PurchaseOrder $order): View
    {
        $this->authorize('inventory.purchasing');
        $order->load('supplier', 'items.product', 'receipts.items.product', 'receipts.receiver:id,name', 'creator:id,name');

        return view('inventory.orders.show', compact('order'));
    }

    public function edit(PurchaseOrder $order): View
    {
        $this->authorize('inventory.purchasing');
        abort_unless($order->isEditable(), 403, 'Only draft purchase orders can be edited.');

        return view('inventory.orders.form', ['order' => $order, 'lines' => $order->items()->get()->keyBy('product_id')->map(fn ($i) => ['quantity' => (string) $i->quantity_ordered, 'unit_cost' => (string) $i->unit_cost])->all(), ...$this->lookups()]);
    }

    public function update(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('inventory.purchasing');
        $this->purchasing->saveDraft($order, $this->validated($request), $request->input('lines', []), $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', 'Draft updated.');
    }

    public function place(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('inventory.purchasing');
        $order = $this->purchasing->place($order, $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', "Purchase order {$order->po_number} placed.");
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('inventory.purchasing');
        $this->purchasing->cancel($order, (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'], $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', 'Purchase order cancelled.');
    }

    public function close(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('inventory.purchasing');
        $this->purchasing->closeShort($order, (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'], $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', 'Order closed short.');
    }

    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('inventory.purchasing');
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.quantity' => ['nullable', 'string', 'max:20'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $receipt = $this->purchasing->receive($order, $data['items'], $data['supplier_reference'] ?? null, $data['notes'] ?? null, $request->user());

        return redirect()->route('purchase-orders.show', $order)->with('success', "Goods receipt {$receipt->receipt_number} recorded; stock updated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'expected_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_cost' => ['nullable', 'string', 'max:20'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lookups(): array
    {
        return ['suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(), 'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'unit', 'cost_price', 'supplier_id'])];
    }
}
