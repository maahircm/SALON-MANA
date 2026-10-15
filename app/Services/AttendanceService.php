<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Atomic;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Clock in / out, manual corrections by HR, and monthly summaries. */
class AttendanceService
{
    public function __construct(private AvailabilityService $availability, private SettingsService $settings, private AuditLogger $audit) {}

    public function clockIn(Employee $employee, User $actor): EmployeeAttendance
    {
        if ($employee->status !== 'active') {
            throw ValidationException::withMessages(['attendance' => 'Only active employees can clock in.']);
        }
        if ($employee->isOnLeave(now())) {
            throw ValidationException::withMessages(['attendance' => 'This employee is on approved leave today.']);
        }

        return Atomic::run(function () use ($employee, $actor) {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $record = EmployeeAttendance::where('employee_id', $employee->id)->whereDate('work_date', today())->first();
            if ($record?->clock_in) {
                throw ValidationException::withMessages(['attendance' => 'Already clocked in today.']);
            }
            $status = $this->isLate($employee) ? 'late' : 'present';
            $record ??= new EmployeeAttendance(['employee_id' => $employee->id, 'work_date' => today()->toDateString()]);
            $record->forceFill(['clock_in' => now(), 'status' => $status, 'recorded_by' => $actor->id])->save();
            $this->audit->log('attendance.clock_in', $record, null, ['employee_id' => $employee->id, 'status' => $status]);

            return $record;
        });
    }

    public function clockOut(Employee $employee, User $actor): EmployeeAttendance
    {
        return Atomic::run(function () use ($employee, $actor) {
            $record = EmployeeAttendance::where('employee_id', $employee->id)->whereDate('work_date', today())->lockForUpdate()->first();
            if (! $record || ! $record->clock_in) {
                throw ValidationException::withMessages(['attendance' => 'Clock in first.']);
            }
            if ($record->clock_out) {
                throw ValidationException::withMessages(['attendance' => 'Already clocked out today.']);
            }
            $record->forceFill(['clock_out' => now(), 'recorded_by' => $actor->id])->save();
            $this->audit->log('attendance.clock_out', $record, null, ['employee_id' => $employee->id, 'minutes' => $record->minutesWorked()]);

            return $record;
        });
    }

    /**
     * HR creates or corrects a day's record. The reason and before/after values are audited.
     */
    public function record(Employee $employee, string $date, string $status, ?string $clockIn, ?string $clockOut, ?string $notes, string $reason, User $actor): EmployeeAttendance
    {
        if (! $actor->can('employees.attendance')) {
            abort(403, 'You are not allowed to edit attendance.');
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for manual attendance entries.']);
        }
        if (! array_key_exists($status, EmployeeAttendance::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }
        $day = CarbonImmutable::parse($date)->startOfDay();
        if ($day->gt(today())) {
            throw ValidationException::withMessages(['work_date' => 'Attendance cannot be recorded for a future date.']);
        }
        $in = $clockIn ? $day->setTimeFromTimeString($clockIn) : null;
        $out = $clockOut ? $day->setTimeFromTimeString($clockOut) : null;
        if ($in && $out && $out->lte($in)) {
            throw ValidationException::withMessages(['clock_out' => 'Clock-out must be after clock-in.']);
        }

        return Atomic::run(function () use ($employee, $day, $status, $in, $out, $notes, $reason, $actor) {
            $record = EmployeeAttendance::where('employee_id', $employee->id)->whereDate('work_date', $day->toDateString())->lockForUpdate()->first();
            $before = $record ? $record->only(['status', 'clock_in', 'clock_out']) : null;
            $record ??= new EmployeeAttendance(['employee_id' => $employee->id, 'work_date' => $day->toDateString()]);
            $record->forceFill(['status' => $status, 'clock_in' => $in, 'clock_out' => $out, 'notes' => $notes, 'recorded_by' => $actor->id])->save();
            $this->audit->log($before ? 'attendance.corrected' : 'attendance.recorded', $record, $before, $record->only(['status', 'clock_in', 'clock_out']), $reason);

            return $record;
        });
    }

    /**
     * @return array{present: int, late: int, half_day: int, absent: int, leave_days: int, minutes: int}
     */
    public function monthlySummary(Employee $employee, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $records = EmployeeAttendance::where('employee_id', $employee->id)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();

        $leaveDays = 0;
        foreach (LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())->get() as $leave) {
            $leaveDays += (int) CarbonImmutable::parse(max($leave->starts_on, $from))->diffInDays(CarbonImmutable::parse(min($leave->ends_on, $to))) + 1;
        }

        return [
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'half_day' => $records->where('status', 'half_day')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'leave_days' => $leaveDays,
            'minutes' => $records->sum(fn (EmployeeAttendance $r) => $r->minutesWorked()),
        ];
    }

    /** Late = clocking in after the first scheduled start plus the grace period. */
    private function isLate(Employee $employee): bool
    {
        $windows = $this->availability->workingWindows($employee, now());
        if ($windows === []) {
            return false;
        }

        return now()->gt($windows[0][0]->addMinutes((int) $this->settings->get('attendance.grace_minutes', 10)));
    }
}
