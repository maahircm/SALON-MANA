<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockMovementController extends Controller
{
    /** Read-only stock ledger. */
    public function index(Request $request): View
    {
        $this->authorize('inventory.view');
        $movements = StockMovement::with('product:id,sku,name,unit', 'user:id,name')
            ->when($request->query('product'), fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($request->query('to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest('id')->paginate(25)->withQueryString();

        return view('inventory.movements', ['movements' => $movements, 'products' => Product::withTrashed()->orderBy('name')->get(['id', 'sku', 'name'])]);
    }

    public function valuation(Request $request): View
    {
        $this->authorize('inventory.costs');
        $products = Product::with('category:id,name')->where('is_active', true)->orderBy('name')->get();
        $total = Money::add(...$products->map->stockValue()->all());

        return view('inventory.valuation', ['products' => $products, 'total' => $total, 'units' => Quantity::add(...$products->pluck('current_stock')->all())]);
    }
}
