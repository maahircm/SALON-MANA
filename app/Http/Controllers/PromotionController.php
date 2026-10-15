<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Promotion;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('loyalty.view');

        return view('loyalty.promotions.index', ['promotions' => Promotion::withCount(['coupons', 'redemptions as redeemed_count' => fn ($q) => $q->where('status', 'redeemed')])->latest('id')->paginate(15)]);
    }

    public function create(): View
    {
        $this->authorize('loyalty.manage');

        return view('loyalty.promotions.form', ['promotion' => new Promotion(['discount_type' => 'percent', 'is_active' => true, 'min_purchase' => '0.00', 'starts_at' => now()->startOfDay(), 'ends_at' => now()->addMonth()->endOfDay()])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $promotion = new Promotion($this->validated($request));
        $promotion->is_active = $request->boolean('is_active', true);
        $promotion->forceFill(['created_by' => $request->user()->id])->save();
        $this->audit->log('promotion.created', $promotion, null, ['name' => $promotion->name, 'type' => $promotion->discount_type, 'value' => (string) $promotion->discount_value]);

        return redirect()->route('promotions.show', $promotion)->with('success', 'Promotion created. Add a coupon code to start using it.');
    }

    public function show(Promotion $promotion): View
    {
        $this->authorize('loyalty.view');

        return view('loyalty.promotions.show', [
            'promotion' => $promotion->load('coupons'),
            'redemptions' => $promotion->redemptions()->with('coupon:id,code', 'invoice:id,invoice_number', 'customer:id,name')->latest('id')->limit(50)->get(),
        ]);
    }

    public function edit(Promotion $promotion): View
    {
        $this->authorize('loyalty.manage');

        return view('loyalty.promotions.form', compact('promotion'));
    }

    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $before = $promotion->only(['name', 'discount_type', 'discount_value', 'min_purchase', 'is_active']);
        $promotion->fill($this->validated($request));
        $promotion->is_active = $request->boolean('is_active');
        $promotion->save();
        $this->audit->log('promotion.updated', $promotion, $before, $promotion->only(array_keys($before)));

        return redirect()->route('promotions.show', $promotion)->with('success', 'Promotion updated.');
    }

    public function storeCoupon(Request $request, Promotion $promotion): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('coupons', 'code')],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ]);
        $coupon = new Coupon($data);
        $coupon->forceFill(['promotion_id' => $promotion->id, 'is_active' => true, 'used_count' => 0])->save();
        $this->audit->log('coupon.created', $coupon, null, ['code' => $coupon->code, 'promotion' => $promotion->name]);

        return back()->with('success', "Coupon {$coupon->code} created.");
    }

    public function toggleCoupon(Coupon $coupon): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $coupon->forceFill(['is_active' => ! $coupon->is_active])->save();
        $this->audit->log($coupon->is_active ? 'coupon.activated' : 'coupon.deactivated', $coupon, null, ['code' => $coupon->code]);

        return back()->with('success', 'Coupon updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999', Rule::when($request->input('discount_type') === 'percent', ['max:100'])],
            'min_purchase' => ['required', 'decimal:0,2', 'min:0', 'max:9999999'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $data;
    }
}
