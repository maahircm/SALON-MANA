<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Commission rules and attendance policy. */
class HrSettingsController extends Controller
{
    public function edit(SettingsService $settings): View
    {
        $this->authorize('settings.manage');

        return view('settings.hr', ['accrueOn' => $settings->get('commission.accrue_on'), 'includeProducts' => $settings->get('commission.include_products'), 'grace' => $settings->get('attendance.grace_minutes')]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        $data = $request->validate(['accrue_on' => ['required', 'in:issue,full_payment'], 'grace_minutes' => ['required', 'integer', 'min:0', 'max:120']]);
        $before = ['accrue_on' => $settings->get('commission.accrue_on'), 'include_products' => $settings->get('commission.include_products'), 'grace_minutes' => $settings->get('attendance.grace_minutes')];
        $settings->set('commission.accrue_on', $data['accrue_on'], $request->user()->id);
        $settings->set('commission.include_products', $request->boolean('include_products'), $request->user()->id);
        $settings->set('attendance.grace_minutes', (int) $data['grace_minutes'], $request->user()->id);
        $audit->log('settings.hr_updated', 'settings', $before, ['accrue_on' => $data['accrue_on'], 'include_products' => $request->boolean('include_products'), 'grace_minutes' => (int) $data['grace_minutes']]);

        return back()->with('success', 'Settings saved. They apply to future sales; past commissions are never recalculated.');
    }
}
