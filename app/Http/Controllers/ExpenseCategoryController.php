<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('expenses.approve');

        return view('expenses.categories', ['categories' => ExpenseCategory::withCount('expenses')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('expenses.approve');
        $data = $request->validate(['name' => ['required', 'string', 'max:80', $this->uniqueName()]]);
        $category = ExpenseCategory::create($data);
        $this->audit->log('expense_category.created', $category, null, ['name' => $category->name]);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('expenses.approve');
        $data = $request->validate(['name' => ['required', 'string', 'max:80', $this->uniqueName($category)]]);
        $category->fill($data)->forceFill(['is_active' => $request->boolean('is_active')])->save();
        $this->audit->log('expense_category.updated', $category, null, ['name' => $category->name, 'is_active' => $category->is_active]);

        return back()->with('success', 'Category saved.');
    }

    /** Case-insensitive uniqueness regardless of the database collation. */
    private function uniqueName(?ExpenseCategory $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $exists = ExpenseCategory::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))->exists();
            if ($exists) {
                $fail('A category with this name already exists.');
            }
        };
    }
}
