<?php

namespace App\Http\Requests\LoanProduct;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'interest_method' => ['sometimes', 'in:straight,diminishing,upon_maturity'],
            'term' => ['sometimes', 'integer', 'min:1'],
            'frequency' => ['sometimes', 'in:daily,weekly,semi_monthly,monthly'],
            'processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
