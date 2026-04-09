<?php

namespace App\Http\Requests\Fee;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('fees.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:fees,name'],
            'type' => ['required', 'in:fixed,percentage'],
            'value' => ['required', 'numeric', 'min:0'],
            'applicable_product_ids' => ['nullable', 'array'],
            'applicable_product_ids.*' => ['integer', 'exists:loan_products,id'],
            'conditions' => ['nullable', 'array'],
            'conditions.term_days_gt' => ['nullable', 'integer', 'min:0'],
            'conditions.term_days_lt' => ['nullable', 'integer', 'min:0'],
            'conditions.term_days_eq' => ['nullable', 'integer', 'min:0'],
            'conditions.loan_amount_gt' => ['nullable', 'numeric', 'min:0'],
            'conditions.loan_amount_lt' => ['nullable', 'numeric', 'min:0'],
            'conditions.loan_amount_eq' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
