<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Services\AuditLogger;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoyaltyController extends Controller
{
    private const KEYS = ['enabled', 'earn_points_per_unit', 'redeem_points_per_unit', 'min_redeem_points', 'max_redeem_percent', 'vip_threshold'];

    public function index(Request $request): View
    {
        $this->authorize('loyalty.view');
        $accounts = LoyaltyAccount::with('customer:id,name,customer_number,phone')
            ->when($request->query('q'), fn ($q, $s) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('customer_number', 'like', "%{$s}%")))
            ->orderByDesc('balance')->paginate(20)->withQueryString();

        return view('loyalty.index', ['accounts' => $accounts, 'outstanding' => (int) LoyaltyAccount::sum('balance')]);
    }

    public function show(Customer $customer): View
    {
        $this->authorize('loyalty.view');

        return view('loyalty.show', [
            'customer' => $customer,
            'account' => $customer->loyaltyAccount,
            'transactions' => LoyaltyTransaction::with('creator:id,name', 'invoice:id,invoice_number')->where('customer_id', $customer->id)->latest('id')->paginate(20),
        ]);
    }

    public function adjust(Request $request, Customer $customer, LoyaltyService $loyalty): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0'], 'reason' => ['required', 'string', 'max:255']]);
        $loyalty->adjust($customer, (int) $data['points'], $data['reason'], $request->user());

        return back()->with('success', 'Points adjusted.');
    }

    public function settings(SettingsService $settings): View
    {
        $this->authorize('loyalty.manage');

        return view('loyalty.settings', ['values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get("loyalty.$k")])->all()]);
    }

    public function updateSettings(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $data = $request->validate([
            'earn_points_per_unit' => ['required', 'decimal:0,2', 'min:0', 'max:1000'],
            'redeem_points_per_unit' => ['required', 'decimal:0,2', 'min:0.01', 'max:100000'],
            'min_redeem_points' => ['required', 'integer', 'min:0', 'max:1000000'],
            'max_redeem_percent' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'vip_threshold' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $data = array_map(fn ($v) => is_bool($v) || is_int($v) ? $v : (string) $v, $data);

        $before = collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get("loyalty.$k")])->all();
        foreach ($data as $key => $value) {
            $settings->set("loyalty.$key", $value, $request->user()->id);
        }
        $audit->log('settings.loyalty_updated', 'settings', $before, $data);

        return back()->with('success', 'Loyalty settings saved.');
    }
}
