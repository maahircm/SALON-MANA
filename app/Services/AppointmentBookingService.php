<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and reschedules appointments. Every availability rule is evaluated inside a transaction after
 * locking the involved employee and room rows, so two concurrent requests cannot reserve the same slot.
 */
class AppointmentBookingService
{
    public function __construct(
        private AvailabilityService $availability,
        private SettingsService $settings,
        private AppointmentStatusService $statuses,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{customer_id:int, start_at:string, notes?:?string, source?:string, lines:array<int, array{service_id:int, employee_id:int, room_id?:?int}>}  $data
     */
    public function create(array $data, User $actor, bool $override = false): Appointment
    {
        return DB::transaction(function () use ($data, $actor, $override) {
            $customer = Customer::withTrashed()->findOrFail($data['customer_id']);
            $walkIn = ($data['source'] ?? 'booking') === 'walk_in';
            $lines = $this->prepareLines($data, null, $override, $walkIn);
            $this->assertCustomer($customer, $lines, null);

            $appointment = new Appointment(['notes' => $data['notes'] ?? null]);
            $appointment->forceFill([
                'customer_id' => $customer->id,
                'start_at' => $lines[0]['start_at'],
                'end_at' => end($lines)['end_at'],
                'status' => AppointmentStatus::Pending,
                'source' => $walkIn ? 'walk_in' : 'booking',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'reminder_at' => $this->reminderFor($lines[0]['start_at']),
            ])->save();
            $appointment->forceFill(['appointment_number' => sprintf('APT-%06d', $appointment->id)])->save();
            $appointment->lines()->createMany($lines);

            $this->statuses->record($appointment, null, AppointmentStatus::Pending, $actor, 'Booked');
            if ($walkIn) {
                // Walk-ins are in the salon already: confirm and check in immediately.
                $this->statuses->forceInitial($appointment, $actor);
            }
            $this->audit->log('appointment.created', $appointment, null, [
                'customer_id' => $customer->id, 'start_at' => (string) $appointment->start_at, 'source' => $appointment->source,
                'lines' => $this->snapshot($lines),
            ]);

            return $appointment->refresh()->load('lines');
        });
    }

    /** Replace the services / time of a not-yet-started appointment. */
    public function reschedule(Appointment $appointment, array $data, User $actor, bool $override = false, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $data, $actor, $override, $reason) {
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($appointment, $override, $reason);

            $lines = $this->prepareLines($data + ['customer_id' => $appointment->customer_id], $appointment->id, $override, false);
            $this->assertCustomer($appointment->customer, $lines, $appointment->id);

            $before = ['start_at' => (string) $appointment->start_at, 'lines' => $appointment->lines->map(fn ($l) => [
                'service_id' => $l->service_id, 'employee_id' => $l->employee_id, 'room_id' => $l->room_id, 'start_at' => $l->start_at->format('Y-m-d H:i:s'),
            ])->all()];
            $appointment->lines()->delete();
            $appointment->lines()->createMany($lines);
            $appointment->forceFill([
                'start_at' => $lines[0]['start_at'],
                'end_at' => end($lines)['end_at'],
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $appointment->notes,
                'updated_by' => $actor->id,
                'reminder_at' => $this->reminderFor($lines[0]['start_at']),
            ])->save();
            $this->audit->log('appointment.rescheduled', $appointment, $before, [
                'start_at' => (string) $appointment->start_at,
                'lines' => $this->snapshot($lines),
            ], $reason);

            return $appointment->refresh()->load('lines');
        });
    }

    /** Completed appointments can only be touched with the override permission and a reason. */
    public function assertEditable(Appointment $appointment, bool $override, ?string $reason): void
    {
        $status = $appointment->status;
        if (in_array($status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
            throw ValidationException::withMessages(['appointment' => 'Cancelled and no-show appointments cannot be edited. Book a new appointment instead.']);
        }
        if (in_array($status, [AppointmentStatus::Completed, AppointmentStatus::InService, AppointmentStatus::CheckedIn], true)) {
            if (! $override) {
                throw ValidationException::withMessages(['appointment' => "A {$status->label()} appointment can only be changed by a user with the override permission."]);
            }
            if (blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A reason is required to change this appointment.']);
            }
        }
    }

    /**
     * Validates and builds appointment_services rows (sequential, in the order given).
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareLines(array $data, ?int $ignoreId, bool $override, bool $walkIn): array
    {
        $rawLines = array_values($data['lines'] ?? []);
        if ($rawLines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one service.']);
        }
        $cursor = CarbonImmutable::parse($data['start_at']);
        $errors = [];

        if (! $override && ! $walkIn && $cursor->lt(now()->subMinutes(1))) {
            $errors['start_at'] = 'The appointment cannot start in the past.';
        }
        $maxDays = (int) $this->settings->get('booking.max_advance_days', 90);
        if (! $override && $cursor->gt(now()->addDays($maxDays))) {
            $errors['start_at'] = "Appointments can be booked at most {$maxDays} days in advance.";
        }

        // Lock rows first (consistent id order avoids deadlocks) so the overlap checks below are serialised.
        $employeeIds = collect($rawLines)->pluck('employee_id')->unique()->sort()->values();
        $employees = Employee::with('skills')->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $roomIds = collect($rawLines)->pluck('room_id')->filter()->unique()->sort()->values();
        $rooms = Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $services = Service::whereIn('id', collect($rawLines)->pluck('service_id'))->get()->keyBy('id');

        $out = [];
        foreach ($rawLines as $i => $raw) {
            $n = $i + 1;
            $service = $services->get((int) $raw['service_id']);
            $employee = $employees->get((int) $raw['employee_id']);
            if (! $service || ! $service->is_active) {
                $errors["lines.$i.service_id"] = "Service #$n is not available.";

                continue;
            }
            if (! $employee || ! $employee->isAvailableForBooking()) {
                $errors["lines.$i.employee_id"] = "The stylist for service #$n cannot take bookings.";

                continue;
            }
            if (! $employee->canPerform($service)) {
                $errors["lines.$i.employee_id"] = "{$employee->name} does not have the skill required for {$service->name}.";

                continue;
            }
            $start = $cursor;
            $end = $start->addMinutes($service->duration_minutes);
            $cursor = $end;

            if (! $this->availability->worksDuring($employee, $start, $end)) {
                $errors["lines.$i.employee_id"] = "{$employee->name} is not working (or the salon is closed) at {$start->format('D H:i')}–{$end->format('H:i')}.";
            } elseif ($this->availability->employeeHasConflict($employee->id, $start, $end, $ignoreId)) {
                $errors["lines.$i.employee_id"] = "{$employee->name} already has an appointment that overlaps {$start->format('H:i')}–{$end->format('H:i')}.";
            }

            $roomId = $raw['room_id'] ?? null;
            if ($roomId) {
                $room = $rooms->get((int) $roomId);
                if (! $room || ! $room->is_active) {
                    $errors["lines.$i.room_id"] = "The chair/room for service #$n is not available.";
                } elseif ($this->availability->roomHasConflict($room->id, $start, $end, $ignoreId)) {
                    $errors["lines.$i.room_id"] = "{$room->name} is already allocated during {$start->format('H:i')}–{$end->format('H:i')}.";
                }
            }

            $out[] = [
                'service_id' => $service->id,
                'employee_id' => $employee->id,
                'room_id' => $roomId ?: null,
                'service_name' => $service->name,
                'start_at' => $start,
                'end_at' => $end,
                'duration_minutes' => $service->duration_minutes,
                'unit_price' => $service->currentPrice($start),
                'tax_rate' => $service->tax_rate,
                'sort_order' => $i,
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** Compact, JSON-safe line description for audit entries. */
    private function snapshot(array $lines): array
    {
        return collect($lines)->map(fn ($l) => [
            'service_id' => $l['service_id'], 'employee_id' => $l['employee_id'], 'room_id' => $l['room_id'],
            'start_at' => $l['start_at']->format('Y-m-d H:i:s'),
        ])->all();
    }

    private function assertCustomer(Customer $customer, array $lines, ?int $ignoreId): void
    {
        if (! $customer->isBookable()) {
            throw ValidationException::withMessages(['customer_id' => 'This customer is archived, inactive or blocked and cannot be booked.']);
        }
        $start = $lines[0]['start_at'];
        $end = end($lines)['end_at'];
        $overlap = Appointment::where('customer_id', $customer->id)
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->where('start_at', '<', $end)->where('end_at', '>', $start)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['customer_id' => 'This customer already has an appointment at that time.']);
        }
    }

    private function reminderFor(CarbonImmutable $start): ?CarbonImmutable
    {
        $hours = (int) $this->settings->get('booking.reminder_hours_before', 24);
        $at = $start->subHours($hours);

        return $at->isPast() ? null : $at;
    }
}
