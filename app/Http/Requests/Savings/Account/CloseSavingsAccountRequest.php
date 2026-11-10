<?php

namespace App\Http\Requests\Savings\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts/{account}/close` (savings:close): the date, the
 * reason, and how the balance and its interest are paid out (over the
 * counter; a savings account can't receive a closing payout).
 */
class CloseSavingsAccountRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:close', 'account', 'account');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => $this->businessDate(),
            'reason' => ['required', 'string', 'max:500'],
            'payout_method' => ['required', 'string', Rule::in(OpenSavingsAccountRequest::PAYMENT_METHODS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->dateMessages('date', 'closing date'),
            'reason.required' => 'Say why the account is being closed.',
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
            'payout_method.required' => 'Choose how the balance is paid out: cash, bank transfer, GCash or Maya.',
            'payout_method.string' => 'Choose how the balance is paid out: cash, bank transfer, GCash or Maya.',
            'payout_method.in' => 'A closing payout is made in cash, by bank transfer, GCash or Maya.',
        ];
    }
}
