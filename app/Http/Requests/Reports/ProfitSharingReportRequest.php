<?php

namespace App\Http\Requests\Reports;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/reports/profit-sharing: `cutoff_date`, a calendar date written
 * year-month-day and no later than today in Manila (today when left out, as
 * the other reports' dates default), and an optional `branch_id`
 * (BranchAccess::authorizeBranchFilter() in the controller).
 *
 * The report is members' share capital, so like the Share Capital report
 * (Phase 6 decision 10) it needs `share_capital:view` as well as
 * `reports:view`, and answers 403 in words without either.
 */
class ProfitSharingReportRequest extends FormRequest
{
    public const REFUSAL = 'The Profit Sharing report shows members\' share capital, so it needs the permission to view share capital as well as the permission to view reports.';

    public function authorize(): bool
    {
        return $this->user()->can('reports:view') && $this->user()->can('share_capital:view');
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
            'cutoff_date' => ['bail', 'nullable', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
            'branch_id' => ['bail', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $written = 'The cutoff date must be a date written as year-month-day, such as 2026-11-24.';

        return [
            'cutoff_date.date' => $written,
            'cutoff_date.date_format' => $written,
            'cutoff_date.before_or_equal' => 'The cutoff date can\'t be after today.',
            'branch_id.integer' => 'Pick a branch from the list.',
            'branch_id.min' => 'Pick a branch from the list.',
        ];
    }
}
