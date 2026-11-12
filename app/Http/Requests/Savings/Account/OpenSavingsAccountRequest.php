<?php

namespace App\Http\Requests\Savings\Account;

use App\Http\Requests\Savings\SavingsRules;
use App\Models\SavingsAccount;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts` (savings:create): open an account for a member in
 * the caller's branches. The member's standing, the product and the minimum
 * opening deposit are checked by AccountOpener, in words. The opening details
 * (gap round S1) are optional: an account officer (an active staff member in
 * the caller's branches), the source of funds (a key; `other` with the
 * member's own words) and a beneficiary {name, relationship, contact_number}.
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
            // Opening details (gap round S1), all optional.
            'account_officer_id' => ['nullable', 'integer', app(BranchAccess::class)->constrainUserExists(Rule::exists('users', 'id')->where('status', 'active'), $this->user())],
            'source_of_funds' => ['nullable', 'string', Rule::in(array_keys(SavingsAccount::SOURCES_OF_FUNDS))],
            'source_of_funds_other' => ['nullable', 'required_if:source_of_funds,other', 'string', 'max:120'],
            'beneficiary' => ['nullable', 'array:name,relationship,contact_number'],
            'beneficiary.name' => ['nullable', 'string', 'max:150'],
            'beneficiary.relationship' => ['nullable', 'string', 'max:60'],
            'beneficiary.contact_number' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9 \-]{6,19}$/'],
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
            'account_officer_id.integer' => 'Choose the account officer from the list.',
            'account_officer_id.exists' => 'Choose an active staff member in your branches as the account officer.',
            'source_of_funds.in' => 'The source of funds is salary, business, remittance, pension, savings or other.',
            'source_of_funds.string' => 'The source of funds is salary, business, remittance, pension, savings or other.',
            'source_of_funds_other.required_if' => 'Say where the money comes from.',
            'source_of_funds_other.string' => 'Say where the money comes from, in words.',
            'source_of_funds_other.max' => 'The source of funds can be at most 120 characters.',
            'beneficiary.array' => 'The beneficiary has a name, a relationship and a contact number, each optional.',
            'beneficiary.name.string' => 'The beneficiary\'s name must be text.',
            'beneficiary.name.max' => 'The beneficiary\'s name can be at most 150 characters.',
            'beneficiary.relationship.string' => 'The beneficiary\'s relationship must be text.',
            'beneficiary.relationship.max' => 'The beneficiary\'s relationship can be at most 60 characters.',
            'beneficiary.contact_number.string' => 'Enter the beneficiary\'s contact number in digits.',
            'beneficiary.contact_number.regex' => 'Enter the beneficiary\'s contact number in digits, like 09171234567.',
        ];
    }
}
