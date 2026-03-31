<?php

namespace App\Http\Requests\LoanProduct;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'interest_method' => ['required', 'in:straight,diminishing,upon_maturity'],
            'term' => ['required', 'integer', 'min:1'],
            'frequency' => ['required', 'in:daily,weekly,semi_monthly,monthly'],
            'processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
