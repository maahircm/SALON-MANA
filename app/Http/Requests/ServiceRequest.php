<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('services.manage');
    }

    public function rules(): array
    {
        $id = $this->route('service')?->id;
        $rules = [
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:150', Rule::unique('services', 'name')->ignore($id)->whereNull('deleted_at')
                ->where('service_category_id', $this->input('service_category_id'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'required_skill_id' => ['nullable', 'integer', 'exists:skills,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        // Price fields are validated for everyone but only applied for users holding services.price.
        if ($this->user()->can('services.price') || ! $id) {
            $rules += [
                'base_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999'],
                'promo_price' => ['nullable', 'decimal:0,2', 'min:0', 'lte:base_price'],
                'promo_starts_on' => ['nullable', 'date'],
                'promo_ends_on' => ['nullable', 'date', 'after_or_equal:promo_starts_on'],
            ];
        }

        return $rules;
    }
}
