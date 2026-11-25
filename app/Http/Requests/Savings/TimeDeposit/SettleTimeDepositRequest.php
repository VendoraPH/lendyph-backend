<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use App\Http\Requests\Savings\SavingsReference;
use App\Models\TimeDeposit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/time-deposits/{id}/settle`: settle at maturity. Needs
 * `savings:transact`. `payout_method` is not needed for a full rollover.
 * `next_instruction` is the new placement's instruction on a rollover (the
 * old one's when left out); a payout ignores it. `payout_account_id` picks
 * which of the member's savings accounts a savings payout goes into (else the
 * one named at placement); other payout methods ignore it.
 * `payout_reference` is the payout's reference, required for bank transfer,
 * GCash and Maya (SavingsReference, finish run round 5); a full rollover and
 * a payout into savings take none.
 */
class SettleTimeDepositRequest extends FormRequest
{
    use FindsTheTimeDepositFirst;

    public function authorize(): bool
    {
        return $this->mayActOnTheTimeDeposit('savings:transact');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'action' => ['required', Rule::in(TimeDeposit::MATURITY_INSTRUCTIONS)],
            'payout_method' => ['exclude_if:action,rollover_all', 'required', ...TimeDepositRules::method()],
            'payout_account_id' => ['exclude_unless:payout_method,savings', 'nullable', 'integer'],
            'payout_reference' => ['exclude_if:action,rollover_all', 'exclude_if:payout_method,savings', ...SavingsReference::rules($this->input('payout_method'), 100)],
            'next_instruction' => ['exclude_if:action,payout', 'nullable', Rule::in(TimeDeposit::MATURITY_INSTRUCTIONS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...TimeDepositRules::methodMessages('payout_method', 'payout leaves'),
            'payout_account_id.integer' => 'Pick one of the member\'s savings accounts to pay into.',
            'payout_reference.required' => SavingsReference::message($this->input('payout_method'), SavingsReference::PAYOUT),
            'payout_reference.string' => 'The reference must be text.',
            'payout_reference.max' => 'The reference can be at most 100 characters.',
            'date.required' => 'Enter the settlement date.',
            'date.date_format' => 'The settlement date must be written as YYYY-MM-DD.',
            'action.required' => 'Say how to settle it: pay out, roll over the principal, or roll over principal and interest.',
            'action.in' => 'A placement is paid out, has its principal rolled over, or has principal and interest rolled over.',
            'next_instruction.in' => 'After the rollover, a placement is paid out, has its principal rolled over, or has principal and interest rolled over.',
        ];
    }
}
