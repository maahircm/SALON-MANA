<?php

namespace App\Http\Controllers;

use App\Models\CustomerMembership;
use App\Models\MembershipPlan;
use App\Services\AuditLogger;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MembershipController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('loyalty.view');

        return view('loyalty.memberships', [
            'plans' => MembershipPlan::withCount(['memberships as active_count' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get(),
            'memberships' => CustomerMembership::with('customer:id,name,customer_number', 'plan:id,name')
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->latest('id')->paginate(15)->withQueryString(),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $plan = new MembershipPlan($this->validated($request));
        $plan->is_active = $request->boolean('is_active', true);
        $plan->save();
        $this->audit->log('membership_plan.created', $plan, null, ['name' => $plan->name, 'price' => (string) $plan->price]);

        return back()->with('success', 'Plan created.');
    }

    public function updatePlan(Request $request, MembershipPlan $plan): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $before = $plan->only(['name', 'price', 'duration_days', 'discount_percent', 'points_multiplier', 'is_active']);
        $plan->fill($this->validated($request, $plan));
        $plan->is_active = $request->boolean('is_active');
        $plan->save();
        $this->audit->log('membership_plan.updated', $plan, $before, $plan->only(array_keys($before)));

        return back()->with('success', 'Plan updated. Existing memberships keep the terms they were sold with.');
    }

    public function cancel(Request $request, CustomerMembership $membership, MembershipService $service): RedirectResponse
    {
        $this->authorize('loyalty.manage');
        $service->cancel($membership, (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', 'Membership cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MembershipPlan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('membership_plans', 'name')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'discount_percent' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'points_multiplier' => ['required', 'decimal:0,2', 'min:0', 'max:10'],
        ]);
    }
}
