<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\User;
use App\Support\Atomic;
use Illuminate\Validation\ValidationException;

/** The only place an appointment's status changes. Validates the transition and writes history + audit. */
class AppointmentStatusService
{
    public function __construct(private SettingsService $settings, private AuditLogger $audit) {}

    public function transition(Appointment $appointment, AppointmentStatus $to, User $actor, ?string $reason = null, bool $override = false): Appointment
    {
        return Atomic::run(function () use ($appointment, $to, $actor, $reason, $override) {
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $from = $appointment->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => "An appointment cannot move from {$from->label()} to {$to->label()}."]);
            }
            if ($to === AppointmentStatus::Cancelled) {
                if (blank($reason)) {
                    throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
                }
                $this->applyCancellation($appointment, $override);
            }
            if ($to === AppointmentStatus::NoShow && now()->lt($appointment->start_at)) {
                throw ValidationException::withMessages(['status' => 'An appointment can be marked No Show only after its start time.']);
            }
            if ($to === AppointmentStatus::CheckedIn && ! $appointment->start_at->isSameDay(now())) {
                throw ValidationException::withMessages(['status' => 'Check-in is only possible on the day of the appointment.']);
            }

            $appointment->status = $to;
            $appointment->updated_by = $actor->id;
            match ($to) {
                AppointmentStatus::CheckedIn => $appointment->checked_in_at = now(),
                AppointmentStatus::InService => $appointment->service_started_at = now(),
                AppointmentStatus::Completed => $appointment->completed_at = now(),
                AppointmentStatus::Cancelled => $appointment->forceFill(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]),
                default => null,
            };
            if ($to->isFinal() && $to !== AppointmentStatus::Completed) {
                $appointment->reminder_at = null;
            }
            $appointment->save();

            $this->record($appointment, $from, $to, $actor, $reason);
            $this->audit->log('appointment.status_changed', $appointment, ['status' => $from->value], ['status' => $to->value,
                'late_cancellation' => $appointment->is_late_cancellation], $reason);

            return $appointment;
        });
    }

    /** Walk-ins skip Pending -> Confirmed -> Checked In manual steps. */
    public function forceInitial(Appointment $appointment, User $actor): void
    {
        foreach ([AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn] as $to) {
            $from = $appointment->status;
            $appointment->status = $to;
            if ($to === AppointmentStatus::CheckedIn) {
                $appointment->checked_in_at = now();
            }
            $appointment->save();
            $this->record($appointment, $from, $to, $actor, 'Walk-in');
        }
    }

    public function record(Appointment $appointment, ?AppointmentStatus $from, AppointmentStatus $to, ?User $actor, ?string $reason = null): void
    {
        AppointmentStatusHistory::create([
            'appointment_id' => $appointment->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $actor?->id,
            'reason' => $reason,
        ]);
    }

    /** Applies the configurable cancellation policy (notice period; flag or block late cancellations). */
    private function applyCancellation(Appointment $appointment, bool $override): void
    {
        $noticeHours = (int) $this->settings->get('booking.cancellation_notice_hours', 24);
        $late = now()->gt($appointment->start_at->copy()->subHours($noticeHours));
        if ($late && $this->settings->get('booking.late_cancellation_action', 'flag') === 'block' && ! $override) {
            throw ValidationException::withMessages(['status' => "Cancellations less than {$noticeHours} hours before the appointment are not allowed. A manager with override permission can cancel it."]);
        }
        $appointment->is_late_cancellation = $late;
    }
}
