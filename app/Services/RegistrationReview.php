<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The writes that decide a public registration: approving it
 * (PATCH /borrowers/{borrower}/approve-registration) and rejecting it
 * (PATCH /borrowers/{borrower}/reject), once BorrowerController has made its
 * checks (finish run round 4).
 *
 * Those checks read the registration before any lock, so on their own two
 * decisions on it at once (two approvals, two rejections, or one of each), or
 * an approval and a staff create saving the same email, could all pass them.
 * Each decision runs in one transaction (LoanWriteTransaction) that takes, in
 * this order:
 *
 *   1. the registration's own row, FOR UPDATE, as the transaction's first
 *      statement. Both decisions take it, so a second decision on the same
 *      registration waits here, then finds it no longer pending;
 *   2. an approval only: the lock a member's duplicate check takes
 *      (BorrowerRegistration::lockForDuplicateCheck(): the newest member's
 *      row, FOR UPDATE). A staff create, a public registration, the online
 *      hand-off's member and every other approval take it before they check,
 *      so the approval's email check never runs while one of them is between
 *      its own check and its commit. A rejection checks no email, so it stops
 *      at lock 1.
 *
 * Both are locking reads ahead of any plain read, so the email check's
 * snapshot is taken once both are held and sees every member committed by
 * then. Two decisions queue on lock 1 rather than deadlock: once it holds
 * lock 2, an approval waits for no other registration's row, and a rejection
 * never takes lock 2. A create that holds lock 2 while an approval waits for
 * it can still deadlock with that approval, two ways:
 * - its insert waits behind the gap lock the waiting approval was granted at
 *   lock 2, as two creates can deadlock;
 * - a public registration linked to the registration being approved
 *   (`possible_duplicate_of_id`) checks that link under a shared lock on its
 *   row, which the approval holds.
 * MySQL then rolls one of them back: a create is tried again
 * (GeneratedNumberWrite), an approval answers 409. A rejection only makes
 * such a create wait, since it waits for nothing the create holds.
 *
 * Under the locks, what the checks decided on is read again from the locked
 * row, and a change since then answers 409 in words, with nothing written:
 * - the registration is no longer pending: someone approved or rejected it,
 *   so an approved member is never rejected and keeps their pledge;
 * - its branch changed, so the decision would land in a branch the reviewer
 *   didn't decide for, perhaps one they can't see;
 * - for an approval, its email, as it is now, is on another member.
 * A deadlock or a lock wait timeout answers 409 too, never 500. A request that
 * was wrong from the start is still the controller's 422.
 */
final class RegistrationReview
{
    public const DECIDED = 'This registration was approved or rejected at the same moment. Reload to see it.';

    public const EMAIL_TAKEN = 'Another member was saved with this email at the same moment. Reload and try again.';

    public const CONFLICT = 'Another change to this registration was saved at the same moment. Reload and try again.';

    public function __construct(private readonly BorrowerDuplicateDetector $duplicates) {}

    /**
     * Approve `$registration`, as read before the transaction. It is refreshed
     * from its locked row, approved, and returned.
     *
     * @param  int|null  $branchId  the reviewer's branch, for a registration that has none
     *
     * @throws HttpResponseException 409 when another write got there first
     * @throws ModelNotFoundException when the registration is gone
     */
    public function approve(Borrower $registration, ?int $branchId, User $by): Borrower
    {
        $branchWhenChecked = self::branchOf($registration);

        return LoanWriteTransaction::run(function () use ($registration, $branchId, $branchWhenChecked, $by): Borrower {
            $this->lockStillPending($registration, $branchWhenChecked);

            BorrowerRegistration::lockForDuplicateCheck();

            if ($this->duplicates->memberWithEmail($registration->email, (int) $registration->getKey()) !== null) {
                throw LoanWriteTransaction::conflict(self::EMAIL_TAKEN);
            }

            // Approval makes the applicant a member today (CDA design §1): the
            // membership date and number, where neither is set yet.
            $registration->update([
                'branch_id' => $registration->branch_id ?? $branchId,
                'status' => 'active',
                'approved_by' => $by->id,
                'approved_at' => now(),
                'membership_date' => $registration->membership_date ?? now()->toDateString(),
                'membership_number' => $registration->membership_number ?? $registration->borrower_code,
            ]);

            return $registration;
        }, conflictMessage: self::CONFLICT);
    }

    /**
     * Reject `$registration`, as read before the transaction. It is refreshed
     * from its locked row, rejected, and returned.
     *
     * @throws HttpResponseException 409 when another write got there first
     * @throws ModelNotFoundException when the registration is gone
     */
    public function reject(Borrower $registration, string $reason, User $by): Borrower
    {
        $branchWhenChecked = self::branchOf($registration);

        return LoanWriteTransaction::run(function () use ($registration, $reason, $branchWhenChecked, $by): Borrower {
            $this->lockStillPending($registration, $branchWhenChecked);

            $registration->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'rejected_by' => $by->id,
                'rejected_at' => now(),
            ]);

            // A rejected applicant is not a member: remove the share-capital pledge
            // auto-created at submission so it doesn't linger in share-capital totals.
            $registration->shareCapitalPledge()->delete();

            return $registration;
        }, conflictMessage: self::CONFLICT);
    }

    /**
     * Lock 1: the registration's row, as the transaction's first statement.
     * Answers 409 unless it is still pending in the branch it was checked in,
     * and refreshes `$registration` from the locked row.
     *
     * @throws HttpResponseException 409 when another write got there first
     * @throws ModelNotFoundException when the registration is gone
     */
    private function lockStillPending(Borrower $registration, ?int $branchWhenChecked): void
    {
        /** @var Borrower $locked */
        $locked = Borrower::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'pending') {
            throw LoanWriteTransaction::conflict(self::DECIDED);
        }

        if (self::branchOf($locked) !== $branchWhenChecked) {
            throw LoanWriteTransaction::conflict(self::CONFLICT);
        }

        $registration->setRawAttributes($locked->getAttributes(), true);
    }

    private static function branchOf(Borrower $registration): ?int
    {
        return $registration->branch_id === null ? null : (int) $registration->branch_id;
    }
}
