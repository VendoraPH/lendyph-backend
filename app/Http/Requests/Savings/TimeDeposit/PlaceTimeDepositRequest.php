<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use App\Models\TimeDeposit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/time-deposits`: a placement. Needs `savings:create`. The
 * member and any savings account must be in the caller's branches (422
 * otherwise, like a missing one). `funding_account_id` counts only when the
 * placement is paid from savings.
 */
class PlaceTimeDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();

        return [
            ...TimeDepositRules::quote($user),
            'borrower_id' => ['required', 'integer', TimeDepositRules::visible('borrowers', $user)],
            'maturity_instruction' => ['required', Rule::in(TimeDeposit::MATURITY_INSTRUCTIONS)],
            'payment_method' => ['required', ...TimeDepositRules::method()],
            'reference' => ['nullable', 'string', 'max:100'],
            'funding_account_id' => ['exclude_unless:payment_method,savings', 'nullable', 'integer', TimeDepositRules::visible('savings_accounts', $user)],
            'payout_account_id' => ['nullable', 'integer', TimeDepositRules::visible('savings_accounts', $user)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...TimeDepositRules::quoteMessages(),
            ...TimeDepositRules::methodMessages('payment_method', 'placement is paid'),
            'borrower_id.required' => 'Pick the member placing the time deposit.',
            'maturity_instruction.required' => 'Say what happens at maturity.',
            'maturity_instruction.in' => 'At maturity a placement is paid out, has its principal rolled over, or has principal and interest rolled over.',
            'reference.string' => 'The reference must be text.',
            'reference.max' => 'The reference can be at most 100 characters.',
            'funding_account_id.integer' => 'Pick the savings account from the list.',
            'funding_account_id.exists' => 'That savings account doesn\'t exist or isn\'t in your branches.',
            'payout_account_id.integer' => 'Pick the payout account from the list.',
            'payout_account_id.exists' => 'That savings account doesn\'t exist or isn\'t in your branches.',
        ];
    }
}
