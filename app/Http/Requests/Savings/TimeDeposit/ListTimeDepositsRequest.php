<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use App\Models\TimeDeposit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `GET /savings/time-deposits`: one page of placements. Needs `savings:view`. */
class ListTimeDepositsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(TimeDeposit::STATUSES)],
            'product_id' => ['sometimes', 'nullable', 'integer'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'borrower_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'page.integer' => 'The page is a whole number.',
            'page.min' => 'The page is at least 1.',
            'per_page.integer' => 'Rows per page is a whole number.',
            'per_page.min' => 'Show at least 1 row per page.',
            'per_page.max' => 'Show at most 100 rows per page.',
            'search.string' => 'Search with text.',
            'search.max' => 'The search can be at most 100 characters.',
            'status.in' => 'A time deposit is active, matured, withdrawn, rolled over or pre-terminated.',
            'product_id.integer' => 'Pick a product from the list.',
            'branch_id.integer' => 'Pick a branch from the list.',
            'borrower_id.integer' => 'Pick a member from the list.',
        ];
    }
}
