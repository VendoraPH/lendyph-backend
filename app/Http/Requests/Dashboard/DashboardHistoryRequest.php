<?php

namespace App\Http\Requests\Dashboard;

use App\Services\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /dashboard/history (Phase 6 EB-B): one `date`, or a `date_from` …
 * `date_to` range read as month-end points; neither after today, and a range
 * of at most 10 years, as the reports cap theirs. `branch_id` and
 * `product_id` narrow it; `officer_id` and `collector_id` are accepted, so
 * the dashboard can send one filter set, but history doesn't follow them
 * (`filters_applied` says so).
 *
 * Needs the executive dashboard (`dashboard:analytics`) and the reports its
 * figures come from (`reports:view`). A branch the caller can't see answers
 * 403, as on the reports.
 */
class DashboardHistoryRequest extends FormRequest
{
    public const REFUSAL = 'Portfolio history needs access to the executive dashboard and to reports.';

    public const MAX_SPAN_YEARS = 10;

    public function authorize(): bool
    {
        return $this->user()->can('dashboard:analytics') && $this->user()->can('reports:view');
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'required_without_all:date_from,date_to', 'prohibits:date_from,date_to'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:date_from', 'after_or_equal:date_from', 'before_or_equal:today'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'officer_id' => ['nullable', 'integer', 'min:1'],
            'collector_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.required_without_all' => 'Pick a date, or a start and an end date.',
            'date.prohibits' => 'Pick either one date or a start and an end date, not both.',
            'date.before_or_equal' => 'The date can\'t be after today.',
            'date_to.before_or_equal' => 'The end date can\'t be after today.',
            'date_to.after_or_equal' => 'The end date can\'t be before the start date.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! filled($this->input('date_from'))) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('date_from'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('date_to'));

            if ($from->lt($to->subYearsNoOverflow(self::MAX_SPAN_YEARS))) {
                $validator->errors()->add('date_from', 'A period can cover at most '.self::MAX_SPAN_YEARS.' years.');
            }
        }];
    }

    /**
     * The days the history reads: the one date, or each month's end from the
     * start's month to the end's, the last capped at the end date.
     *
     * @return list<string>
     */
    public function requestedDates(): array
    {
        if (filled($this->validated('date'))) {
            return [(string) $this->validated('date')];
        }

        $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('date_to'));
        $month = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->validated('date_from'))->startOfMonth();

        $dates = [];
        while ($month->lte($to)) {
            $end = $month->endOfMonth()->startOfDay();
            $dates[] = ($end->gt($to) ? $to : $end)->toDateString();
            $month = $month->addMonthNoOverflow();
        }

        return $dates;
    }

    /**
     * The branches read: the caller's, narrowed by `branch_id` (403 outside
     * them); null for every branch.
     *
     * @return list<int>|null
     */
    public function branchScope(): ?array
    {
        $access = app(BranchAccess::class);
        $branchId = $this->validated('branch_id');

        $access->authorizeBranchFilter($this->user(), $branchId);

        return filled($branchId) ? [(int) $branchId] : $access->for($this->user());
    }
}
