<?php

namespace App\Http\Requests\LoanProduct;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:update');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interest_method' => ['sometimes', 'in:straight,diminishing,upon_maturity'],
            'term' => ['sometimes', 'integer', 'min:1'],
            'min_term' => ['nullable', 'integer', 'min:1'],
            'max_term' => ['nullable', 'integer', 'min:1'],
            'frequency' => ['sometimes', 'in:daily,weekly,semi_monthly,monthly'],
            'frequencies' => ['nullable', 'array'],
            'frequencies.*' => ['in:daily,weekly,semi_monthly,monthly'],
            'processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_service_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_service_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notarial_fee' => ['nullable', 'numeric', 'min:0'],
            'custom_fees' => ['nullable', 'array'],
            'custom_fees.*.name' => ['required_with:custom_fees', 'string', 'max:255'],
            'custom_fees.*.type' => ['required_with:custom_fees', 'in:fixed,percentage'],
            'custom_fees.*.value' => ['required_with:custom_fees', 'numeric', 'min:0'],
            'custom_fees.*.conditions' => ['nullable', 'array'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('interest_rate') && $this->has('max_interest_rate')) {
            $this->merge(['interest_rate' => $this->max_interest_rate]);
        }
        if (! $this->has('term') && $this->has('max_term')) {
            $this->merge(['term' => $this->max_term]);
        }
        if (! $this->has('frequency') && $this->has('frequencies')) {
            $frequencies = $this->frequencies;
            $this->merge(['frequency' => is_array($frequencies) ? ($frequencies[0] ?? 'monthly') : $frequencies]);
        }
    }
}
