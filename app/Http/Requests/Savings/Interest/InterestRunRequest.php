<?php

namespace App\Http\Requests\Savings\Interest;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/interest-runs/preview` and `POST /savings/interest-runs`
 * (savings:post): `{product_id, period_start, branch_ids?}`. A branch the
 * caller can't see reads as one that doesn't exist (422). No branches means
 * every branch the caller can see that holds an account on the product.
 */
class InterestRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:post');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('savings_products', 'id')],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'branch_ids' => ['sometimes', 'array', 'min:1', 'max:200'],
            'branch_ids.*' => [
                'integer',
                'distinct',
                app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Choose the savings product to credit interest on.',
            'product_id.integer' => 'Choose the savings product to credit interest on.',
            'product_id.exists' => 'That savings product doesn\'t exist.',
            'period_start.required' => 'Enter the first day of the interest period.',
            'period_start.date_format' => 'The first day of the period must be written as YYYY-MM-DD.',
            'branch_ids.array' => 'List the branches to run, or leave them out for every branch you can see.',
            'branch_ids.min' => 'List at least one branch, or leave them out for every branch you can see.',
            'branch_ids.max' => 'A run can list at most 200 branches.',
            'branch_ids.*.integer' => 'Each branch is chosen from the list.',
            'branch_ids.*.distinct' => 'Each branch can be listed once.',
            'branch_ids.*.exists' => 'That branch doesn\'t exist or isn\'t one of yours.',
        ];
    }

    /** @return list<int>|null */
    public function branchIds(): ?array
    {
        $ids = $this->validated('branch_ids');

        return $ids === null ? null : array_values(array_map('intval', $ids));
    }
}
