<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The write that approves a public registration
 * (PATCH /borrowers/{borrower}/approve-registration), once
 * BorrowerController::approveRegistration() has made its checks (finish run
 * round 4).
 *
 * Those checks read the registration before any lock, so on their own two
 * approvals of it at once, an approval and a rejection, or an approval and a
 * staff create saving the same email could all pass them. The write runs in
 * one transaction (LoanWriteTransaction) that takes, in this order:
 *
 *   1. the registration's own row, FOR UPDATE, as the transaction's first
 *      statement. A second approval of it waits here, then finds it no
 *      longer pending;
 *   2. the lock a member's duplicate check takes
 *      (BorrowerRegistration::lockForDuplicateCheck(): the newest member's
 *      row, FOR UPDATE). A staff create, a public registration, the online
 *      hand-off's member and every other approval take it before they check,
 *      so the email check below never runs while one of them is between its
 *      own check and its commit.
 *
 * Both are locking reads ahead of any plain read, so the email check's
 * snapshot is taken once both are held and sees every member committed by
 * then. Once it holds lock 2, neither an approval nor a create waits for a
 * registration's row, so approvals queue rather than deadlock. A create that
 * holds lock 2 while an approval waits for it can still deadlock with that
 * approval when it inserts its row, as two creates can. MySQL then rolls one
 * of them back: a create is tried again (GeneratedNumberWrite), an approval
 * answers 409.
 *
 * Under the locks, what the checks decided on is read again from the locked
 * row, and a change since then answers 409 in words, with nothing written:
 * - the registration is no longer pending: someone approved or rejected it;
 * - its branch changed, so the member would join a branch the reviewer didn't
 *   approve them into, perhaps one they can't see;
 * - its email, as it is now, is on another member.
 * A deadlock or a lock wait timeout answers 409 too, never 500. A request that
 * was wrong from the start is still the controller's 422.
 */
final class RegistrationApproval
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
            /** @var Borrower $locked */
            $locked = Borrower::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw LoanWriteTransaction::conflict(self::DECIDED);
            }

            if (self::branchOf($locked) !== $branchWhenChecked) {
                throw LoanWriteTransaction::conflict(self::CONFLICT);
            }

            BorrowerRegistration::lockForDuplicateCheck();

            if ($this->duplicates->memberWithEmail($locked->email, (int) $locked->getKey()) !== null) {
                throw LoanWriteTransaction::conflict(self::EMAIL_TAKEN);
            }

            $registration->setRawAttributes($locked->getAttributes(), true);

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

    private static function branchOf(Borrower $registration): ?int
    {
        return $registration->branch_id === null ? null : (int) $registration->branch_id;
    }
}
