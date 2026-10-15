<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Skill;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Sahra Warsame', 'status' => 'active', 'calendar_color' => '#336699', 'commission_type' => 'percentage', 'commission_rate' => 20, 'is_bookable' => 1], $over);
    }

    public function test_hr_registers_employee_with_number_skills_and_salary(): void
    {
        $hr = User::factory()->withPermissions('employees.view', 'employees.manage', 'employees.sensitive')->create();
        $skill = Skill::factory()->create();
        $this->actingAs($hr)->post('/employees', $this->payload(['skills' => [$skill->id], 'salary_amount' => '450.00']))->assertRedirect();
        $e = Employee::firstOrFail();
        $this->assertSame(sprintf('EMP-%04d', $e->id), $e->employee_number);
        $this->assertTrue($e->skills->contains($skill));
        $this->assertSame('450.00', $e->salary_amount);
    }

    public function test_salary_is_hidden_and_not_writable_without_sensitive_permission(): void
    {
        $manager = User::factory()->withPermissions('employees.view', 'employees.manage')->create();
        $this->actingAs($manager)->post('/employees', $this->payload(['salary_amount' => '999.00']))->assertRedirect();
        $e = Employee::firstOrFail();
        $this->assertNull($e->salary_amount);
        $e->forceFill(['salary_amount' => '500.00'])->save();
        $this->actingAs($manager)->get("/employees/{$e->id}")->assertOk()->assertDontSee('500.00');
        $this->actingAs($manager)->put("/employees/{$e->id}", $this->payload(['salary_amount' => '1.00']))->assertRedirect();
        $this->assertSame('500.00', $e->fresh()->salary_amount);
        $hr = User::factory()->withPermissions('employees.view', 'employees.sensitive')->create();
        $this->actingAs($hr)->get("/employees/{$e->id}")->assertSee('$500.00');
        $this->assertArrayNotHasKey('salary_amount', $e->toArray());
    }

    public function test_receptionist_can_view_directory_but_not_manage(): void
    {
        $r = $this->receptionist();
        $e = Employee::factory()->create();
        $this->actingAs($r)->get('/employees')->assertOk();
        $this->actingAs($r)->get('/employees/create')->assertForbidden();
        $this->actingAs($r)->post('/employees', $this->payload())->assertForbidden();
        $this->actingAs($r)->put("/employees/{$e->id}", $this->payload())->assertForbidden();
        $this->actingAs($r)->get("/employees/{$e->id}/schedule")->assertForbidden();
    }

    public function test_user_account_link_is_unique(): void
    {
        $hr = User::factory()->superAdmin()->create();
        $u = User::factory()->create();
        Employee::factory()->create(['user_id' => $u->id]);
        $this->actingAs($hr)->post('/employees', $this->payload(['user_id' => $u->id]))->assertSessionHasErrors('user_id');
    }

    public function test_validation(): void
    {
        $hr = User::factory()->superAdmin()->create();
        $this->actingAs($hr)->post('/employees', $this->payload(['name' => '', 'calendar_color' => 'red', 'commission_rate' => 150]))->assertSessionHasErrors(['name', 'calendar_color', 'commission_rate']);
        $this->actingAs($hr)->post('/employees', $this->payload(['status' => 'terminated']))->assertSessionHasErrors('termination_date');
    }

    public function test_status_change_is_audited_with_reason_and_terminate_blocked_by_future_appointments(): void
    {
        $this->freezeSalonTime();
        $hr = User::factory()->superAdmin()->create();
        $e = $this->stylist();
        $s = $this->service();
        app(AppointmentBookingService::class)->create($this->bookingPayload($this->customer(), $e, $s) + ['start_at' => '2026-10-20 10:00'], $hr);

        $this->actingAs($hr)->put("/employees/{$e->id}", $this->payload(['name' => $e->name, 'status' => 'terminated', 'termination_date' => '2026-10-30']))->assertSessionHasErrors('status');
        $this->actingAs($hr)->put("/employees/{$e->id}", $this->payload(['name' => $e->name, 'status' => 'suspended', 'status_reason' => 'Investigation']))->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.status_changed', 'reason' => 'Investigation']);
    }

    public function test_weekly_schedule_update_and_validation(): void
    {
        $hr = User::factory()->withPermissions('employees.view', 'employees.schedule')->create();
        $e = Employee::factory()->create();
        $this->actingAs($hr)->put("/employees/{$e->id}/schedule", ['days' => [1 => [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']], 2 => [['start' => '10:00', 'end' => '16:00']]]])->assertSessionDoesntHaveErrors();
        $this->assertSame(3, $e->schedules()->count());
        $this->actingAs($hr)->put("/employees/{$e->id}/schedule", ['days' => [1 => [['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '18:00']]]])->assertSessionHasErrors('days');
        $this->actingAs($hr)->put("/employees/{$e->id}/schedule", ['days' => [1 => [['start' => '15:00', 'end' => '09:00']]]])->assertSessionHasErrors('days');
        $this->assertSame(3, $e->schedules()->count(), 'failed update must not wipe existing hours');
    }

    public function test_shifts_override_weekly_schedule(): void
    {
        $this->freezeSalonTime();
        $hr = User::factory()->withPermissions('employees.view', 'employees.schedule')->create();
        $e = $this->stylist();
        $this->actingAs($hr)->post("/employees/{$e->id}/shifts", ['shift_date' => '2026-10-21', 'type' => 'off'])->assertSessionDoesntHaveErrors();
        $this->actingAs($hr)->post("/employees/{$e->id}/shifts", ['shift_date' => '2026-10-22', 'type' => 'work', 'start_time' => '12:00', 'end_time' => '15:00'])->assertSessionDoesntHaveErrors();
        $this->actingAs($hr)->post("/employees/{$e->id}/shifts", ['shift_date' => '2026-10-10', 'type' => 'off'])->assertSessionHasErrors('shift_date');
        $av = app(AvailabilityService::class);
        $this->assertSame([], $av->workingWindows($e, Carbon::parse('2026-10-21')));
        $w = $av->workingWindows($e, Carbon::parse('2026-10-22'));
        $this->assertSame('12:00', $w[0][0]->format('H:i'));
        $this->assertSame('15:00', $w[0][1]->format('H:i'));
        $this->actingAs($hr)->get('/schedules')->assertOk();
    }

    public function test_cannot_delete_other_employees_shift(): void
    {
        $this->freezeSalonTime();
        $hr = User::factory()->withPermissions('employees.schedule')->create();
        $a = Employee::factory()->create();
        $b = Employee::factory()->create();
        $shift = $b->shifts()->create(['shift_date' => '2026-10-25', 'type' => 'off']);
        $this->actingAs($hr)->delete("/employees/{$a->id}/shifts/{$shift->id}")->assertNotFound();
    }
}
