<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Invoice numbering, discount policy, credit sales and receipt text. */
class PosSettingsController extends Controller
{
    private const KEYS = ['invoice_prefix', 'refund_prefix', 'number_padding', 'max_discount_percent', 'allow_credit_sales', 'require_open_drawer', 'receipt_header', 'receipt_footer'];

    public function edit(SettingsService $settings): View
    {
        $this->authorize('settings.manage');

        return view('settings.pos', ['values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get("pos.$k")])->all()]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        $data = $request->validate([
            'invoice_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_\/]+$/'],
            'refund_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_\/]+$/'],
            'number_padding' => ['required', 'integer', 'min:3', 'max:10'],
            'max_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'receipt_header' => ['nullable', 'string', 'max:500'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
        ]);
        $data['allow_credit_sales'] = $request->boolean('allow_credit_sales');
        $data['require_open_drawer'] = $request->boolean('require_open_drawer');
        $data['max_discount_percent'] = (string) $data['max_discount_percent'];

        $before = collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get("pos.$k")])->all();
        foreach ($data as $key => $value) {
            $settings->set("pos.$key", $value, $request->user()->id);
        }
        $audit->log('settings.pos_updated', 'settings', $before, $data);

        return back()->with('success', 'POS settings saved.');
    }
}
