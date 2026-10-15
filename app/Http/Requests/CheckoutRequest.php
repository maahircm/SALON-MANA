<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.use');
    }

    /**
     * Shape validation only; amounts, prices, discounts and limits are enforced by the pricing and payment services.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:checkout,save_draft'],
            'idempotency_key' => ['required', 'uuid'],
            'draft_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.service_id' => ['nullable', 'integer'],
            'items.*.appointment_service_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.employee_id' => ['nullable', 'integer'],
            'items.*.discount_type' => ['nullable', 'in:none,percent,fixed'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'payments' => ['nullable', 'array', 'max:6'],
            'payments.*.payment_method_id' => ['required_with:payments.*.amount', 'nullable', 'integer'],
            'payments.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }
}
