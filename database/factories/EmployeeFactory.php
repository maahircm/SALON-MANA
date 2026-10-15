<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->name(), 'job_title' => 'Stylist', 'status' => 'active', 'is_bookable' => true, 'hire_date' => '2024-01-01'];
    }

    /** Works every day 09:00–18:00. */
    public function workingAllWeek(string $start = '09:00', string $end = '18:00'): static
    {
        return $this->afterCreating(function (Employee $e) use ($start, $end) {
            foreach (range(0, 6) as $d) {
                $e->schedules()->create(['weekday' => $d, 'start_time' => $start, 'end_time' => $end]);
            }
        });
    }
}
