<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Room;
use App\Models\Skill;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentStatusService;
use App\Services\AvailabilityService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private AppointmentBookingService $booking;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $this->booking = app(AppointmentBookingService::class);
        $this->actor = User::factory()->superAdmin()->create();
    }

    private function book(array $lines, string $start = '2026-10-20 10:00', $customer = null, bool $override = false, array $extra = []): Appointment
    {
        return $this->booking->create(['customer_id' => ($customer ?? $this->customer())->id, 'start_at' => $start, 'lines' => $lines] + $extra, $this->actor, $override);
    }

    private function line($service, $employee, $room = null): array
    {
        return ['service_id' => $service->id, 'employee_id' => $employee->id, 'room_id' => $room?->id];
    }

    private function assertRejected(callable $fn, ?string $errorKey = null): ValidationException
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            if ($errorKey) {
                $this->assertTrue(collect($e->errors())->keys()->contains(fn ($k) => str_starts_with($k, $errorKey)), 'Expected error on '.$errorKey.' got '.json_encode($e->errors()));
            }

            return $e;
        }
        $this->fail('Expected booking to be rejected');
    }

    public function test_books_appointment_with_snapshots_number_and_history(): void
    {
        $e = $this->stylist();
        $s = $this->service(45, '30.00', ['tax_rate' => '5.00']);
        $a = $this->book([$this->line($s, $e)]);
        $this->assertSame(sprintf('APT-%06d', $a->id), $a->appointment_number);
        $this->assertSame(AppointmentStatus::Pending, $a->status);
        $this->assertSame('2026-10-20 10:45:00', $a->end_at->format('Y-m-d H:i:s'));
        $line = $a->lines->first();
        $this->assertSame('30.00', $line->unit_price);
        $this->assertSame('5.00', $line->tax_rate);
        $this->assertSame($s->name, $line->service_name);
        $this->assertDatabaseHas('appointment_status_histories', ['appointment_id' => $a->id, 'to_status' => 'pending']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.created']);
        $this->assertNotNull($a->reminder_at);
    }

    public function test_price_and_name_snapshot_survive_later_service_changes(): void
    {
        $e = $this->stylist();
        $s = $this->service(60, '30.00');
        $a = $this->book([$this->line($s, $e)]);
        $s->forceFill(['base_price' => '99.00', 'name' => 'Renamed', 'duration_minutes' => 120])->save();
        $line = $a->fresh()->lines->first();
        $this->assertSame('30.00', $line->unit_price);
        $this->assertSame(60, $line->duration_minutes);
        $this->assertNotSame('Renamed', $line->service_name);
    }

    public function test_active_promotion_price_is_used_at_booking_time(): void
    {
        $e = $this->stylist();
        $s = $this->service(60, '40.00');
        $s->forceFill(['promo_price' => '30.00', 'promo_starts_on' => '2026-10-19', 'promo_ends_on' => '2026-10-21'])->save();
        $this->assertSame('30.00', $this->book([$this->line($s, $e)])->lines->first()->unit_price);
        $this->assertSame('40.00', $this->book([$this->line($s, $e)], '2026-10-22 10:00')->lines->first()->unit_price);
    }

    public function test_multiple_services_are_sequential_with_individual_stylists(): void
    {
        $e1 = $this->stylist();
        $e2 = $this->stylist();
        $s1 = $this->service(60);
        $s2 = $this->service(30);
        $a = $this->book([$this->line($s1, $e1), $this->line($s2, $e2)]);
        [$l1, $l2] = $a->lines->all();
        $this->assertSame('10:00', $l1->start_at->format('H:i'));
        $this->assertSame('11:00', $l2->start_at->format('H:i'));
        $this->assertSame($e2->id, $l2->employee_id);
        $this->assertSame('11:30', $a->end_at->format('H:i'));
    }

    public function test_double_booking_same_stylist_is_rejected(): void
    {
        $e = $this->stylist();
        $s = $this->service(60);
        $this->book([$this->line($s, $e)]);
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-20 10:30'), 'lines.0.employee_id');
        $this->assertSame(1, Appointment::count());
    }

    public function test_back_to_back_is_allowed_without_buffer_and_rejected_with_buffer(): void
    {
        $e = $this->stylist();
        $s = $this->service(60);
        $this->book([$this->line($s, $e)]);
        $this->book([$this->line($s, $e)], '2026-10-20 11:00');
        app(SettingsService::class)->set('booking.buffer_minutes', 15);
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-20 12:05'), 'lines.0.employee_id');
        $this->book([$this->line($s, $e)], '2026-10-20 12:15');
        $this->assertSame(3, Appointment::count());
    }

    public function test_overlap_allowed_only_when_setting_enabled(): void
    {
        $e = $this->stylist();
        $s = $this->service(60);
        $this->book([$this->line($s, $e)]);
        app(SettingsService::class)->set('booking.allow_stylist_overlap', true);
        $this->book([$this->line($s, $e)], '2026-10-20 10:00');
        $this->assertSame(2, Appointment::count());
    }

    public function test_cancelled_and_no_show_appointments_free_the_slot(): void
    {
        $e = $this->stylist();
        $s = $this->service(60);
        $a = $this->book([$this->line($s, $e)]);
        app(AppointmentStatusService::class)->transition($a, AppointmentStatus::Cancelled, $this->actor, 'Customer called');
        $this->book([$this->line($s, $e)]);
        $this->assertSame(2, Appointment::count());
    }

    public function test_room_conflict_is_rejected_across_different_stylists(): void
    {
        $e1 = $this->stylist();
        $e2 = $this->stylist();
        $s = $this->service(60);
        $room = Room::factory()->create();
        $this->book([$this->line($s, $e1, $room)]);
        $this->assertRejected(fn () => $this->book([$this->line($s, $e2, $room)], '2026-10-20 10:30'), 'lines.0.room_id');
        $this->book([$this->line($s, $e2, Room::factory()->create())], '2026-10-20 10:30');
    }

    public function test_inactive_room_rejected(): void
    {
        $e = $this->stylist();
        $this->assertRejected(fn () => $this->book([$this->line($this->service(), $e, Room::factory()->create(['is_active' => false]))]), 'lines.0.room_id');
    }

    public function test_outside_stylist_working_hours_is_rejected(): void
    {
        $e = $this->stylist('10:00', '14:00');
        $s = $this->service(60);
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-20 09:00'), 'lines.0.employee_id');
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-20 13:30'), 'lines.0.employee_id');
        $this->book([$this->line($s, $e)], '2026-10-20 13:00');
    }

    public function test_stylist_without_schedule_cannot_be_booked(): void
    {
        $e = Employee::factory()->create();
        $this->assertRejected(fn () => $this->book([$this->line($this->service(), $e)]), 'lines.0.employee_id');
    }

    public function test_salon_closed_day_and_hours_are_enforced(): void
    {
        $e = $this->stylist('06:00', '23:00');
        $s = $this->service(60);
        // default hours 09:00-18:00, Friday closed (2026-10-23 is a Friday)
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-23 10:00'), 'lines.0.employee_id');
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-20 08:00'), 'lines.0.employee_id');
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-20 17:30'), 'lines.0.employee_id');
    }

    public function test_service_must_fit_before_closing_across_multiple_lines(): void
    {
        $e = $this->stylist();
        $s = $this->service(60);
        $this->assertRejected(fn () => $this->book([$this->line($s, $e), $this->line($s, $e)], '2026-10-20 16:30'), 'lines.1.employee_id');
        $this->assertSame(0, Appointment::count(), 'nothing may be persisted when any line fails');
        $this->assertDatabaseCount('appointment_services', 0);
    }

    public function test_day_off_shift_blocks_booking(): void
    {
        $e = $this->stylist();
        $e->shifts()->create(['shift_date' => '2026-10-20', 'type' => 'off']);
        $this->assertRejected(fn () => $this->book([$this->line($this->service(), $e)]), 'lines.0.employee_id');
    }

    public function test_required_skill_is_enforced(): void
    {
        $skill = Skill::factory()->create();
        $s = $this->service(60, '50.00', ['required_skill_id' => $skill->id]);
        $unskilled = $this->stylist();
        $skilled = $this->stylist();
        $skilled->skills()->attach($skill);
        $this->assertRejected(fn () => $this->book([$this->line($s, $unskilled)]), 'lines.0.employee_id');
        $this->book([$this->line($s, $skilled)]);
    }

    public function test_inactive_service_unbookable_employee_and_archived_customer_rejected(): void
    {
        $e = $this->stylist();
        $this->assertRejected(fn () => $this->book([$this->line($this->service(60, '10', ['is_active' => false]), $e)]), 'lines.0.service_id');
        $e2 = $this->stylist();
        $e2->forceFill(['is_bookable' => false])->save();
        $this->assertRejected(fn () => $this->book([$this->line($this->service(), $e2)]), 'lines.0.employee_id');
        $e3 = $this->stylist();
        $e3->forceFill(['status' => 'suspended'])->save();
        $this->assertRejected(fn () => $this->book([$this->line($this->service(), $e3)]), 'lines.0.employee_id');
        $c = $this->customer();
        $c->delete();
        $this->assertRejected(fn () => $this->book([$this->line($this->service(), $e)], customer: $c), 'customer_id');
        $this->assertRejected(fn () => $this->book([$this->line($this->service(), $e)], customer: $this->customer(['status' => 'blocked'])), 'customer_id');
    }

    public function test_cannot_book_in_the_past_or_too_far_ahead_without_override(): void
    {
        $e = $this->stylist();
        $s = $this->service(30);
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2026-10-19 07:00'), 'start_at');
        $this->assertRejected(fn () => $this->book([$this->line($s, $e)], '2027-03-02 10:00'), 'start_at');
        $this->book([$this->line($s, $e)], '2026-10-19 09:00', override: false);
    }

    public function test_customer_cannot_be_double_booked_with_two_stylists(): void
    {
        $e1 = $this->stylist();
        $e2 = $this->stylist();
        $s = $this->service(60);
        $c = $this->customer();
        $this->book([$this->line($s, $e1)], customer: $c);
        $this->assertRejected(fn () => $this->book([$this->line($s, $e2)], '2026-10-20 10:30', $c), 'customer_id');
    }

    public function test_walk_in_is_checked_in_immediately_and_allowed_now(): void
    {
        $e = $this->stylist();
        $s = $this->service(30);
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:07', config('app.timezone')));
        $a = $this->book([$this->line($s, $e)], '2026-10-19 10:00', extra: ['source' => 'walk_in']);
        $this->assertSame(AppointmentStatus::CheckedIn, $a->fresh()->status);
        $this->assertNotNull($a->fresh()->checked_in_at);
        $this->assertSame(3, $a->histories()->count());
    }

    public function test_reschedule_excludes_itself_from_conflicts_and_updates_snapshot(): void
    {
        $e = $this->stylist();
        $s = $this->service(60, '30.00');
        $a = $this->book([$this->line($s, $e)]);
        $s->forceFill(['base_price' => '35.00'])->save();
        $a = $this->booking->reschedule($a, ['start_at' => '2026-10-20 10:30', 'lines' => [$this->line($s, $e)]], $this->actor);
        $this->assertSame('10:30', $a->start_at->format('H:i'));
        $this->assertSame(1, $a->lines->count());
        $this->assertSame('35.00', $a->lines->first()->unit_price);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.rescheduled']);
    }

    public function test_reschedule_into_conflict_rolls_back_leaving_original_untouched(): void
    {
        $e = $this->stylist();
        $s = $this->service(60);
        $a = $this->book([$this->line($s, $e)]);
        $this->book([$this->line($s, $e)], '2026-10-20 14:00');
        $this->assertRejected(fn () => $this->booking->reschedule($a, ['start_at' => '2026-10-20 14:30', 'lines' => [$this->line($s, $e)]], $this->actor));
        $a->refresh();
        $this->assertSame('10:00', $a->start_at->format('H:i'));
        $this->assertSame('10:00', $a->lines->first()->start_at->format('H:i'));
    }

    public function test_free_slots_exclude_busy_times(): void
    {
        $e = $this->stylist('09:00', '12:00');
        $s = $this->service(60);
        $this->book([$this->line($s, $e)], '2026-10-20 10:00');
        $slots = app(AvailabilityService::class)->freeSlots($e, Carbon::parse('2026-10-20'), 60);
        $this->assertSame(['09:00', '11:00'], $slots);
    }

    public function test_completed_appointment_is_protected_from_edits(): void
    {
        $e = $this->stylist();
        $s = $this->service(60);
        $a = $this->book([$this->line($s, $e)], '2026-10-19 09:00');
        $st = app(AppointmentStatusService::class);
        foreach ([AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InService, AppointmentStatus::Completed] as $to) {
            $st->transition($a, $to, $this->actor);
        }
        $payload = ['start_at' => '2026-10-20 10:00', 'lines' => [$this->line($s, $e)]];
        $this->assertRejected(fn () => $this->booking->reschedule($a, $payload, $this->actor, false), 'appointment');
        $this->assertRejected(fn () => $this->booking->reschedule($a, $payload, $this->actor, true, null), 'reason');
        $this->booking->reschedule($a, $payload, $this->actor, true, 'Data entry error');
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.rescheduled', 'reason' => 'Data entry error']);
    }
}
