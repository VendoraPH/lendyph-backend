<?php

namespace App\Http\Requests\Savings\Account;

use App\Http\Requests\Savings\SavingsRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts/{account}/deposits` and `…/withdrawals`
 * (savings:transact): an amount above ₱0.00, today or yesterday, and how the
 * money moved over the counter.
 */
class SavingsMoneyRequest extends FormRequest
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
            'amount' => ['required', ...SavingsRules::money('amount'), 'gt:0'],
            'date' => $this->businessDate(),
            'payment_method' => ['required', 'string', Rule::in(OpenSavingsAccountRequest::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['amount' => 'amount']),
            ...$this->dateMessages('date', 'date'),
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be above ₱0.00.',
            'payment_method.required' => 'Choose how the money was paid: cash, bank transfer, GCash or Maya.',
            'payment_method.string' => 'Choose how the money was paid: cash, bank transfer, GCash or Maya.',
            'payment_method.in' => 'Choose how the money was paid: cash, bank transfer, GCash or Maya.',
            'reference.max' => 'The reference can be at most 64 characters.',
            'reference.string' => 'The reference must be text.',
            'remarks.max' => 'The remarks can be at most 500 characters.',
            'remarks.string' => 'The remarks must be text.',
        ];
    }
}
