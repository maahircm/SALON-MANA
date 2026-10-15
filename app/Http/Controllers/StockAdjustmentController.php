<?php

namespace App\Http\Controllers;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Services\StockAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('inventory.adjust');

        return view('inventory.adjustments', [
            'adjustments' => InventoryAdjustment::with('product:id,sku,name,unit', 'user:id,name')->latest('id')->paginate(20),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'unit', 'current_stock']),
            'selected' => $request->query('product'),
        ]);
    }

    public function store(Request $request, StockAdjustmentService $adjustments): RedirectResponse
    {
        $this->authorize('inventory.adjust');
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'kind' => ['required', Rule::in(array_keys(InventoryAdjustment::KINDS))],
            'quantity' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $adjustment = $adjustments->adjust(Product::findOrFail($data['product_id']), $data['kind'], $data['quantity'], $data['reason'], $request->user());

        return redirect()->route('adjustments.index')->with('success', "Stock of {$adjustment->product->name} is now {$adjustment->new_quantity}.");
    }
}
