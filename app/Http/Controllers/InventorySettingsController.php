<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Stock policy and purchasing document numbering. */
class InventorySettingsController extends Controller
{
    private const KEYS = ['allow_negative_stock', 'expiry_alert_days', 'purchase_order_prefix', 'goods_receipt_prefix', 'supplier_return_prefix'];

    public function edit(SettingsService $settings): View
    {
        $this->authorize('settings.manage');

        return view('settings.inventory', ['values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get("inventory.$k")])->all()]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        $data = $request->validate([
            'expiry_alert_days' => ['required', 'integer', 'min:1', 'max:365'],
            'purchase_order_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_\/]+$/'],
            'goods_receipt_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_\/]+$/'],
            'supplier_return_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_\/]+$/'],
        ]);
        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');

        $before = collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get("inventory.$k")])->all();
        foreach ($data as $key => $value) {
            $settings->set("inventory.$key", $value, $request->user()->id);
        }
        $audit->log('settings.inventory_updated', 'settings', $before, $data);

        return back()->with('success', 'Inventory settings saved.');
    }
}
