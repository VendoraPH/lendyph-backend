<?php

namespace App\Http\Requests\AutoPay;

use Illuminate\Foundation\Http\FormRequest;

class PreviewAutoPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('auto_pay:view');
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['integer', 'exists:loan_products,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ];
    }
}
