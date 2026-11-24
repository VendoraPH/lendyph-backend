<?php

namespace App\Http\Resources;

use App\Models\Borrower;
use App\Models\User;
use App\Services\BorrowerDuplicateDetector;
use App\Services\ContactVisibility;

/**
 * The "possible duplicate" link on a public registration (finish run round 2,
 * decision 3), as the registration review shows it.
 *
 * The public form never refuses a duplicate, so this link is the only place
 * the match is named. A reviewer who may see that record (`borrowers:view` and
 * its branch, ContactVisibility) reads its code, name and why it matched;
 * anyone else learns only that someone outside their branches may be the same
 * person, as a staff create's refusal tells them.
 */
final class PossibleDuplicatePresenter
{
    public const HIDDEN_NOTICE = 'This registration may be a duplicate of someone already on file outside your branches. Check with head office before approving.';

    public const HIDDEN_REFUSAL = 'This registration may be a duplicate of someone already on file outside your branches. Check with head office, then confirm to approve it.';

    /**
     * The registration's link for $viewer, or null when it has none.
     * `is_member` is false when the match is itself a registration (pending
     * or rejected), so the screen links to its review instead.
     *
     * @return array{visible: true, id: int, borrower_code: string, full_name: string, is_member: bool, reason: string, message: string}|array{visible: false, message: string}|null
     */
    public static function present(Borrower $registration, ?User $viewer): ?array
    {
        $match = $registration->possibleDuplicateOf;

        if ($match === null) {
            return null;
        }

        if (! self::mayName($match, $viewer)) {
            return ['visible' => false, 'message' => self::HIDDEN_NOTICE];
        }

        $reason = BorrowerDuplicateDetector::reasonInWords($registration->possible_duplicate_reason);

        return [
            'visible' => true,
            'id' => $match->id,
            'borrower_code' => $match->borrower_code,
            'full_name' => $match->full_name,
            'is_member' => ! in_array($match->status, Borrower::NON_MEMBER_STATUSES, true),
            'reason' => $reason,
            'message' => sprintf('Possible duplicate of %s (%s): %s.', $match->borrower_code, $match->full_name, lcfirst($reason)),
        ];
    }

    /**
     * Why approving the registration waits for the reviewer's confirmation,
     * in words for $viewer.
     */
    public static function approvalRefusal(Borrower $registration, ?User $viewer): string
    {
        $match = $registration->possibleDuplicateOf;

        if ($match === null || ! self::mayName($match, $viewer)) {
            return self::HIDDEN_REFUSAL;
        }

        return sprintf(
            'This registration may be a duplicate of %s (%s). Check that this is a different person, then confirm to approve it.',
            $match->borrower_code,
            $match->full_name,
        );
    }

    private static function mayName(Borrower $match, ?User $viewer): bool
    {
        return app(ContactVisibility::class)->allows($viewer, $match->branch_id === null ? null : (int) $match->branch_id);
    }
}
