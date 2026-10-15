<?php

namespace Tests;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;

/** Shared builders for salon feature tests. "Now" is frozen to Monday 2026-10-19 08:00 salon time. */
trait Concerns
{
    protected function freezeSalonTime(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00', config('app.timezone')));
    }

    protected function stylist(string $start = '09:00', string $end = '18:00'): Employee
    {
        return Employee::factory()->workingAllWeek($start, $end)->create();
    }

    protected function service(int $minutes = 60, string $price = '25.00', array $attrs = []): Service
    {
        return Service::factory()->create($attrs + ['duration_minutes' => $minutes, 'base_price' => $price]);
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs);
    }

    protected function receptionist(): User
    {
        return User::factory()->withPermissions('customers.view', 'customers.create', 'customers.update', 'appointments.view', 'appointments.all',
            'appointments.create', 'appointments.update', 'appointments.status', 'appointments.cancel', 'services.view', 'employees.view')->create();
    }

    /** Booking payload for a single service on 2026-10-20 (Tuesday). */
    protected function bookingPayload(Customer $c, Employee $e, Service $s, string $time = '10:00', string $date = '2026-10-20'): array
    {
        return ['customer_id' => $c->id, 'date' => $date, 'time' => $time, 'lines' => [['service_id' => $s->id, 'employee_id' => $e->id]]];
    }
}
