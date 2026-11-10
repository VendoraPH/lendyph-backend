<?php

namespace App\Http\Requests\Savings\Account;

use App\Models\SavingsAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /savings/accounts` (savings:view): set B's filters and paging. A
 * `branch_id` outside the caller's branches answers 403 in the controller.
 */
class ListSavingsAccountsRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(SavingsAccount::STATUSES)],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'borrower_id' => ['nullable', 'integer', 'min:1'],
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
            'status.in' => 'An account is active, dormant, frozen or closed.',
            'product_id.integer' => 'Choose the product from the list.',
            'product_id.min' => 'Choose the product from the list.',
            'branch_id.integer' => 'Choose the branch from the list.',
            'branch_id.min' => 'Choose the branch from the list.',
            'borrower_id.integer' => 'Choose the member from the list.',
            'borrower_id.min' => 'Choose the member from the list.',
            'page.integer' => 'The page must be a whole number from 1.',
            'page.min' => 'The page must be a whole number from 1.',
            'per_page.integer' => 'Rows per page must be a whole number from 1.',
            'per_page.min' => 'Rows per page must be a whole number from 1.',
        ];
    }
}
