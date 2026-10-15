<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeaveRequestController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canAny(['leave.request', 'employees.leave']), 403);
        $approver = $user->can('employees.leave');
        $mine = Employee::where('user_id', $user->id)->first();

        $requests = LeaveRequest::with('employee:id,name,employee_number', 'decider:id,name')
            ->when(! $approver, fn ($q) => $q->where('employee_id', $mine?->id ?? 0))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('id')->paginate(20)->withQueryString();

        return view('hr.leave', [
            'requests' => $requests, 'approver' => $approver, 'mine' => $mine,
            'employees' => $approver ? Employee::where('status', '!=', 'terminated')->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['leave.request', 'employees.leave']), 403);
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'leave_type' => ['required', Rule::in(array_keys(LeaveRequest::TYPES))],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        // Staff request only for themselves; approvers may file on someone's behalf.
        $employee = $user->can('employees.leave') && ! empty($data['employee_id'])
            ? Employee::findOrFail($data['employee_id'])
            : Employee::where('user_id', $user->id)->first();
        abort_if($employee === null, 422, 'Your user account is not linked to an employee record.');

        $this->leave->request($employee, $data['leave_type'], $data['starts_on'], $data['ends_on'], $data['reason'] ?? null, $user);

        return back()->with('success', 'Leave request submitted.');
    }

    public function approve(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $this->authorize('employees.leave');
        $this->leave->approve($leave, $request->input('decision_note'), $request->user());

        return back()->with('success', 'Leave approved. The employee is no longer bookable on those days.');
    }

    public function reject(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $this->authorize('employees.leave');
        $data = $request->validate(['decision_note' => ['required', 'string', 'max:500']]);
        $this->leave->reject($leave, $data['decision_note'], $request->user());

        return back()->with('success', 'Leave rejected.');
    }

    public function cancel(Request $request, LeaveRequest $leave): RedirectResponse
    {
        abort_unless($request->user()->canAny(['leave.request', 'employees.leave']), 403);
        $this->leave->cancel($leave, $request->user());

        return back()->with('success', 'Leave request cancelled.');
    }
}
