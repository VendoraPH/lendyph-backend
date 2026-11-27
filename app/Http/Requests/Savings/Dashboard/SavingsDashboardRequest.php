<?php

namespace App\Http\Requests\Savings\Dashboard;

use App\Services\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Savings dashboard's filters: `from` and `to` (YYYY-MM-DD, `to` not after
 * today; by default the first of this month to today, and a missing `from`
 * is the first of `to`'s month), at most 10 years apart; `branch_id` (one of
 * the caller's branches, else 403) and `product_id`. Needs `savings:view`.
 */
class SavingsDashboardRequest extends FormRequest
{
    private const MAX_SPAN_YEARS = 10;

    public function authorize(): bool
    {
        return $this->user()?->can('savings:view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => array_values(array_filter(['nullable', 'date_format:Y-m-d', $this->filled('to') ? 'before_or_equal:to' : 'before_or_equal:'.now()->toDateString()])),
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1', Rule::exists('savings_products', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'from.before_or_equal' => 'The start date can\'t be after the end date or today.',
            'to.date_format' => 'The end date must be written as YYYY-MM-DD.',
            'to.before_or_equal' => 'The end date can\'t be after today.',
            'branch_id.integer' => 'Choose a branch from the list.',
            'branch_id.min' => 'Choose a branch from the list.',
            'product_id.integer' => 'Choose a product from the list.',
            'product_id.min' => 'Choose a product from the list.',
            'product_id.exists' => 'Choose a product from the list.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->period();

            if (CarbonImmutable::parse($from)->addYears(self::MAX_SPAN_YEARS)->lessThan(CarbonImmutable::parse($to))) {
                $validator->errors()->add('from', 'The dashboard can cover at most '.self::MAX_SPAN_YEARS.' years.');
            }
        });
    }

    /** @return array{0: string, 1: string} the period the dashboard reads */
    public function period(): array
    {
        $to = CarbonImmutable::parse($this->input('to') ?? now()->toDateString());

        return [$this->input('from') ?? $to->startOfMonth()->toDateString(), $to->toDateString()];
    }

    /**
     * The scope: the caller's branches (`branch_ids`, null for
     * `branches:view_all`), `branch_id` (checked, 403 outside them) and
     * `product_id`.
     *
     * @return array{branch_ids: list<int>|null, branch_id: int|null, product_id: int|null}
     */
    public function scope(): array
    {
        $filters = $this->validated();
        $access = app(BranchAccess::class);
        $access->authorizeBranchFilter($this->user(), $filters['branch_id'] ?? null);

        return [
            'branch_ids' => $access->for($this->user()),
            'branch_id' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            'product_id' => isset($filters['product_id']) ? (int) $filters['product_id'] : null,
        ];
    }
}
