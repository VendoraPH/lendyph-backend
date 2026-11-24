<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Concerns\DateInputMessages;
use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/reports/profit-sharing: `cutoff_date`, a calendar date written
 * year-month-day and no later than today in Manila (today when left out, as
 * the other reports' dates default). There is no branch filter: a sent
 * `branch_id` changes nothing.
 *
 * A whole-cooperative report, and each of its figures needs its source's
 * permission, since the profit less the income gives the rest away (money
 * review, 2026-11-24): reports, share capital, expenses, the books, and
 * every branch. Without any one of them it answers 403 in words. The member
 * rows also need `reports:export` (ProfitSharingReportController).
 */
class ProfitSharingReportRequest extends FormRequest
{
    public const REFUSAL = 'The Profit Sharing report covers the whole cooperative\'s share capital, income, expenses and credit losses, so it needs the permissions to view reports, share capital, expenses and the books, and access to every branch.';

    /** Every one of these, each the permission of a figure's source. */
    public const PERMISSIONS = ['reports:view', 'share_capital:view', 'expenses:view', 'accounting:view', BranchAccess::VIEW_ALL];

    public function authorize(): bool
    {
        foreach (self::PERMISSIONS as $permission) {
            if (! $this->user()->can($permission)) {
                return false;
            }
        }

        return true;
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...DateInputMessages::calendar(['cutoff_date' => 'cutoff date']),
            'cutoff_date.before_or_equal' => 'The cutoff date can\'t be after today.',
        ];
    }
}
