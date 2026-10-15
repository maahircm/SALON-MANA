<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use App\Support\Atomic;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeScheduleController extends Controller
{
    public function __construct(private AuditLogger $audit, private AvailabilityService $availability) {}

    /** Staff scheduling overview: who works when over a week. */
    public function overview(Request $request)
    {
        abort_unless($request->user()->canAny(['employees.schedule', 'employees.view']), 403);
        $start = CarbonImmutable::parse($request->query('week', now()->toDateString()))->startOfWeek();
        $days = collect(range(0, 6))->map(fn ($i) => $start->addDays($i));
        $employees = Employee::where('status', '!=', 'terminated')->orderBy('name')->get();
        $grid = [];
        foreach ($employees as $e) {
            foreach ($days as $d) {
                $grid[$e->id][$d->toDateString()] = collect($this->availability->workingWindows($e, $d))
                    ->map(fn ($w) => $w[0]->format('H:i').'–'.$w[1]->format('H:i'))->all();
            }
        }

        return view('employees.schedules', compact('start', 'days', 'employees', 'grid'));
    }

    public function edit(Employee $employee)
    {
        $this->authorize('employees.schedule');
        $employee->load('schedules');
        $upcomingShifts = $employee->shifts()->where('shift_date', '>=', now()->toDateString())->get();

        return view('employees.schedule', ['employee' => $employee, 'weekly' => $employee->schedules->groupBy('weekday'), 'shifts' => $upcomingShifts]);
    }

    public function update(Request $request, Employee $employee)
    {
        $this->authorize('employees.schedule');
        $request->validate([
            'days' => ['array'],
            'days.*.*.start' => ['nullable', 'date_format:H:i'],
            'days.*.*.end' => ['nullable', 'date_format:H:i'],
        ]);

        $rows = [];
        foreach ((array) $request->input('days', []) as $weekday => $blocks) {
            if (! in_array((int) $weekday, range(0, 6), true)) {
                continue;
            }
            $valid = [];
            foreach ((array) $blocks as $b) {
                if (empty($b['start']) && empty($b['end'])) {
                    continue;
                }
                if (empty($b['start']) || empty($b['end']) || $b['start'] >= $b['end']) {
                    throw ValidationException::withMessages(['days' => 'Each working block needs a start time earlier than its end time.']);
                }
                $valid[] = $b;
            }
            usort($valid, fn ($a, $b) => strcmp($a['start'], $b['start']));
            foreach ($valid as $i => $b) {
                if ($i > 0 && $b['start'] < $valid[$i - 1]['end']) {
                    throw ValidationException::withMessages(['days' => Employee::WEEKDAYS[(int) $weekday].' has overlapping time blocks.']);
                }
                $rows[] = ['weekday' => (int) $weekday, 'start_time' => $b['start'], 'end_time' => $b['end']];
            }
        }

        Atomic::run(function () use ($employee, $rows) {
            $employee->schedules()->delete();
            $employee->schedules()->createMany($rows);
            $this->audit->log('employee.schedule_updated', $employee, null, ['blocks' => count($rows)]);
        });

        return back()->with('success', 'Weekly schedule saved.');
    }

    public function storeShift(Request $request, Employee $employee)
    {
        $this->authorize('employees.schedule');
        $data = $request->validate([
            'shift_date' => ['required', 'date', 'after_or_equal:today'],
            'type' => ['required', 'in:work,off'],
            'start_time' => ['required_if:type,work', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_if:type,work', 'nullable', 'date_format:H:i', 'after:start_time'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        if ($data['type'] === 'off') {
            $data['start_time'] = $data['end_time'] = null;
        }
        $shift = new EmployeeShift($data);
        $shift->employee_id = $employee->id;
        $shift->created_by = $request->user()->id;
        $shift->save();
        $this->audit->log('employee.shift_added', $employee, null, ['date' => $data['shift_date'], 'type' => $data['type']]);

        return back()->with('success', 'Shift saved.');
    }

    public function destroyShift(Employee $employee, EmployeeShift $shift)
    {
        $this->authorize('employees.schedule');
        abort_unless($shift->employee_id === $employee->id, 404);
        $shift->delete();
        $this->audit->log('employee.shift_removed', $employee, ['date' => $shift->shift_date->toDateString(), 'type' => $shift->type], null);

        return back()->with('success', 'Shift removed.');
    }
}
