<?php

namespace App\Http\Requests\Savings\Account;

use App\Http\Requests\Savings\SavingsRules;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts` (savings:create): open an account for a member in
 * the caller's branches. The member's standing, the product and the minimum
 * opening deposit are checked by AccountOpener, in words.
 */
class OpenSavingsAccountRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'gcash', 'maya'];

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'integer', app(BranchAccess::class)->constrainExists(Rule::exists('borrowers', 'id'), $this->user())],
            'product_id' => ['required', 'integer', Rule::exists('savings_products', 'id')],
            'opened_at' => $this->businessDate(),
            'initial_deposit' => ['required', ...SavingsRules::money('opening deposit')],
            'payment_method' => ['nullable', 'string', Rule::in(self::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['initial_deposit' => 'opening deposit']),
            ...$this->dateMessages('opened_at', 'opening date'),
            'borrower_id.required' => 'Choose the member.',
            'borrower_id.integer' => 'Choose the member from the list.',
            'borrower_id.exists' => 'That member does not exist.',
            'product_id.required' => 'Choose the savings product.',
            'product_id.integer' => 'Choose the savings product from the list.',
            'product_id.exists' => 'That savings product does not exist.',
            'initial_deposit.required' => 'Enter the opening deposit (0 when the product needs none).',
            'payment_method.in' => 'The opening deposit is paid in cash, by bank transfer, GCash or Maya.',
            'payment_method.string' => 'The opening deposit is paid in cash, by bank transfer, GCash or Maya.',
            'reference.max' => 'The reference can be at most 64 characters.',
            'reference.string' => 'The reference must be text.',
            'remarks.max' => 'The remarks can be at most 500 characters.',
            'remarks.string' => 'The remarks must be text.',
        ];
    }
}
