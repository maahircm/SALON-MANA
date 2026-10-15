<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('employees.manage');
    }

    public function rules(): array
    {
        $id = $this->route('employee')?->id;
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(array_keys(Employee::STATUSES))],
            'termination_date' => ['nullable', 'date', 'required_if:status,terminated'],
            'is_bookable' => ['sometimes', 'boolean'],
            'calendar_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'commission_type' => ['required', Rule::in(['none', 'percentage', 'fixed'])],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100000', Rule::when($this->input('commission_type') === 'percentage', ['max:100'])],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')->ignore($id)],
            'skills' => ['array'],
            'skills.*' => ['integer', 'exists:skills,id'],
            'status_reason' => ['nullable', 'string', 'max:500'],
        ];
        if ($this->user()->can('employees.sensitive')) {
            $rules['salary_amount'] = ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'];
        }

        return $rules;
    }
}
