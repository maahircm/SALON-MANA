<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AttendanceService;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns;
use Tests\TestCase;

class LeaveAndAttendanceTest extends TestCase
{
    use Concerns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime(); // Monday 2026-10-19 08:00
    }

    private function hr(): User
    {
        return User::factory()->withPermissions('employees.view', 'employees.leave', 'employees.attendance')->create();
    }

    /** @return array<string, string> */
    private function leave(string $from = '2026-10-26', string $to = '2026-10-28', array $extra = []): array
    {
        return array_merge(['leave_type' => 'annual', 'starts_on' => $from, 'ends_on' => $to, 'reason' => 'Family event'], $extra);
    }

    public function test_staff_request_leave_for_themselves_and_it_starts_pending(): void
    {
        [$user, $employee] = $this->staffMember(['leave.request']);
        $other = Employee::factory()->create();

        $this->actingAs($user)->post('/leave', $this->leave('2026-10-26', '2026-10-28', ['employee_id' => $other->id]))->assertSessionDoesntHaveErrors();

        $leave = LeaveRequest::firstOrFail();
        $this->assertSame($employee->id, $leave->employee_id, 'staff cannot file for someone else');
        $this->assertSame('pending', $leave->status);
        $this->assertSame(3, $leave->days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.requested', 'user_id' => $user->id]);
    }

    public function test_leave_request_validation_and_overlap_rules(): void
    {
        [$user] = $this->staffMember(['leave.request']);
        $this->actingAs($user)->post('/leave', $this->leave('2026-10-28', '2026-10-26'))->assertSessionHasErrors('ends_on');
        $this->actingAs($user)->post('/leave', $this->leave('2026-10-01', '2026-10-02'))->assertSessionHasErrors('starts_on');
        $this->actingAs($user)->post('/leave', $this->leave('2026-10-26', '2027-03-01'))->assertSessionHasErrors('ends_on');
        $this->actingAs($user)->post('/leave', $this->leave(extra: ['leave_type' => 'holiday']))->assertSessionHasErrors('leave_type');
        $this->assertSame(0, LeaveRequest::count());

        $this->actingAs($user)->post('/leave', $this->leave('2026-10-26', '2026-10-28'));
        $this->actingAs($user)->post('/leave', $this->leave('2026-10-28', '2026-10-30'))->assertSessionHasErrors('starts_on');
        $this->actingAs($user)->post('/leave', $this->leave('2026-10-29', '2026-10-30'))->assertSessionDoesntHaveErrors();
        $this->assertSame(2, LeaveRequest::count());
    }

    public function test_hr_can_backdate_and_file_for_an_employee_but_a_user_without_an_employee_record_cannot_request(): void
    {
        $employee = Employee::factory()->create();
        $this->actingAs($this->hr())->post('/leave', $this->leave('2026-10-12', '2026-10-13', ['employee_id' => $employee->id]))->assertSessionDoesntHaveErrors();
        $this->assertSame($employee->id, LeaveRequest::firstOrFail()->employee_id);

        $unlinked = User::factory()->withPermissions('leave.request')->create();
        $this->actingAs($unlinked)->post('/leave', $this->leave())->assertStatus(422);
    }

    public function test_approval_needs_the_leave_permission_and_a_decision_is_final(): void
    {
        [$stylist, $employee] = $this->staffMember(['leave.request']);
        $this->actingAs($stylist)->post('/leave', $this->leave());
        $leave = LeaveRequest::firstOrFail();

        $this->actingAs($stylist)->post("/leave/{$leave->id}/approve")->assertForbidden();
        $this->actingAs($this->hr())->post("/leave/{$leave->id}/approve", ['decision_note' => 'Enjoy'])->assertSessionDoesntHaveErrors();
        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertNotNull($leave->decided_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.approved', 'reason' => 'Enjoy']);

        $this->actingAs($this->hr())->post("/leave/{$leave->id}/reject", ['decision_note' => 'Too late'])->assertSessionHasErrors('leave');
        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_rejection_needs_a_reason(): void
    {
        [$stylist] = $this->staffMember(['leave.request']);
        $this->actingAs($stylist)->post('/leave', $this->leave());
        $leave = LeaveRequest::firstOrFail();

        $this->actingAs($this->hr())->post("/leave/{$leave->id}/reject", [])->assertSessionHasErrors('decision_note');
        $this->actingAs($this->hr())->post("/leave/{$leave->id}/reject", ['decision_note' => 'Peak week'])->assertSessionDoesntHaveErrors();
        $this->assertSame('rejected', $leave->fresh()->status);
    }

    public function test_approved_leave_removes_the_employee_from_the_bookable_schedule(): void
    {
        $employee = $this->stylist();
        $service = $this->service(60);
        $admin = User::factory()->superAdmin()->create();
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'annual', 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-22', 'days' => 2, 'status' => 'pending']);

        $availability = app(AvailabilityService::class);
        $this->assertNotEmpty($availability->workingWindows($employee, Carbon::parse('2026-10-21')), 'pending leave does not block');

        $this->actingAs($this->hr())->post("/leave/{$leave->id}/approve");
        $this->assertSame([], $availability->workingWindows($employee, Carbon::parse('2026-10-21')));
        $this->assertSame([], $availability->freeSlots($employee, Carbon::parse('2026-10-22'), 60));
        $this->assertNotEmpty($availability->workingWindows($employee, Carbon::parse('2026-10-20')), 'the day before is unaffected');

        try {
            app(AppointmentBookingService::class)->create(['customer_id' => $this->customer()->id, 'start_at' => '2026-10-21 10:00', 'lines' => [['service_id' => $service->id, 'employee_id' => $employee->id]]], $admin);
            $this->fail('Booking during approved leave must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.employee_id', $e->errors());
        }
    }

    public function test_leave_cannot_be_approved_over_existing_appointments(): void
    {
        $employee = $this->stylist();
        $admin = User::factory()->superAdmin()->create();
        app(AppointmentBookingService::class)->create(['customer_id' => $this->customer()->id, 'start_at' => '2026-10-21 10:00', 'lines' => [['service_id' => $this->service(60)->id, 'employee_id' => $employee->id]]], $admin);
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'sick', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22', 'days' => 3, 'status' => 'pending']);

        $this->actingAs($this->hr())->post("/leave/{$leave->id}/approve")->assertSessionHasErrors('leave');
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_requests_can_be_cancelled_by_the_requester_or_an_approver_only(): void
    {
        [$stylist] = $this->staffMember(['leave.request']);
        [$colleague] = $this->staffMember(['leave.request']);
        $this->actingAs($stylist)->post('/leave', $this->leave());
        $leave = LeaveRequest::firstOrFail();

        $this->actingAs($colleague)->post("/leave/{$leave->id}/cancel")->assertForbidden();
        $this->actingAs($stylist)->post("/leave/{$leave->id}/cancel")->assertSessionDoesntHaveErrors();
        $this->assertSame('cancelled', $leave->fresh()->status);
        $this->actingAs($this->hr())->post("/leave/{$leave->id}/cancel")->assertSessionHasErrors('leave');
    }

    public function test_the_leave_list_is_scoped_to_the_viewer(): void
    {
        [$mine, $myEmployee] = $this->staffMember(['leave.request']);
        $other = Employee::factory()->create(['name' => 'Someone Else']);
        LeaveRequest::create(['employee_id' => $myEmployee->id, 'leave_type' => 'annual', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03', 'days' => 2, 'status' => 'pending']);
        LeaveRequest::create(['employee_id' => $other->id, 'leave_type' => 'annual', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03', 'days' => 2, 'status' => 'pending']);

        $this->actingAs($mine)->get('/leave')->assertOk()->assertDontSee('Someone Else');
        $this->actingAs($this->hr())->get('/leave')->assertOk()->assertSee('Someone Else');
        $this->actingAs(User::factory()->create())->get('/leave')->assertForbidden();
    }

    public function test_clocking_in_and_out_records_the_day_and_flags_late_arrivals(): void
    {
        [$user, $employee] = $this->staffMember(['attendance.self']);
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:05', config('app.timezone')));
        $this->actingAs($user)->post('/attendance/clock-in')->assertSessionDoesntHaveErrors();
        $this->assertSame('present', EmployeeAttendance::firstOrFail()->status, 'inside the 10 minute grace period');

        $this->actingAs($user)->post('/attendance/clock-in')->assertSessionHasErrors('attendance');
        Carbon::setTestNow(Carbon::parse('2026-10-19 17:30', config('app.timezone')));
        $this->actingAs($user)->post('/attendance/clock-out')->assertSessionDoesntHaveErrors();
        $this->assertSame(505, EmployeeAttendance::firstOrFail()->minutesWorked());
        $this->actingAs($user)->post('/attendance/clock-out')->assertSessionHasErrors('attendance');

        [$late] = $this->staffMember(['attendance.self']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:25', config('app.timezone')));
        $this->actingAs($late)->post('/attendance/clock-in');
        $this->assertSame('late', EmployeeAttendance::latest('id')->first()->status);
    }

    public function test_clock_out_needs_a_clock_in_and_leave_days_cannot_be_clocked(): void
    {
        [$user, $employee] = $this->staffMember(['attendance.self']);
        $this->actingAs($user)->post('/attendance/clock-out')->assertSessionHasErrors('attendance');

        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'sick', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-19', 'days' => 1, 'status' => 'approved']);
        $this->actingAs($user)->post('/attendance/clock-in')->assertSessionHasErrors('attendance');
        $this->assertSame(0, EmployeeAttendance::count());
    }

    public function test_attendance_clocking_needs_permission_and_a_linked_employee(): void
    {
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->post('/attendance/clock-in')->assertForbidden();
        $this->actingAs($nobody)->get('/attendance')->assertForbidden();

        $unlinked = User::factory()->withPermissions('attendance.self')->create();
        $this->actingAs($unlinked)->get('/attendance')->assertOk()->assertSee('not linked');
        $this->actingAs($unlinked)->post('/attendance/clock-in')->assertNotFound();
    }

    public function test_hr_records_and_corrects_attendance_with_a_mandatory_reason_and_audit_trail(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();
        $payload = ['employee_id' => $employee->id, 'work_date' => '2026-10-16', 'status' => 'present', 'clock_in' => '09:00', 'clock_out' => '17:00', 'reason' => 'Forgot to clock'];

        $this->actingAs($hr)->post('/attendance', ['reason' => ''] + $payload)->assertSessionHasErrors('reason');
        $this->actingAs($hr)->post('/attendance', ['work_date' => '2026-10-25'] + $payload)->assertSessionHasErrors('work_date');
        $this->actingAs($hr)->post('/attendance', ['clock_out' => '08:00'] + $payload)->assertSessionHasErrors('clock_out');
        $this->assertSame(0, EmployeeAttendance::count());

        $this->actingAs($hr)->post('/attendance', $payload)->assertSessionDoesntHaveErrors();
        $this->actingAs($hr)->post('/attendance', ['status' => 'half_day', 'clock_out' => '13:00', 'reason' => 'Left early, confirmed by manager'] + $payload)->assertSessionDoesntHaveErrors();

        $this->assertSame(1, EmployeeAttendance::count(), 'one record per employee per day');
        $this->assertSame('half_day', EmployeeAttendance::firstOrFail()->status);
        $log = AuditLog::where('action', 'attendance.corrected')->firstOrFail();
        $this->assertSame('present', $log->before['status']);
        $this->assertSame('half_day', $log->after['status']);
        $this->assertSame('Left early, confirmed by manager', $log->reason);

        $self = User::factory()->withPermissions('attendance.self')->create();
        $this->actingAs($self)->post('/attendance', $payload)->assertForbidden();
    }

    public function test_the_monthly_summary_counts_days_hours_and_leave(): void
    {
        $employee = Employee::factory()->create(['name' => 'Summary Subject']);
        $hr = $this->hr();
        foreach ([['2026-10-05', 'present', '09:00', '17:00'], ['2026-10-06', 'late', '09:30', '17:00'], ['2026-10-07', 'absent', null, null]] as [$date, $status, $in, $out]) {
            $this->actingAs($hr)->post('/attendance', ['employee_id' => $employee->id, 'work_date' => $date, 'status' => $status, 'clock_in' => $in, 'clock_out' => $out, 'reason' => 'Entry']);
        }
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'annual', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14', 'days' => 3, 'status' => 'approved']);

        $summary = app(AttendanceService::class)->monthlySummary($employee, Carbon::parse('2026-10-01')->toImmutable());
        $this->assertSame(['present' => 1, 'late' => 1, 'half_day' => 0, 'absent' => 1, 'leave_days' => 3, 'minutes' => 480 + 450], $summary);
        $this->actingAs($hr)->get('/attendance/summary?month=2026-10')->assertOk()->assertSee('Summary Subject');
    }
}
