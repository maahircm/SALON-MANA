<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->canAny(['attendance.self', 'employees.attendance']), 403);
        $mine = Employee::where('user_id', $request->user()->id)->first();
        $manager = $request->user()->can('employees.attendance');

        return view('hr.attendance', [
            'mine' => $mine,
            'today' => $mine ? EmployeeAttendance::where('employee_id', $mine->id)->whereDate('work_date', today())->first() : null,
            'canSelf' => $request->user()->can('attendance.self'),
            'manager' => $manager,
            'records' => $manager ? EmployeeAttendance::with('employee:id,name,employee_number')
                ->when($request->query('employee'), fn ($q, $v) => $q->where('employee_id', $v))
                ->when($request->query('from'), fn ($q, $v) => $q->where('work_date', '>=', $v))
                ->when($request->query('to'), fn ($q, $v) => $q->where('work_date', '<=', $v))
                ->latest('work_date')->latest('id')->paginate(25)->withQueryString() : null,
            'employees' => $manager ? Employee::where('status', '!=', 'terminated')->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        $this->authorize('attendance.self');
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();
        $record = $this->attendance->clockIn($employee, $request->user());

        return back()->with('success', 'Clocked in at '.$record->clock_in->format(config('salon.time_format')).($record->status === 'late' ? ' (late)' : '').'.');
    }

    public function clockOut(Request $request): RedirectResponse
    {
        $this->authorize('attendance.self');
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();
        $record = $this->attendance->clockOut($employee, $request->user());

        return back()->with('success', 'Clocked out at '.$record->clock_out->format(config('salon.time_format')).'.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('employees.attendance');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(array_keys(EmployeeAttendance::STATUSES))],
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->attendance->record(Employee::findOrFail($data['employee_id']), $data['work_date'], $data['status'], $data['clock_in'] ?? null, $data['clock_out'] ?? null, $data['notes'] ?? null, $data['reason'], $request->user());

        return back()->with('success', 'Attendance saved.');
    }

    public function summary(Request $request): View
    {
        $this->authorize('employees.attendance');
        $month = CarbonImmutable::parse(($request->query('month') ?: now()->format('Y-m')).'-01');
        $rows = Employee::where('status', '!=', 'terminated')->orderBy('name')->get()->map(fn (Employee $e) => ['employee' => $e, 'summary' => $this->attendance->monthlySummary($e, $month)]);

        return view('hr.attendance-summary', ['month' => $month, 'rows' => $rows]);
    }
}
