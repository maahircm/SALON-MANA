<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentStatusService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Concerns;
use Tests\TestCase;

class AppointmentFlowTest extends TestCase
{
    use Concerns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
    }

    private function appt(?Employee $e = null, string $start = '2026-10-20 10:00', ?User $by = null): Appointment
    {
        $e ??= $this->stylist();

        return app(AppointmentBookingService::class)->create(['customer_id' => $this->customer()->id, 'start_at' => $start,
            'lines' => [['service_id' => $this->service()->id, 'employee_id' => $e->id]]], $by ?? User::factory()->superAdmin()->create());
    }

    public function test_receptionist_books_via_http_and_sees_it_on_calendar_feed(): void
    {
        $r = $this->receptionist();
        $e = $this->stylist();
        $s = $this->service(60, '25.00');
        $c = $this->customer();
        $this->actingAs($r)->get('/appointments/calendar')->assertOk();
        $this->actingAs($r)->get('/appointments/create')->assertOk();
        $resp = $this->actingAs($r)->post('/appointments', $this->bookingPayload($c, $e, $s));
        $a = Appointment::firstOrFail();
        $resp->assertRedirect("/appointments/{$a->id}");
        $this->actingAs($r)->get("/appointments/{$a->id}")->assertOk()->assertSee($s->name)->assertSee('$25.00');
        $feed = $this->actingAs($r)->getJson('/appointments/events?start=2026-10-19T00:00:00%2B03:00&end=2026-10-26T00:00:00%2B03:00')->assertOk();
        $feed->assertJsonCount(1)->assertJsonPath('0.id', $a->id);
    }

    public function test_http_double_booking_returns_validation_error_and_preserves_input(): void
    {
        $r = $this->receptionist();
        $e = $this->stylist();
        $s = $this->service(60);
        $this->appt($e);
        $this->actingAs($r)->from('/appointments/create')->post('/appointments', $this->bookingPayload($this->customer(), $e, $s))
            ->assertRedirect('/appointments/create')->assertSessionHasErrors('lines.0.employee_id')->assertSessionHasInput('time', '10:00');
        $this->assertSame(1, Appointment::count());
    }

    public function test_booking_validation(): void
    {
        $r = $this->receptionist();
        $this->actingAs($r)->post('/appointments', ['date' => 'bad', 'time' => '25:99', 'lines' => []])->assertSessionHasErrors(['customer_id', 'date', 'time', 'lines']);
    }

    public function test_user_without_create_permission_cannot_book(): void
    {
        $u = User::factory()->withPermissions('appointments.view')->create();
        $e = $this->stylist();
        $this->actingAs($u)->post('/appointments', $this->bookingPayload($this->customer(), $e, $this->service()))->assertForbidden();
        $this->actingAs($u)->get('/appointments/create')->assertForbidden();
        $this->actingAs($u)->getJson('/appointments/availability?employee_id='.$e->id.'&date=2026-10-20')->assertForbidden();
    }

    public function test_valid_and_invalid_status_transitions(): void
    {
        $by = User::factory()->superAdmin()->create();
        $a = $this->appt(null, '2026-10-19 09:00', $by);
        $st = app(AppointmentStatusService::class);
        $this->assertTrue(AppointmentStatus::Pending->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertFalse(AppointmentStatus::Pending->canTransitionTo(AppointmentStatus::Completed));
        foreach ([AppointmentStatus::InService, AppointmentStatus::Completed, AppointmentStatus::CheckedIn] as $bad) {
            try {
                $st->transition($a, $bad, $by);
                $this->fail("pending -> {$bad->value} must be rejected");
            } catch (ValidationException) {
                $this->assertSame(AppointmentStatus::Pending, $a->fresh()->status);
            }
        }
        foreach ([AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InService, AppointmentStatus::Completed] as $to) {
            $a = $st->transition($a, $to, $by);
        }
        $this->assertNotNull($a->checked_in_at);
        $this->assertNotNull($a->service_started_at);
        $this->assertNotNull($a->completed_at);
        $this->assertSame(5, $a->histories()->count());
        $this->expectException(ValidationException::class);
        $st->transition($a, AppointmentStatus::Cancelled, $by, 'too late');
    }

    public function test_final_states_are_terminal(): void
    {
        foreach ([AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow] as $final) {
            $this->assertSame([], $final->allowedNext());
        }
        $this->assertSame(7, count(AppointmentStatus::cases()));
    }

    public function test_cancellation_requires_reason_and_flags_late_cancellations(): void
    {
        $by = User::factory()->superAdmin()->create();
        $st = app(AppointmentStatusService::class);
        $late = $this->appt(null, '2026-10-19 12:00', $by); // 4h away, notice is 24h
        $this->expectExceptionMessageMatches('/reason/');
        try {
            $st->transition($late, AppointmentStatus::Cancelled, $by, '');
        } finally {
            $late = $st->transition($late, AppointmentStatus::Cancelled, $by, 'Sick');
            $this->assertTrue($late->is_late_cancellation);
            $this->assertSame('Sick', $late->cancellation_reason);
            $this->assertNull($late->reminder_at);
            $early = $st->transition($this->appt(null, '2026-10-24 10:00', $by), AppointmentStatus::Cancelled, $by, 'Plans changed');
            $this->assertFalse($early->is_late_cancellation);
        }
    }

    public function test_late_cancellation_can_be_blocked_by_policy_unless_override(): void
    {
        $by = User::factory()->superAdmin()->create();
        app(SettingsService::class)->set('booking.late_cancellation_action', 'block');
        $st = app(AppointmentStatusService::class);
        $a = $this->appt(null, '2026-10-19 12:00', $by);
        try {
            $st->transition($a, AppointmentStatus::Cancelled, $by, 'x');
            $this->fail('should be blocked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $this->assertSame(AppointmentStatus::Pending, $a->fresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $st->transition($a, AppointmentStatus::Cancelled, $by, 'Manager override', true)->status);
    }

    public function test_no_show_only_after_start_and_checkin_only_same_day(): void
    {
        $by = User::factory()->superAdmin()->create();
        $st = app(AppointmentStatusService::class);
        $future = $this->appt(null, '2026-10-21 10:00', $by);
        $this->assertTrue($this->fails(fn () => $st->transition($future, AppointmentStatus::NoShow, $by)));
        $future = $st->transition($future, AppointmentStatus::Confirmed, $by);
        $this->assertTrue($this->fails(fn () => $st->transition($future, AppointmentStatus::CheckedIn, $by)), 'check-in two days early');
        $today = $this->appt(null, '2026-10-19 09:00', $by);
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:30', config('app.timezone')));
        $this->assertSame(AppointmentStatus::NoShow, $st->transition($today, AppointmentStatus::NoShow, $by)->status);
    }

    private function fails(callable $f): bool
    {
        try {
            $f();
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    public function test_status_change_via_http_requires_status_permission_and_cancel_permission(): void
    {
        $a = $this->appt();
        $viewer = User::factory()->withPermissions('appointments.view', 'appointments.all')->create();
        $this->actingAs($viewer)->post("/appointments/{$a->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $statusOnly = User::factory()->withPermissions('appointments.view', 'appointments.all', 'appointments.status')->create();
        $this->actingAs($statusOnly)->post("/appointments/{$a->id}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($statusOnly)->post("/appointments/{$a->id}/status", ['status' => 'confirmed'])->assertRedirect();
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->actingAs($statusOnly)->post("/appointments/{$a->id}/status", ['status' => 'bogus'])->assertSessionHasErrors('status');
    }

    public function test_completing_an_appointment_does_not_mark_anything_paid(): void
    {
        $this->assertFalse(Schema::hasColumn('appointments', 'paid_at'));
        $this->assertFalse(Schema::hasColumn('appointments', 'payment_status'));
    }

    public function test_stylist_sees_only_own_appointments(): void
    {
        $user = User::factory()->withPermissions('appointments.view', 'appointments.status')->create();
        $mine = Employee::factory()->workingAllWeek()->create(['user_id' => $user->id]);
        $other = $this->stylist();
        $a1 = $this->appt($mine);
        $a2 = $this->appt($other, '2026-10-21 10:00');
        $this->actingAs($user)->get("/appointments/{$a1->id}")->assertOk();
        $this->actingAs($user)->get("/appointments/{$a2->id}")->assertForbidden();
        $this->actingAs($user)->post("/appointments/{$a2->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->assertSame(AppointmentStatus::Pending, $a2->fresh()->status);
        $this->actingAs($user)->get('/appointments/list')->assertSee($a1->appointment_number)->assertDontSee($a2->appointment_number);
        $this->actingAs($user)->getJson('/appointments/events?start=2026-10-19T00:00:00%2B03:00&end=2026-10-30T00:00:00%2B03:00')->assertJsonCount(1);
        // a stylist with no linked employee record sees nothing
        $orphan = User::factory()->withPermissions('appointments.view')->create();
        $this->actingAs($orphan)->getJson('/appointments/events?start=2026-10-19T00:00:00%2B03:00&end=2026-10-30T00:00:00%2B03:00')->assertJsonCount(0);
    }

    public function test_cancelled_appointments_cannot_be_edited_over_http(): void
    {
        $by = User::factory()->superAdmin()->create();
        $a = $this->appt(null, '2026-10-22 10:00', $by);
        app(AppointmentStatusService::class)->transition($a, AppointmentStatus::Cancelled, $by, 'x');
        $this->actingAs($by)->get("/appointments/{$a->id}/edit")->assertSessionHasErrors('appointment');
    }

    public function test_customer_history_lists_appointments(): void
    {
        $by = User::factory()->superAdmin()->create();
        $a = $this->appt(null, '2026-10-20 10:00', $by);
        $this->actingAs($by)->get("/customers/{$a->customer_id}")->assertOk()->assertSee($a->appointment_number);
    }

    public function test_availability_endpoint(): void
    {
        $r = $this->receptionist();
        $e = $this->stylist('09:00', '11:00');
        $this->actingAs($r)->getJson("/appointments/availability?employee_id={$e->id}&date=2026-10-20&duration=60")->assertOk()->assertJsonPath('slots.0', '09:00')->assertJsonCount(5, 'slots');
    }

    public function test_booking_settings_authorization_and_effect(): void
    {
        $r = $this->receptionist();
        $this->actingAs($r)->get('/settings/booking')->assertForbidden();
        $owner = User::factory()->withPermissions('settings.manage')->create();
        $hours = collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => ['open' => '08:00', 'close' => '20:00']])->all();
        $this->actingAs($owner)->get('/settings/booking')->assertOk();
        $this->actingAs($owner)->put('/settings/booking', ['hours' => $hours, 'closed' => [5 => 1], 'buffer_minutes' => 10, 'slot_minutes' => 30, 'max_advance_days' => 60,
            'cancellation_notice_hours' => 12, 'late_cancellation_action' => 'block', 'reminder_hours_before' => 6])->assertSessionDoesntHaveErrors();
        $s = app(SettingsService::class);
        $s->flush();
        $this->assertSame(10, $s->get('booking.buffer_minutes'));
        $this->assertSame('08:00', $s->get('booking.business_hours')[2]['open']);
        $this->assertTrue($s->get('booking.business_hours')[5]['closed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.booking_updated']);
        $this->actingAs($owner)->put('/settings/booking', ['hours' => $hours, 'buffer_minutes' => 10, 'slot_minutes' => 7, 'max_advance_days' => 60,
            'cancellation_notice_hours' => 12, 'late_cancellation_action' => 'flag', 'reminder_hours_before' => 6])->assertSessionHasErrors('slot_minutes');
    }

    public function test_times_are_in_salon_timezone(): void
    {
        $this->assertSame('Africa/Mogadishu', config('app.timezone'));
        $a = $this->appt(null, '2026-10-20 10:00');
        $this->assertSame('+03:00', $a->start_at->format('P'));
        $this->assertSame('10:00', $a->fresh()->start_at->format('H:i'));
    }

    public function test_rooms_management_permissions(): void
    {
        $this->actingAs($this->receptionist())->post('/rooms', ['name' => 'Chair 1', 'type' => 'chair'])->assertForbidden();
        $mgr = User::factory()->withPermissions('employees.schedule')->create();
        $this->actingAs($mgr)->post('/rooms', ['name' => 'Chair 1', 'type' => 'chair'])->assertSessionDoesntHaveErrors();
        $this->actingAs($mgr)->post('/rooms', ['name' => 'Chair 1', 'type' => 'chair'])->assertSessionHasErrors('name');
    }
}
