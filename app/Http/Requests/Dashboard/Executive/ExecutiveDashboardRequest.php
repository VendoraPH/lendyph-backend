<?php

namespace App\Http\Requests\Dashboard\Executive;

use App\Services\BranchAccess;
use App\Services\Dashboard\DashboardPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The executive dashboard's shared filters (Phase 6 EB-D1): a `preset`
 * (DashboardPeriod, "This Month" by default) or `custom` with `date_from` and
 * `date_to`, worked out in Manila time, and `branch_id`, `product_id`,
 * `officer_id` and `collector_id`. Needs `dashboard:analytics`; each figure
 * also needs its own source's permission, applied by the service.
 *
 * A branch outside the caller's own answers 403, as on the reports.
 */
class ExecutiveDashboardRequest extends FormRequest
{
    public const REFUSAL = 'The executive dashboard needs its own access.';

    private ?DashboardPeriod $period = null;

    public function authorize(): bool
    {
        return $this->user()->can('dashboard:analytics');
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(static::REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preset' => ['nullable', 'string', Rule::in(array_keys(DashboardPeriod::PRESETS))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            // Real records only, so a made-up id can't buy an uncached run of
            // every report. An id outside the caller's branches still reads
            // nothing: every figure stays in their branches.
            'product_id' => ['nullable', 'integer', 'min:1', Rule::exists('loan_products', 'id')],
            'officer_id' => ['nullable', 'integer', 'min:1', Rule::exists('users', 'id')],
            'collector_id' => ['nullable', 'integer', 'min:1', Rule::exists('users', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['preset.in' => 'Pick one of the listed periods.'];
    }

    /**
     * The period, refused in words on the field it's about.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $this->period = DashboardPeriod::resolve(
                    (string) ($this->input('preset') ?: 'this_month'),
                    $this->input('date_from'),
                    $this->input('date_to'),
                );
            } catch (InvalidArgumentException $exception) {
                $message = $exception->getMessage();
                $field = match (true) {
                    str_contains($message, 'end date') => 'date_to',
                    str_contains($message, 'listed periods') => 'preset',
                    default => 'date_from',
                };

                $validator->errors()->add($field, $message);
            }
        }];
    }

    public function period(): DashboardPeriod
    {
        return $this->period ??= DashboardPeriod::resolve((string) ($this->validated('preset') ?: 'this_month'), $this->validated('date_from'), $this->validated('date_to'));
    }

    /**
     * The branches read: `branch_id` (403 outside the caller's), else the
     * caller's own; null for every branch.
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
     * The validated filters as the reports read them (ReportController's
     * `branch_ids` and `branch_id`, plus the dashboard's own), without dates.
     *
     * @return array<string, mixed>
     */
    public function reportFilters(): array
    {
        $this->branchScope();

        return [
            'branch_ids' => app(BranchAccess::class)->for($this->user()),
            'branch_id' => $this->validated('branch_id'),
            'product_id' => $this->validated('product_id'),
            'officer_id' => $this->validated('officer_id'),
            'collector_id' => $this->validated('collector_id'),
        ];
    }
}
