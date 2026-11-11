<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/time-deposits/{id}/preterminate`: break a placement early. Needs `savings:close`.
 * `payout_account_id` picks which of the member's savings accounts a savings payout goes into
 * (else the one named at placement); other payout methods ignore it.
 */
class PreterminateTimeDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:close');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
            'payout_method' => ['required', ...TimeDepositRules::method()],
            'payout_account_id' => ['exclude_unless:payout_method,savings', 'nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...TimeDepositRules::methodMessages('payout_method', 'payout leaves'),
            'payout_account_id.integer' => 'Pick one of the member\'s savings accounts to pay into.',
            'date.required' => 'Enter the date it is broken.',
            'date.date_format' => 'The date must be written as YYYY-MM-DD.',
            'reason.required' => 'Say why the placement is being broken early.',
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
