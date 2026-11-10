<?php

namespace App\Http\Requests\Savings\Hold;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use App\Http\Requests\Savings\SavingsRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts/{account}/holds` (savings:transact): a legal or
 * staff hold, until a date (today or later) or until released. Loan
 * retention holds are placed by loans (v2), never by hand.
 */
class PlaceSavingsHoldRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:transact', 'account', 'account');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['legal', 'manual'])],
            'amount' => ['required', ...SavingsRules::money('hold amount'), 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['amount' => 'hold amount']),
            'type.required' => 'Choose the kind of hold: a legal order or a staff hold.',
            'type.string' => 'Choose the kind of hold: a legal order or a staff hold.',
            'type.in' => 'A hold placed by hand is a legal order or a staff hold.',
            'amount.required' => 'Enter the amount to hold.',
            'amount.gt' => 'The hold amount must be above ₱0.00.',
            'reason.required' => 'Say why the money is held.',
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
            'expires_on.date_format' => 'The end date must be written as YYYY-MM-DD.',
            'expires_on.after_or_equal' => 'A hold can end today or later, or leave the end date empty to hold until released.',
        ];
    }
}
