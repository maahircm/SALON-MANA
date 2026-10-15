<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\RuntimeSettings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Salon profile, locale (timezone, currency, formats), tax, receipt layout and finance policy. */
class GeneralSettingsController extends Controller
{
    private const KEYS = [
        'general.salon_name', 'general.phone', 'general.email', 'general.address', 'general.timezone', 'general.currency', 'general.currency_symbol', 'general.date_format', 'general.time_format',
        'tax.label', 'tax.number', 'tax.default_rate', 'receipt.paper', 'receipt.show_logo', 'receipt.show_tax_number', 'receipt.show_cashier', 'finance.expense_self_approval',
    ];

    public function edit(SettingsService $settings): View
    {
        $this->authorize('settings.manage');
        $values = collect(self::KEYS)->mapWithKeys(fn ($key) => [$key => $settings->get($key)])->all();
        $values['general.salon_name'] ??= config('salon.name');
        $values['general.timezone'] ??= config('app.timezone');
        $values['general.currency'] ??= config('salon.currency');
        $values['general.currency_symbol'] ??= config('salon.currency_symbol');
        $values['general.date_format'] ??= config('salon.date_format');
        $values['general.time_format'] ??= config('salon.time_format');

        return view('settings.general', [
            'values' => $values, 'logo' => $settings->get('general.logo_path'),
            'timezones' => DateTimeZone::listIdentifiers(), 'dateFormats' => RuntimeSettings::DATE_FORMATS, 'timeFormats' => RuntimeSettings::TIME_FORMATS,
            'timezoneLocked' => $this->hasBusinessData(), 'currencyLocked' => Invoice::query()->exists(),
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        $timezoneLocked = $this->hasBusinessData();
        $currencyLocked = Invoice::query()->exists();

        $data = $request->validate([
            'salon_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'date_format' => ['required', Rule::in(array_keys(RuntimeSettings::DATE_FORMATS))],
            'time_format' => ['required', Rule::in(array_keys(RuntimeSettings::TIME_FORMATS))],
            'tax_label' => ['required', 'string', 'max:20'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'tax_default_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'receipt_paper' => ['required', Rule::in(['80mm', 'a4'])],
        ]);

        $current = $settings->get('general.timezone') ?? config('app.timezone');
        if ($timezoneLocked && $data['timezone'] !== $current) {
            return back()->withInput()->withErrors(['timezone' => 'The timezone cannot be changed once appointments or invoices exist: every stored time would shift.']);
        }
        if ($currencyLocked && ($data['currency'] !== config('salon.currency') || $data['currency_symbol'] !== config('salon.currency_symbol'))) {
            return back()->withInput()->withErrors(['currency' => 'The currency cannot be changed once invoices exist: amounts are not converted.']);
        }

        $before = collect(self::KEYS)->mapWithKeys(fn ($key) => [$key => $settings->get($key)])->all();
        $values = [
            'general.salon_name' => $data['salon_name'], 'general.phone' => $data['phone'] ?? '', 'general.email' => $data['email'] ?? '', 'general.address' => $data['address'] ?? '',
            'general.timezone' => $data['timezone'], 'general.currency' => $data['currency'], 'general.currency_symbol' => $data['currency_symbol'],
            'general.date_format' => $data['date_format'], 'general.time_format' => $data['time_format'],
            'tax.label' => $data['tax_label'], 'tax.number' => $data['tax_number'] ?? '', 'tax.default_rate' => $data['tax_default_rate'],
            'receipt.paper' => $data['receipt_paper'], 'receipt.show_logo' => $request->boolean('receipt_show_logo'), 'receipt.show_tax_number' => $request->boolean('receipt_show_tax_number'),
            'receipt.show_cashier' => $request->boolean('receipt_show_cashier'), 'finance.expense_self_approval' => $request->boolean('expense_self_approval'),
        ];
        foreach ($values as $key => $value) {
            $settings->set($key, $value, $request->user()->id);
        }

        if ($request->hasFile('logo')) {
            $old = $settings->get('general.logo_path');
            $path = $request->file('logo')->storeAs('logos', bin2hex(random_bytes(12)).'.'.($request->file('logo')->guessExtension() ?: 'png'), 'public');
            $settings->set('general.logo_path', $path, $request->user()->id);
            $old && Storage::disk('public')->delete($old);
        }

        RuntimeSettings::apply($settings);
        $audit->log('settings.general_updated', 'settings', $before, $values + ['logo_changed' => $request->hasFile('logo')]);

        return back()->with('success', 'Settings saved.');
    }

    public function removeLogo(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('settings.manage');
        if ($path = $settings->get('general.logo_path')) {
            Storage::disk('public')->delete($path);
            $settings->set('general.logo_path', null, $request->user()->id);
            $audit->log('settings.logo_removed', 'settings');
        }

        return back()->with('success', 'Logo removed.');
    }

    private function hasBusinessData(): bool
    {
        return Invoice::query()->exists() || Appointment::query()->exists();
    }
}
