<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use App\Models\User;
use App\Services\ContactVisibility;

/**
 * Who may read and work on online applications (origination decision 16),
 * with the refusals in words:
 *
 * - reading the list, a detail, the documents queue, a download and the
 *   dashboard: `loan_origination:view`;
 * - every staff write (moves, notes, assignment, information requests,
 *   reminders, document decisions, linking a member):
 *   `loan_origination:update`; rejecting also needs `loans:reject`
 *   (ApplicationTransitions);
 * - member matches and linking a member: also `borrowers:view`, since they
 *   show members' records;
 * - an applicant's contacts and address lines: `borrowers:view` and the
 *   application's branch (ContactVisibility, the round fz rule); otherwise
 *   they're masked. The same rule opens a document's file (download) and
 *   shows its file names, since an ID or a bill shows both.
 *
 * Another branch's application or document answers 404 (route binding).
 */
final class StaffAccess
{
    public const VIEW_REFUSAL = 'You don\'t have access to online applications.';

    public const UPDATE_REFUSAL = 'Working on online applications needs the permission to update online applications.';

    public const DOWNLOAD_REFUSAL = 'Documents can show an applicant\'s address and contacts, so downloading them needs the permission to view members.';

    public const MATCHES_REFUSAL = 'Member matches show members\' records, so they need the permission to view members.';

    public function __construct(private readonly ContactVisibility $contacts) {}

    public static function canView(?User $user): bool
    {
        return $user !== null && $user->can('loan_origination:view');
    }

    public static function canUpdate(?User $user): bool
    {
        return $user !== null && $user->can('loan_origination:update');
    }

    public static function canSeeMembers(?User $user): bool
    {
        return $user !== null && $user->can('borrowers:view');
    }

    public function canSeeContacts(?User $user, OriginationApplication $application): bool
    {
        return $this->contacts->allows($user, $application->branch_id === null ? null : (int) $application->branch_id);
    }
}
