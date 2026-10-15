<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment ? $this->user()->can('update', $appointment) : $this->user()->can('appointments.create');
    }

    public function rules(): array
    {
        return [
            'customer_id' => [$this->route('appointment') ? 'sometimes' : 'required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'source' => ['sometimes', Rule::in(['booking', 'walk_in'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'lines.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'lines.*.room_id' => ['nullable', 'integer', 'exists:rooms,id'],
        ];
    }

    /** Booking payload in the shape AppointmentBookingService expects. */
    public function booking(): array
    {
        $v = $this->validated();

        return [
            'customer_id' => $v['customer_id'] ?? null,
            'start_at' => $v['date'].' '.$v['time'],
            'notes' => $v['notes'] ?? null,
            'source' => $v['source'] ?? 'booking',
            'lines' => $v['lines'],
        ];
    }
}
