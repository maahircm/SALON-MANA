<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.manage');
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $id = $this->route('product')?->id;
        $rules = [
            'sku' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._\-\/]+$/', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('products', 'barcode')->ignore($id)],
            'name' => ['required', 'string', 'max:190'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['required', Rule::in(array_keys(Product::UNITS))],
            'selling_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['required', 'decimal:0,3', 'min:0', 'max:99999999'],
            'expiry_date' => ['nullable', 'date'],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if (! $id) {
            $rules['opening_quantity'] = ['nullable', 'decimal:0,3', 'min:0', 'max:99999999'];
            $rules['opening_cost'] = ['nullable', 'decimal:0,2', 'min:0', 'max:9999999'];
        }

        return $rules;
    }
}
