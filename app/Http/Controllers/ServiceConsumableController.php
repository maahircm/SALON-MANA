<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceConsumable;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceConsumableController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('services.manage');
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id', Rule::unique('service_consumables', 'product_id')->where('service_id', $service->id)],
            'quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:99999'],
        ]);
        $consumable = $service->consumables()->create($data);
        $this->audit->log('service_consumable.added', $service, null, ['product_id' => $consumable->product_id, 'quantity' => (string) $consumable->quantity]);

        return back()->with('success', 'Consumable added. It is deducted from stock whenever this service is invoiced.');
    }

    public function destroy(Service $service, ServiceConsumable $consumable): RedirectResponse
    {
        $this->authorize('services.manage');
        abort_unless($consumable->service_id === $service->id, 404);
        $consumable->delete();
        $this->audit->log('service_consumable.removed', $service, ['product_id' => $consumable->product_id], null);

        return back()->with('success', 'Consumable removed.');
    }
}
