<?php

namespace App\Http\Requests\LoanAdjustment;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreLoanAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loan_adjustments:create');
    }

    /**
     * Every `new_values.*` rule used to be plain `nullable`, so a payload that
     * omitted the figure its adjustment type actually needs sailed through
     * validation and broke inside LoanAdjustmentService instead:
     *
     *   - balance_adjustment / term_extension read new_values['adjustment_amount']
     *     and ['additional_terms'] directly, so a missing key is an undefined
     *     array key access — a 500 rather than a validation error.
     *   - penalty_waiver was worse than a 500: it reads the selection with `??`
     *     and `empty()`, so a payload with neither `waive_all` nor
     *     `schedule_ids` fell through to an unfiltered query and silently
     *     waived the penalties on *every* open schedule of the loan.
     *
     * The `required_if` rules below make each type state its own figure, so a
     * wrong payload fails with a 422 naming the field.
     */
    public function rules(): array
    {
        return [
            'adjustment_type' => ['required', 'in:restructure,penalty_waiver,balance_adjustment,term_extension,extension'],
            'description' => ['nullable', 'string', 'max:1000'],
            'new_values' => ['required', 'array'],

            // Restructure — any one of the three is enough; enforced in after().
            'new_values.interest_rate' => ['nullable', 'numeric', 'min:0'],
            'new_values.term' => ['nullable', 'integer', 'min:1'],
            'new_values.frequency' => ['nullable', 'in:daily,weekly,bi_weekly,semi_monthly,monthly'],

            // Penalty waiver — needs an explicit selection; enforced in after().
            'new_values.schedule_ids' => ['nullable', 'array'],
            'new_values.schedule_ids.*' => ['integer', 'exists:amortization_schedules,id'],
            'new_values.waive_all' => ['nullable', 'boolean'],

            // Balance adjustment — a signed delta applied to the principal,
            // not the resulting balance.
            'new_values.adjustment_amount' => ['required_if:adjustment_type,balance_adjustment', 'nullable', 'numeric'],
            'new_values.reason' => ['nullable', 'string', 'max:500'],

            // Term extension — the number of extra periods, not the new term.
            'new_values.additional_terms' => ['required_if:adjustment_type,term_extension', 'nullable', 'integer', 'min:1'],

            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = $this->input('adjustment_type');
                $newValues = $this->input('new_values', []);

                // Waiving penalties must be deliberate. Without this, a payload
                // carrying neither key reaches a handler that waives every open
                // schedule on the loan.
                if ($type === 'penalty_waiver'
                    && ! $this->boolean('new_values.waive_all')
                    && empty($newValues['schedule_ids'])) {
                    $validator->errors()->add(
                        'new_values.schedule_ids',
                        'Select the schedules to waive, or set waive_all to true.',
                    );
                }

                // Restructure with no figures at all would apply nothing while
                // still recording an "applied" adjustment against the loan.
                $restructureKeys = ['interest_rate', 'term', 'frequency'];

                if ($type === 'restructure'
                    && count(array_intersect_key($newValues, array_flip($restructureKeys))) === 0) {
                    $validator->errors()->add(
                        'new_values',
                        'Provide at least one of interest_rate, term or frequency to restructure.',
                    );
                }
            },
        ];
    }
}
