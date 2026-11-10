<?php

namespace App\Http\Requests\Savings\Report;

use App\Services\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The filters every `/reports/savings/*` endpoint shares, and the savings
 * reports' own copy of ReportController::reportFilters(): `date_from`,
 * `date_to`, `as_of` (not after today), `branch_id` and `per_page` (1 to
 * 1,000), a period of at most 10 years, and the branch scope. A `branch_id`
 * outside the caller's branches is refused (403); without one, a caller
 * without `branches:view_all` reports on their own branches only
 * (`branch_ids`). Needs `reports:view` and `savings:view`.
 */
class SavingsReportRequest extends FormRequest
{
    private const MAX_SPAN_YEARS = 10;

    /** @return list<string> */
    protected function permissions(): array
    {
        return ['reports:view', 'savings:view'];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && collect($this->permissions())->every(fn (string $permission) => $user->can($permission));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_values(array_filter(['nullable', 'date_format:Y-m-d', $this->filled('date_from') ? 'after_or_equal:date_from' : null])),
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'date_to.date_format' => 'The end date must be written as YYYY-MM-DD.',
            'date_to.after_or_equal' => 'The end date can\'t be before the start date.',
            'as_of.date_format' => 'The "as of" date must be written as YYYY-MM-DD.',
            'as_of.before_or_equal' => 'The "as of" date can\'t be after today.',
            'branch_id.integer' => 'Choose a branch from the list.',
            'branch_id.min' => 'Choose a branch from the list.',
            'per_page.integer' => 'Show between 1 and 1,000 rows a page.',
            'per_page.min' => 'Show between 1 and 1,000 rows a page.',
            'per_page.max' => 'Show between 1 and 1,000 rows a page.',
            'page.integer' => 'The page is a whole number from 1.',
            'page.min' => 'The page is a whole number from 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('date_from') || ! $this->filled('date_to')) {
                return;
            }

            $from = CarbonImmutable::parse((string) $this->input('date_from'));

            if ($from->addYears(self::MAX_SPAN_YEARS)->lessThan(CarbonImmutable::parse((string) $this->input('date_to')))) {
                $validator->errors()->add('date_to', 'A report can cover at most '.self::MAX_SPAN_YEARS.' years.');
            }
        });
    }

    /**
     * The validated filters with the caller's branch scope: `branch_ids` is
     * null for `branches:view_all`, else the caller's branches.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();
        $access = app(BranchAccess::class);
        $access->authorizeBranchFilter($this->user(), $filters['branch_id'] ?? null);
        $filters['branch_ids'] = $access->for($this->user());

        return $filters;
    }
}
