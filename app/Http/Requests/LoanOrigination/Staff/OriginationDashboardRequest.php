<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Services\BranchAccess;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * `GET /loan-origination/dashboard`: a period of Manila days (this month to
 * today by default) of at most MAX_DAYS, and an optional branch inside the
 * caller's (403 otherwise).
 */
class OriginationDashboardRequest extends StaffViewRequest
{
    public const MAX_DAYS = 366;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->period();

            if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('date_to', 'Choose a period of at most a year.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        app(BranchAccess::class)->authorizeBranchFilter($this->user(), $this->validated('branch_id'));
    }

    /** @return array{0: string, 1: string} */
    public function period(): array
    {
        $today = Carbon::now('Asia/Manila');
        $to = $this->input('date_to') ?: $today->toDateString();
        $from = $this->input('date_from') ?: Carbon::parse($to)->startOfMonth()->toDateString();

        return [(string) $from, (string) $to];
    }

    /** @return list<int>|null */
    public function branchScope(): ?array
    {
        $branchId = $this->validated('branch_id');

        return filled($branchId) ? [(int) $branchId] : app(BranchAccess::class)->for($this->user());
    }
}
