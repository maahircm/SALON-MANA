<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\User;

/**
 * Appointment visibility: users with appointments.all see everything; others (e.g. stylists)
 * only appointments in which their linked employee record performs a service.
 * Gate::before has already allowed Super Administrators and denied inactive users.
 */
class AppointmentPolicy
{
    private function scoped(User $user, Appointment $appointment): bool
    {
        if (! $user->hasPermission('appointments.view')) {
            return false;
        }
        if ($user->hasPermission('appointments.all')) {
            return true;
        }
        $employeeId = Employee::where('user_id', $user->id)->value('id');
        $appointment->loadMissing('lines');

        return $appointment->involvesEmployee($employeeId);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->scoped($user, $appointment);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission('appointments.update') && $this->scoped($user, $appointment);
    }

    public function changeStatus(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission('appointments.status') && $this->scoped($user, $appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission('appointments.cancel') && $this->scoped($user, $appointment);
    }
}
