<?php

namespace App\Http\Requests\Repayment;

use Illuminate\Foundation\Http\FormRequest;

class StoreRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('payments:create');
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'method' => ['sometimes', 'in:cash,gcash,maya,bank_transfer,online'],
            'reference_number' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'cash']);
        }
    }
}
