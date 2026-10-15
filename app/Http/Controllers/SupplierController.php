<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorizeView($request);
        $suppliers = Supplier::withCount('products')
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('contact_person', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('inventory.suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        $this->authorize('inventory.suppliers');

        return view('inventory.suppliers.form', ['supplier' => new Supplier(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.suppliers');
        $supplier = new Supplier($this->validated($request));
        $supplier->is_active = $request->boolean('is_active', true);
        $supplier->forceFill(['created_by' => $request->user()->id])->save();
        $this->audit->log('supplier.created', $supplier, null, ['name' => $supplier->name]);

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Supplier added.');
    }

    public function show(Request $request, Supplier $supplier): View
    {
        $this->authorizeView($request);

        return view('inventory.suppliers.show', [
            'supplier' => $supplier,
            'orders' => $supplier->purchaseOrders()->latest('id')->limit(15)->get(),
            'products' => $supplier->products()->orderBy('name')->limit(50)->get(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorize('inventory.suppliers');

        return view('inventory.suppliers.form', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('inventory.suppliers');
        $supplier->fill($this->validated($request, $supplier));
        $supplier->is_active = $request->boolean('is_active');
        $supplier->save();
        $this->audit->log('supplier.updated', $supplier, null, ['name' => $supplier->name, 'is_active' => $supplier->is_active]);

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Supplier updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->ignore($supplier?->id)],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->canAny(['inventory.suppliers', 'inventory.purchasing']), 403);
    }
}
