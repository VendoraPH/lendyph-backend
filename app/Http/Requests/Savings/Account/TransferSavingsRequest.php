<?php

namespace App\Http\Requests\Savings\Account;

use App\Http\Requests\Savings\SavingsRules;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts/{account}/transfers` (savings:transact): to another
 * account in the caller's branches. Teller checks it is the same member's,
 * in the same branch, and open.
 */
class TransferSavingsRequest extends FormRequest
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
            'to_account_id' => ['required', 'integer', app(BranchAccess::class)->constrainExists(Rule::exists('savings_accounts', 'id'), $this->user())],
            'amount' => ['required', ...SavingsRules::money('amount'), 'gt:0'],
            'date' => $this->businessDate(),
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['amount' => 'amount']),
            ...$this->dateMessages('date', 'date'),
            'to_account_id.required' => 'Choose the account to transfer to.',
            'to_account_id.integer' => 'Choose the account to transfer to from the list.',
            'to_account_id.exists' => 'That savings account does not exist.',
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be above ₱0.00.',
            'remarks.max' => 'The remarks can be at most 500 characters.',
            'remarks.string' => 'The remarks must be text.',
        ];
    }
}
