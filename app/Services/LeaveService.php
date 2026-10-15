<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\AppointmentService;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Atomic;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Leave requests and their approval. Approved leave removes the employee from the bookable schedule. */
class LeaveService
{
    public function __construct(private AuditLogger $audit) {}

    public function request(Employee $employee, string $type, string $startsOn, string $endsOn, ?string $reason, User $actor): LeaveRequest
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['ends_on' => 'The end date cannot be before the start date.']);
        }
        if ($start->diffInDays($end) >= 90) {
            throw ValidationException::withMessages(['ends_on' => 'A single request can cover at most 90 days.']);
        }
        if ($start->lt(today()) && ! $actor->can('employees.leave')) {
            throw ValidationException::withMessages(['starts_on' => 'Leave cannot start in the past.']);
        }
        if (! array_key_exists($type, LeaveRequest::TYPES)) {
            throw ValidationException::withMessages(['leave_type' => 'Unknown leave type.']);
        }

        return Atomic::run(function () use ($employee, $type, $start, $end, $reason, $actor) {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $overlap = LeaveRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
                ->whereDate('starts_on', '<=', $end->toDateString())->whereDate('ends_on', '>=', $start->toDateString())->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'This overlaps another pending or approved leave request.']);
            }
            $leave = LeaveRequest::create([
                'employee_id' => $employee->id, 'leave_type' => $type, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                'days' => (int) $start->diffInDays($end) + 1, 'reason' => $reason, 'status' => 'pending', 'requested_by' => $actor->id,
            ]);
            $this->audit->log('leave.requested', $leave, null, ['employee_id' => $employee->id, 'type' => $type, 'starts_on' => $leave->starts_on->toDateString(), 'ends_on' => $leave->ends_on->toDateString()]);

            return $leave;
        });
    }

    public function approve(LeaveRequest $leave, ?string $note, User $actor): LeaveRequest
    {
        $this->assertCanDecide($actor);

        return Atomic::run(function () use ($leave, $note, $actor) {
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($leave);
            $blocking = AppointmentService::where('employee_id', $leave->employee_id)
                ->whereDate('start_at', '>=', $leave->starts_on->toDateString())->whereDate('start_at', '<=', $leave->ends_on->toDateString())
                ->whereHas('appointment', fn ($q) => $q->whereIn('status', AppointmentStatus::blockingValues()))->count();
            if ($blocking > 0) {
                throw ValidationException::withMessages(['leave' => "{$blocking} appointment(s) are booked with this employee during the leave. Reschedule or cancel them first."]);
            }
            $this->decide($leave, 'approved', $note, $actor);

            return $leave;
        });
    }

    public function reject(LeaveRequest $leave, string $note, User $actor): LeaveRequest
    {
        $this->assertCanDecide($actor);
        if (blank($note)) {
            throw ValidationException::withMessages(['decision_note' => 'Please give a reason for rejecting the request.']);
        }

        return Atomic::run(function () use ($leave, $note, $actor) {
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($leave);
            $this->decide($leave, 'rejected', $note, $actor);

            return $leave;
        });
    }

    /** The requester (or an approver) can withdraw a request that has not finished yet. */
    public function cancel(LeaveRequest $leave, User $actor): LeaveRequest
    {
        return Atomic::run(function () use ($leave, $actor) {
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if (! in_array($leave->status, ['pending', 'approved'], true) || $leave->ends_on->lt(today())) {
                throw ValidationException::withMessages(['leave' => 'This request can no longer be cancelled.']);
            }
            if ($leave->requested_by !== $actor->id && ! $actor->can('employees.leave')) {
                abort(403, 'Only the requester or a leave approver can cancel a request.');
            }
            $before = $leave->status;
            $leave->forceFill(['status' => 'cancelled'])->save();
            $this->audit->log('leave.cancelled', $leave, ['status' => $before], ['status' => 'cancelled']);

            return $leave;
        });
    }

    private function decide(LeaveRequest $leave, string $status, ?string $note, User $actor): void
    {
        $leave->forceFill(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->log("leave.{$status}", $leave, ['status' => 'pending'], ['status' => $status], $note);
    }

    private function assertPending(LeaveRequest $leave): void
    {
        if (! $leave->isPending()) {
            throw ValidationException::withMessages(['leave' => 'This request has already been decided.']);
        }
    }

    private function assertCanDecide(User $actor): void
    {
        if (! $actor->can('employees.leave')) {
            abort(403, 'You are not allowed to decide leave requests.');
        }
    }
}
