<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('expenses.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2000-01-01'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['receipt.max' => 'The receipt must not be larger than 5 MB.', 'receipt.mimes' => 'The receipt must be a JPG, PNG or PDF file.'];
    }
}
