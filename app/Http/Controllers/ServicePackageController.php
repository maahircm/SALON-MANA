<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServicePackage;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServicePackageController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        $this->authorize('services.view');

        return view('services.packages', ['packages' => ServicePackage::with('items.service')->orderBy('name')->get()]);
    }

    public function create()
    {
        $this->authorize('services.manage');

        return view('services.package-form', ['package' => new ServicePackage(['is_active' => true]), 'services' => Service::where('is_active', true)->orderBy('name')->get(), 'quantities' => []]);
    }

    public function store(Request $request)
    {
        $this->authorize('services.manage');
        $data = $this->validated($request);
        $package = DB::transaction(function () use ($data, $request) {
            $package = ServicePackage::create(collect($data)->except('items')->all() + ['is_active' => $request->boolean('is_active')]);
            $this->syncItems($package, $data['items']);
            $this->audit->log('service_package.created', $package, null, ['name' => $package->name, 'price' => $package->price]);

            return $package;
        });

        return redirect()->route('packages.index')->with('success', "Package {$package->name} created.");
    }

    public function edit(ServicePackage $package)
    {
        $this->authorize('services.manage');

        return view('services.package-form', ['package' => $package, 'services' => Service::where('is_active', true)->orderBy('name')->get(),
            'quantities' => $package->items()->pluck('quantity', 'service_id')->all()]);
    }

    public function update(Request $request, ServicePackage $package)
    {
        $this->authorize('services.manage');
        $data = $this->validated($request, $package);
        DB::transaction(function () use ($data, $request, $package) {
            $before = ['name' => $package->name, 'price' => $package->price];
            $package->fill(collect($data)->except('items')->all())->forceFill(['is_active' => $request->boolean('is_active')])->save();
            $this->syncItems($package, $data['items']);
            $this->audit->log('service_package.updated', $package, $before, ['name' => $package->name, 'price' => $package->price]);
        });

        return redirect()->route('packages.index')->with('success', 'Package updated.');
    }

    public function destroy(ServicePackage $package)
    {
        $this->authorize('services.manage');
        $this->audit->log('service_package.deleted', $package, ['name' => $package->name], null);
        $package->delete();

        return redirect()->route('packages.index')->with('success', 'Package deleted.');
    }

    private function validated(Request $request, ?ServicePackage $package = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('service_packages', 'name')->ignore($package?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);
        // items: service_id => quantity; zero / empty quantities are dropped.
        $data['items'] = collect($data['items'])->filter(fn ($q) => (int) $q > 0)->all();
        if ($data['items'] === []) {
            throw ValidationException::withMessages(['items' => 'Select at least one service with a quantity.']);
        }
        $valid = Service::whereIn('id', array_keys($data['items']))->pluck('id')->all();
        if (array_diff(array_keys($data['items']), $valid)) {
            throw ValidationException::withMessages(['items' => 'Unknown service selected.']);
        }

        return $data;
    }

    private function syncItems(ServicePackage $package, array $items): void
    {
        $package->items()->delete();
        foreach ($items as $serviceId => $qty) {
            $package->items()->create(['service_id' => $serviceId, 'quantity' => (int) $qty]);
        }
    }
}
