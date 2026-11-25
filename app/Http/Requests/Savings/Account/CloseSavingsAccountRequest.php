<?php

namespace App\Http\Requests\Savings\Account;

use App\Http\Requests\Savings\SavingsReference;
use App\Support\Sentence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts/{account}/close` (savings:close): the date, the
 * reason, and how the balance and its interest are paid out (over the
 * counter; a savings account can't receive a closing payout), with the
 * payout's reference unless it is paid in cash (SavingsReference, finish run
 * round 5).
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
            'reason' => ['bail', 'required', 'string', 'max:500', 'regex:'.Sentence::HAS_WORDS],
            'payout_method' => ['required', 'string', Rule::in(OpenSavingsAccountRequest::PAYMENT_METHODS)],
            'payout_reference' => SavingsReference::rules($this->input('payout_method'), 64),
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
            'reason.regex' => Sentence::NO_WORDS,
            'payout_method.required' => 'Choose how the balance is paid out: cash, bank transfer, GCash or Maya.',
            'payout_method.string' => 'Choose how the balance is paid out: cash, bank transfer, GCash or Maya.',
            'payout_method.in' => 'A closing payout is made in cash, by bank transfer, GCash or Maya.',
            'payout_reference.required' => SavingsReference::message($this->input('payout_method'), SavingsReference::PAYOUT),
            'payout_reference.string' => 'The reference must be text.',
            'payout_reference.max' => 'The reference can be at most 64 characters.',
        ];
    }
}
