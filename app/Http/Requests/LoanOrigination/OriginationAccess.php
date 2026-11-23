<?php

namespace App\Http\Requests\LoanOrigination;

use App\Models\User;
use App\Services\BranchAccess;

/**
 * Who may read and change online application setup (origination decision
 * 16), in one place with the refusals in words:
 *
 * - reading the settings, notices and links: `loan_origination:view` or
 *   `loan_origination:settings`;
 * - changing the company-wide settings and publishing notices:
 *   `loan_origination:settings` plus `branches:view_all`, as Savings and
 *   Reminders settings do;
 * - managing links: `loan_origination:settings`, for one of the caller's own
 *   branches; a link for every branch needs `branches:view_all`.
 */
final class OriginationAccess
{
    public const READ_REFUSAL = 'You don\'t have access to online application settings.';

    public const SETTINGS_REFUSAL = 'Online application settings apply to every branch, so changing them needs the online applications settings permission and access to all branches.';

    public const NOTICE_REFUSAL = 'Online application notices apply to every branch, so publishing one needs the online applications settings permission and access to all branches.';

    public const LINK_REFUSAL = 'Managing application links needs the online applications settings permission.';

    public static function canRead(?User $user): bool
    {
        return $user !== null && $user->canAny(['loan_origination:view', 'loan_origination:settings']);
    }

    public static function canChangeCompanyWide(?User $user): bool
    {
        return $user !== null && $user->can('loan_origination:settings') && $user->can(BranchAccess::VIEW_ALL);
    }

    public static function canManageLinks(?User $user): bool
    {
        return $user !== null && $user->can('loan_origination:settings');
    }
}
