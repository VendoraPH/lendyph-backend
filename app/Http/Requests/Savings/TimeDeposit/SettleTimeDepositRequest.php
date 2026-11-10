<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use App\Models\TimeDeposit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/time-deposits/{id}/settle`: settle at maturity. Needs
 * `savings:transact`. `payout_method` is not needed for a full rollover.
 * `next_instruction` is the new placement's instruction on a rollover (the
 * old one's when left out); a payout ignores it.
 */
class SettleTimeDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:transact');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'action' => ['required', Rule::in(TimeDeposit::MATURITY_INSTRUCTIONS)],
            'payout_method' => ['exclude_if:action,rollover_all', 'required', ...TimeDepositRules::method()],
            'next_instruction' => ['exclude_if:action,payout', 'nullable', Rule::in(TimeDeposit::MATURITY_INSTRUCTIONS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...TimeDepositRules::methodMessages('payout_method', 'payout leaves'),
            'date.required' => 'Enter the settlement date.',
            'date.date_format' => 'The settlement date must be written as YYYY-MM-DD.',
            'action.required' => 'Say how to settle it: pay out, roll over the principal, or roll over principal and interest.',
            'action.in' => 'A placement is paid out, has its principal rolled over, or has principal and interest rolled over.',
            'next_instruction.in' => 'After the rollover, a placement is paid out, has its principal rolled over, or has principal and interest rolled over.',
        ];
    }
}
