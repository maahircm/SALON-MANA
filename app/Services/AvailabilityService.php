<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\AppointmentService;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers "when can this stylist / room be booked?". All times are in the salon timezone
 * (config app.timezone), which is also how datetimes are stored.
 */
class AvailabilityService
{
    public function __construct(private SettingsService $settings) {}

    /** Opening window [open, close] for a date, or null when the salon is closed. */
    public function businessWindow(CarbonInterface $date): ?array
    {
        $hours = $this->settings->get('booking.business_hours', [])[$date->dayOfWeek] ?? null;
        if (! $hours || ! empty($hours['closed'])) {
            return null;
        }
        $day = CarbonImmutable::parse($date->toDateString());

        return [$day->setTimeFromTimeString($hours['open']), $day->setTimeFromTimeString($hours['close'])];
    }

    /**
     * Working windows for an employee on a date: dated shifts override the weekly schedule
     * ("off" shift = day off). Result is intersected with business hours.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function workingWindows(Employee $employee, CarbonInterface $date): array
    {
        $business = $this->businessWindow($date);
        if (! $business || $employee->isOnLeave($date)) {
            return [];
        }
        $day = CarbonImmutable::parse($date->toDateString());
        $shifts = $employee->shifts()->whereDate('shift_date', $day->toDateString())->get();

        if ($shifts->isNotEmpty()) {
            if ($shifts->contains('type', 'off')) {
                return [];
            }
            $blocks = $shifts->filter(fn ($s) => $s->start_time && $s->end_time)->map(fn ($s) => [$s->start_time, $s->end_time]);
        } else {
            $blocks = $employee->schedules()->where('weekday', $day->dayOfWeek)->get()->map(fn ($s) => [$s->start_time, $s->end_time]);
        }

        $windows = [];
        foreach ($blocks as [$from, $to]) {
            $start = $day->setTimeFromTimeString($from);
            $end = $day->setTimeFromTimeString($to);
            $start = $start->max($business[0]);
            $end = $end->min($business[1]);
            if ($start->lt($end)) {
                $windows[] = [$start, $end];
            }
        }

        return $windows;
    }

    public function worksDuring(Employee $employee, CarbonInterface $start, CarbonInterface $end): bool
    {
        if (! $start->isSameDay($end)) {
            return false;
        }
        foreach ($this->workingWindows($employee, $start) as [$from, $to]) {
            if ($start->gte($from) && $end->lte($to)) {
                return true;
            }
        }

        return false;
    }

    /** True when another active booking overlaps (including the configured buffer) for this employee. */
    public function employeeHasConflict(int $employeeId, CarbonInterface $start, CarbonInterface $end, ?int $ignoreAppointmentId = null, bool $lock = false): bool
    {
        if ($this->settings->get('booking.allow_stylist_overlap', false)) {
            return false;
        }

        return $this->conflicts($this->conflictQuery('employee_id', $employeeId, $start, $end, $ignoreAppointmentId), $lock);
    }

    public function roomHasConflict(int $roomId, CarbonInterface $start, CarbonInterface $end, ?int $ignoreAppointmentId = null, bool $lock = false): bool
    {
        return $this->conflicts($this->conflictQuery('room_id', $roomId, $start, $end, $ignoreAppointmentId), $lock);
    }

    /**
     * With $lock the check is a locking read (SELECT ... FOR UPDATE). That always sees the latest committed rows,
     * even when the surrounding transaction's snapshot is older, so two concurrent bookings cannot both pass.
     *
     * @param  Builder<AppointmentService>  $query
     */
    private function conflicts($query, bool $lock): bool
    {
        return $lock ? $query->lockForUpdate()->first() !== null : $query->exists();
    }

    private function conflictQuery(string $column, int $id, CarbonInterface $start, CarbonInterface $end, ?int $ignoreId)
    {
        $buffer = (int) $this->settings->get('booking.buffer_minutes', 0);

        return AppointmentService::query()
            ->where($column, $id)
            ->where('start_at', '<', $end->copy()->addMinutes($buffer))
            ->where('end_at', '>', $start->copy()->subMinutes($buffer))
            ->whereHas('appointment', function ($q) use ($ignoreId) {
                $q->whereIn('status', AppointmentStatus::blockingValues());
                if ($ignoreId) {
                    $q->where('id', '!=', $ignoreId);
                }
            });
    }

    /**
     * Free start times for one employee on a date able to fit $durationMinutes.
     *
     * @return string[] H:i values
     */
    public function freeSlots(Employee $employee, CarbonInterface $date, int $durationMinutes, ?int $ignoreAppointmentId = null): array
    {
        if (! $employee->isAvailableForBooking()) {
            return [];
        }
        $step = max(5, (int) $this->settings->get('booking.slot_minutes', 15));
        $slots = [];
        foreach ($this->workingWindows($employee, $date) as [$from, $to]) {
            for ($t = $from; $t->copy()->addMinutes($durationMinutes)->lte($to); $t = $t->addMinutes($step)) {
                $end = $t->addMinutes($durationMinutes);
                if ($t->lt(now()) || $this->employeeHasConflict($employee->id, $t, $end, $ignoreAppointmentId)) {
                    continue;
                }
                $slots[] = $t->format('H:i');
            }
        }

        return $slots;
    }
}
