<?php

namespace App\Http\Requests\Savings\Account;

use App\Models\SavingsTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /savings/accounts/{account}/transactions` (savings:view): one page of
 * the account's ledger, newest first, by date range and type.
 */
class AccountTransactionsRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:view', 'account', 'account');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['nullable', Rule::in(SavingsTransaction::TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'date_to.date_format' => 'The end date must be written as YYYY-MM-DD.',
            'date_to.after_or_equal' => 'The end date can\'t be before the start date.',
            'type.in' => 'Choose a transaction type from the list.',
            'page.integer' => 'The page must be a whole number from 1.',
            'page.min' => 'The page must be a whole number from 1.',
            'per_page.integer' => 'Rows per page must be a whole number from 1.',
            'per_page.min' => 'Rows per page must be a whole number from 1.',
        ];
    }
}
