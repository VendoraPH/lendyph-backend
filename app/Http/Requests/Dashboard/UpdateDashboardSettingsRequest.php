<?php

namespace App\Http\Requests\Dashboard;

use App\Models\DashboardSetting;
use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /dashboard/settings` (Phase 6 EB-C). The alert thresholds are
 * company-wide, so changing them needs `dashboard:settings` and
 * `branches:view_all`. Every field is optional. Percentages and points are 0
 * to 100 and pesos ₱0.00 to ₱1,000,000.00, each with at most two decimals
 * (more is refused, never rounded); days are whole days from 1 to 365
 * (`true` is refused). The controller stores each value in one form
 * (DashboardSetting::normalised()).
 */
class UpdateDashboardSettingsRequest extends FormRequest
{
    public const COMPANY_WIDE_REFUSAL = 'Dashboard alert thresholds apply to every branch, so changing them needs the dashboard settings permission and access to all branches.';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('dashboard:settings') && $user->can(BranchAccess::VIEW_ALL);
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::COMPANY_WIDE_REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $percent = ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:0,100'];
        $days = ['sometimes', 'required', 'numeric', 'integer', 'between:1,365'];

        return [
            'par30_rise_points' => $percent,
            'par30_compare_days' => $days,
            'collection_efficiency_below' => $percent,
            'overdue_share_above' => $percent,
            'approval_wait_days' => $days,
            'release_wait_days' => $days,
            'control_difference_above' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:0,'.DashboardSetting::MAX_CONTROL_DIFFERENCE],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = ['*.required' => 'This threshold can\'t be left empty.'];

        foreach (['par30_rise_points' => 'The PAR 30 rise must be a number of points from 0 to 100, with at most two decimals.',
            'collection_efficiency_below' => 'The Collection Efficiency threshold must be a percentage from 0 to 100, with at most two decimals.',
            'overdue_share_above' => 'The overdue share threshold must be a percentage from 0 to 100, with at most two decimals.',
        ] as $field => $text) {
            foreach (['numeric', 'decimal', 'between'] as $rule) {
                $messages["{$field}.{$rule}"] = $text;
            }
        }

        foreach (['par30_compare_days' => 'The PAR 30 comparison period must be a whole number of days from 1 to 365.',
            'approval_wait_days' => 'The approval waiting time must be a whole number of days from 1 to 365.',
            'release_wait_days' => 'The release waiting time must be a whole number of days from 1 to 365.',
        ] as $field => $text) {
            foreach (['numeric', 'integer', 'between'] as $rule) {
                $messages["{$field}.{$rule}"] = $text;
            }
        }

        foreach (['numeric', 'decimal', 'between'] as $rule) {
            $messages["control_difference_above.{$rule}"] = 'The control difference tolerance must be an amount from ₱0.00 to ₱1,000,000.00, with at most two decimals.';
        }

        return $messages;
    }
}
