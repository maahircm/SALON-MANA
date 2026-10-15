<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductCategoryController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('inventory.view');

        return view('inventory.categories', ['categories' => ProductCategory::withCount('products')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.manage');
        $category = ProductCategory::create($request->validate(['name' => ['required', 'string', 'max:100', 'unique:product_categories,name']]) + ['is_active' => true]);
        $this->audit->log('product_category.created', $category, null, ['name' => $category->name]);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        $this->authorize('inventory.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('product_categories', 'name')->ignore($productCategory->id)]]);
        $productCategory->fill($data)->forceFill(['is_active' => $request->boolean('is_active')])->save();
        $this->audit->log('product_category.updated', $productCategory, null, $data + ['is_active' => $productCategory->is_active]);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        $this->authorize('inventory.manage');
        if ($productCategory->products()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['category' => 'This category has products. Mark it inactive instead.']);
        }
        $productCategory->delete();
        $this->audit->log('product_category.deleted', $productCategory);

        return back()->with('success', 'Category deleted.');
    }
}
