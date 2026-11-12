<?php

namespace App\Http\Requests\Savings\Transfer;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use App\Http\Requests\Savings\SavingsRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/accounts/{account}/share-capital-transfers` (gap round S3):
 * needs `savings:post` and `share_capital:create` (the share capital entry
 * permission), then the account in the caller's branches (404 otherwise).
 * An amount above ₱0.00 and optional remarks; the date is today.
 */
class ShareCapitalTransferRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        if (! $this->user()?->can('share_capital:create')) {
            return false;
        }

        return $this->authorizeRecord('savings:post', 'account', 'account');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', ...SavingsRules::money('amount'), 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['amount' => 'amount']),
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be above ₱0.00.',
            'remarks.string' => 'The remarks must be text.',
            'remarks.max' => 'The remarks can be at most 500 characters.',
        ];
    }
}
