<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Services\StockAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierReturnController extends Controller
{
    public function index(): View
    {
        $this->authorize('inventory.purchasing');

        return view('inventory.returns', [
            'returns' => SupplierReturn::with('supplier:id,name', 'product:id,sku,name,unit', 'creator:id,name')->latest('id')->paginate(20),
            'suppliers' => Supplier::orderBy('name')->get(),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'unit', 'current_stock']),
        ]);
    }

    public function store(Request $request, StockAdjustmentService $adjustments): RedirectResponse
    {
        $this->authorize('inventory.purchasing');
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $return = $adjustments->returnToSupplier(Supplier::findOrFail($data['supplier_id']), Product::findOrFail($data['product_id']), $data['quantity'], $data['reason'], $request->user());

        return redirect()->route('supplier-returns.index')->with('success', "Return {$return->return_number} recorded.");
    }
}
