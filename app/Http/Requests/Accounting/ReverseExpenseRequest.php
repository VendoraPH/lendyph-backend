<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversing an expense from Expenses & Payables (finish run round 5), once
 * none of its payments stands (ExpenseRecorder::reverse()).
 *
 * Needs `expenses:update`, the permission that corrects an expense, and
 * `journals:reverse`, since it posts a reversal. Takes only a reason, with
 * the Journals screen's 300-character limit; the mirror is built from the
 * expense's stored journal and lands today in Manila, or on the expense's
 * own date when that is later.
 */
class ReverseExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('expenses:update') && $this->user()->can('journals:reverse');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this expense is being reversed.',
            'reason.string' => 'Say why this expense is being reversed.',
            'reason.max' => 'The reason can be at most 300 characters.',
        ];
    }
}
