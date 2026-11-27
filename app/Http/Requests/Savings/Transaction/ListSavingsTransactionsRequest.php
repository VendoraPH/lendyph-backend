<?php

namespace App\Http\Requests\Savings\Transaction;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use App\Models\SavingsTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /savings/transactions` (savings:view): the register, newest first. A
 * `branch_id` outside the caller's branches answers 403 in the controller.
 */
class ListSavingsTransactionsRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(SavingsTransaction::TYPES)],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'search.string' => 'Search with text.',
            'search.max' => 'Search with at most 100 characters.',
            'type.in' => 'Choose a transaction type from the list.',
            'branch_id.integer' => 'Choose the branch from the list.',
            'branch_id.min' => 'Choose the branch from the list.',
            'date_from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'date_to.date_format' => 'The end date must be written as YYYY-MM-DD.',
            'date_to.after_or_equal' => 'The end date can\'t be before the start date.',
            'page.integer' => 'The page must be a whole number from 1.',
            'page.min' => 'The page must be a whole number from 1.',
            'per_page.integer' => 'Rows per page must be a whole number from 1.',
            'per_page.min' => 'Rows per page must be a whole number from 1.',
        ];
    }
}
