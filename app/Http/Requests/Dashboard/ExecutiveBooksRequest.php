<?php

namespace App\Http\Requests\Dashboard;

use App\Services\BranchAccess;
use App\Services\Dashboard\DashboardPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The executive dashboard's books, trends and alerts (Phase 6 EB-D2):
 * `preset` (one of DashboardPeriod::PRESETS, This Month by default) or
 * `preset=custom` with `from` and `to`, worked out in Manila time; `as_of`
 * for the share capital control (today by default, never after it); and the
 * dashboard's `branch_id`, `product_id`, `officer_id` and `collector_id`.
 *
 * Needs the executive dashboard (`dashboard:analytics`); each endpoint also
 * checks its sources' permissions. A branch the caller can't see answers
 * 403, as on the reports. A period that can't be read answers 422 on
 * `preset`, in words.
 */
class ExecutiveBooksRequest extends FormRequest
{
    public const REFUSAL = 'This part of the executive dashboard needs access to the executive dashboard.';

    private ?DashboardPeriod $period = null;

    public function authorize(): bool
    {
        return $this->user()->can('dashboard:analytics');
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
            'preset' => ['nullable', 'string'],
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
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
            'preset.string' => 'Pick one of the listed periods.',
            'from.string' => 'Pick a start date as YYYY-MM-DD.',
            'to.string' => 'Pick an end date as YYYY-MM-DD.',
            'as_of.date_format' => 'Pick a date as YYYY-MM-DD.',
            'as_of.before_or_equal' => 'The date can\'t be after today.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $preset = $this->input('preset') ?? 'this_month';
            $from = $this->input('from');
            $to = $this->input('to');

            // Only text is read as a period: a list (`preset[]=x`) is refused
            // in words by the string rules, never cast.
            if (! is_string($preset) || ($from !== null && ! is_string($from)) || ($to !== null && ! is_string($to))) {
                $validator->errors()->add('preset', 'Pick one of the listed periods.');

                return;
            }

            try {
                $this->period = DashboardPeriod::resolve($preset, $from === '' ? null : $from, $to === '' ? null : $to);
            } catch (InvalidArgumentException $refusal) {
                $validator->errors()->add('preset', $refusal->getMessage());
            }
        }];
    }

    public function period(): DashboardPeriod
    {
        return $this->period ?? DashboardPeriod::resolve('this_month');
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

    /**
     * The filters the reports take (ReportController::reportFilters()'s
     * shape): the caller's branches as `branch_ids`, the branch, product,
     * officer and collector ids, and the period's dates.
     *
     * @return array<string, mixed>
     */
    public function reportFilters(): array
    {
        $this->branchScope();
        $period = $this->period();

        return [
            'branch_ids' => app(BranchAccess::class)->for($this->user()),
            'branch_id' => $this->validated('branch_id'),
            'product_id' => $this->validated('product_id'),
            'officer_id' => $this->validated('officer_id'),
            'collector_id' => $this->validated('collector_id'),
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ];
    }
}
