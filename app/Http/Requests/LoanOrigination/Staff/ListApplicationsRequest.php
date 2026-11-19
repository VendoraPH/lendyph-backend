<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use App\Services\BranchAccess;
use App\Services\LoanOrigination\Staff\ApplicationQueue;
use Illuminate\Validation\Rule;

/**
 * `GET /loan-origination/applications`: filters, sort and page. A branch
 * outside the caller's answers 403, never an empty page.
 */
class ListApplicationsRequest extends StaffViewRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(OriginationApplication::STATUSES)],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'loan_product_id' => ['nullable', 'integer', 'min:1'],
            'officer_id' => ['nullable', 'integer', 'min:1'],
            'assigned' => ['nullable', 'string', 'in:me,none'],
            'is_test' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', Rule::in(ApplicationQueue::SORTS)],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function passedValidation(): void
    {
        app(BranchAccess::class)->authorizeBranchFilter($this->user(), $this->validated('branch_id'));
    }
}
