<?php

namespace App\Http\Controllers;

use App\Models\ServiceCategory;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceCategoryController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        $this->authorize('services.view');

        return view('services.categories', ['categories' => ServiceCategory::withCount('services')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $this->authorize('services.manage');
        $data = $this->validated($request);
        $category = ServiceCategory::create($data + ['is_active' => $request->boolean('is_active', true)]);
        $this->audit->log('service_category.created', $category, null, ['name' => $category->name]);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, ServiceCategory $category)
    {
        $this->authorize('services.manage');
        $data = $this->validated($request, $category);
        $category->fill($data)->forceFill(['is_active' => $request->boolean('is_active')])->save();
        $this->audit->log('service_category.updated', $category, null, ['name' => $category->name, 'is_active' => $category->is_active]);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(ServiceCategory $category)
    {
        $this->authorize('services.manage');
        if ($category->services()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['category' => 'This category has services (or past services). Deactivate it instead of deleting.']);
        }
        $category->delete();
        $this->audit->log('service_category.deleted', $category);

        return back()->with('success', 'Category deleted.');
    }

    private function validated(Request $request, ?ServiceCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('service_categories', 'name')->ignore($category?->id)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ]) + ['sort_order' => (int) $request->input('sort_order', 0)];
    }
}
