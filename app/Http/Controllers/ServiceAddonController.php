<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceAddon;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ServiceAddonController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request, Service $service)
    {
        $this->authorize('services.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'duration_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999'],
        ]);
        $addon = $service->addons()->create($data + ['is_active' => true]);
        $this->audit->log('service_addon.created', $addon, null, $data);

        return back()->with('success', 'Add-on added.');
    }

    public function destroy(Service $service, ServiceAddon $addon)
    {
        $this->authorize('services.manage');
        abort_unless($addon->service_id === $service->id, 404);
        $addon->delete();
        $this->audit->log('service_addon.deleted', $addon);

        return back()->with('success', 'Add-on removed.');
    }
}
