<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PosPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.use');
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'draft_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.service_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.membership_plan_id' => ['nullable', 'integer'],
            'items.*.appointment_service_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.employee_id' => ['nullable', 'integer'],
            'items.*.discount_type' => ['nullable', 'in:none,percent,fixed'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }
}
