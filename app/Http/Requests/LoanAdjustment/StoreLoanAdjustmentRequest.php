<?php

namespace App\Http\Requests\LoanAdjustment;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loan_adjustments:create');
    }

    public function rules(): array
    {
        return [
            'adjustment_type' => ['required', 'in:restructure,penalty_waiver,balance_adjustment,term_extension,extension'],
            'description' => ['nullable', 'string', 'max:1000'],
            'new_values' => ['required', 'array'],

            // Restructure
            'new_values.interest_rate' => ['nullable', 'numeric', 'min:0'],
            'new_values.term' => ['nullable', 'integer', 'min:1'],
            'new_values.frequency' => ['nullable', 'in:daily,weekly,bi_weekly,semi_monthly,monthly'],

            // Penalty waiver
            'new_values.schedule_ids' => ['nullable', 'array'],
            'new_values.schedule_ids.*' => ['integer', 'exists:amortization_schedules,id'],
            'new_values.waive_all' => ['nullable', 'boolean'],

            // Balance adjustment
            'new_values.adjustment_amount' => ['nullable', 'numeric'],
            'new_values.reason' => ['nullable', 'string', 'max:500'],

            // Term extension
            'new_values.additional_terms' => ['nullable', 'integer', 'min:1'],

            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
