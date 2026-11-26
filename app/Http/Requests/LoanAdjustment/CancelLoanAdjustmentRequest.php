<?php

namespace App\Http\Requests\LoanAdjustment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling an approved adjustment (finish run round 6): the approver's
 * permission, and a reason of 5 to 500 characters, read trimmed (the
 * TrimStrings middleware), so spaces alone never count.
 */
class CancelLoanAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loan_adjustments:approve');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give the reason for cancelling this adjustment.',
            'reason.min' => 'The reason must be at least 5 characters.',
            'reason.max' => 'The reason must not be longer than 500 characters.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
