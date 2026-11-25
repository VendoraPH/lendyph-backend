<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversing one payment of an expense from Expenses & Payables (finish run
 * round 5).
 *
 * Needs `expenses:pay`, the permission that took the money out, and
 * `journals:reverse`, since it posts a reversal. Takes only a reason: the
 * mirror is built from the payment's stored journal (JournalPoster::
 * reverse()) and lands today in Manila, or on the payment's own date when
 * that is later. The reason is folded into the reversal's description, as on
 * the Journals screen, so it has that screen's 300-character limit.
 */
class ReverseExpensePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('expenses:pay') && $this->user()->can('journals:reverse');
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
            'reason.required' => 'Say why this payment is being reversed.',
            'reason.string' => 'Say why this payment is being reversed.',
            'reason.max' => 'The reason can be at most 300 characters.',
        ];
    }
}
