<?php

namespace App\Http\Requests\Savings\Interest;

use App\Models\SavingsInterestRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /savings/interest-runs` (savings:view): filters `status`,
 * `product_id` and `branch_id`, paged up to 100 a page.
 */
class ListInterestRunsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(SavingsInterestRun::STATUSES)],
            'product_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'A run is a draft, approved, posted, rejected or reversed.',
            'product_id.integer' => 'Choose a savings product from the list.',
            'product_id.min' => 'Choose a savings product from the list.',
            'branch_id.integer' => 'Choose a branch from the list.',
            'branch_id.min' => 'Choose a branch from the list.',
            'per_page.integer' => 'Show between 1 and 100 runs a page.',
            'per_page.min' => 'Show between 1 and 100 runs a page.',
            'per_page.max' => 'Show between 1 and 100 runs a page.',
            'page.integer' => 'The page is a whole number from 1.',
            'page.min' => 'The page is a whole number from 1.',
        ];
    }
}
