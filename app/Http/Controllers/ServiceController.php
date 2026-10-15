<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Skill;
use App\Services\AuditLogger;
use App\Support\Atomic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('services.view');
        $services = Service::with('category:id,name', 'requiredSkill:id,name')
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->query('category'), fn ($q, $c) => $q->where('service_category_id', $c))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('services.index', ['services' => $services, 'categories' => ServiceCategory::orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function create()
    {
        $this->authorize('services.manage');

        return view('services.form', ['service' => new Service(['duration_minutes' => 60, 'tax_rate' => 0, 'is_active' => true]), ...$this->lookups()]);
    }

    public function store(ServiceRequest $request)
    {
        $service = Atomic::run(function () use ($request) {
            $data = $request->validated();
            $service = new Service(collect($data)->only(['service_category_id', 'name', 'description', 'duration_minutes', 'tax_rate', 'required_skill_id'])->all());
            $service->is_active = $request->boolean('is_active');
            $service->forceFill($this->priceFields($data) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();
            $service->priceHistories()->create($this->historyRow(null, $service, $request));
            $this->audit->log('service.created', $service, null, ['name' => $service->name, 'base_price' => $service->base_price, 'promo_price' => $service->promo_price]);

            return $service;
        });

        return redirect()->route('services.show', $service)->with('success', 'Service created.');
    }

    public function show(Service $service)
    {
        $this->authorize('services.view');
        $service->load('category', 'requiredSkill', 'addons', 'priceHistories', 'consumables.product');
        $consumableProducts = Auth::user()->can('services.manage')
            ? Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit'])
            : collect();

        return view('services.show', compact('service', 'consumableProducts'));
    }

    public function edit(Service $service)
    {
        $this->authorize('services.manage');

        return view('services.form', ['service' => $service, ...$this->lookups()]);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        Atomic::run(function () use ($request, $service) {
            $data = $request->validated();
            $oldBase = $service->base_price;
            $oldPromo = $service->promo_price;
            $original = $service->getOriginal();

            $service->fill(collect($data)->only(['service_category_id', 'name', 'description', 'duration_minutes', 'tax_rate', 'required_skill_id'])->all());
            $service->is_active = $request->boolean('is_active');
            $priceAllowed = $request->user()->can('services.price');
            if ($priceAllowed && array_key_exists('base_price', $data)) {
                $service->forceFill($this->priceFields($data));
            }
            $service->updated_by = $request->user()->id;
            $service->save();

            $priceChanged = $priceAllowed && ($oldBase !== $service->base_price || $oldPromo !== $service->promo_price);
            if ($priceChanged) {
                $service->priceHistories()->create($this->historyRow($oldBase, $service, $request, $oldPromo));
            }
            $changes = $service->getChanges();
            unset($changes['updated_at'], $changes['updated_by']);
            if ($changes) {
                $this->audit->log($priceChanged ? 'service.price_changed' : 'service.updated', $service,
                    array_intersect_key($original, $changes), $changes);
            }
        });

        return redirect()->route('services.show', $service)->with('success', 'Service updated.');
    }

    public function destroy(Service $service)
    {
        $this->authorize('services.manage');
        $service->delete(); // soft delete: booked lines and invoices keep their snapshots
        $this->audit->log('service.deleted', $service);

        return redirect()->route('services.index')->with('success', 'Service removed from the catalogue.');
    }

    private function priceFields(array $data): array
    {
        return [
            'base_price' => $data['base_price'],
            'promo_price' => $data['promo_price'] ?? null,
            'promo_starts_on' => ($data['promo_price'] ?? null) !== null ? ($data['promo_starts_on'] ?? null) : null,
            'promo_ends_on' => ($data['promo_price'] ?? null) !== null ? ($data['promo_ends_on'] ?? null) : null,
        ];
    }

    private function historyRow(?string $oldBase, Service $service, Request $request, ?string $oldPromo = null): array
    {
        return [
            'old_base_price' => $oldBase, 'new_base_price' => $service->base_price,
            'old_promo_price' => $oldPromo, 'new_promo_price' => $service->promo_price,
            'changed_by' => $request->user()->id,
        ];
    }

    private function lookups(): array
    {
        return ['categories' => ServiceCategory::orderBy('name')->get(), 'skills' => Skill::orderBy('name')->get()];
    }
}
