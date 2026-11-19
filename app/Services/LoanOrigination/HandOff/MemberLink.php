<?php

namespace App\Services\LoanOrigination\HandOff;

use App\Models\Borrower;
use App\Models\OriginationApplication;
use App\Models\User;
use App\Rules\NotAFormerMember;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\LoanOrigination\Assessment\AssessmentService;
use App\Services\LoanWriteTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Linking an existing member to an online application before its hand-off
 * (origination decision 13), or unlinking one (null).
 *
 * - Needs `loan_origination:update` and `borrowers:view`, and the member must
 *   be in one of the caller's branches.
 * - A former member (withdrawn or deceased) or a rejected registration is
 *   refused, in words.
 * - The member's record is never changed: differences from the application
 *   show as conflicts in the assessment, which must run again before the
 *   hand-off, since the member's own Lendy loans now count.
 * - Only from submission until the hand-off, at the version the client read
 *   (409 otherwise). Audited with ids only.
 */
final class MemberLink
{
    public const REFUSAL = 'Linking a member needs the permission to update online applications and the permission to view members.';

    public function __construct(private readonly BranchAccess $branches) {}

    public function link(OriginationApplication $application, User $user, ?int $borrowerId, int $version): OriginationApplication
    {
        if (! $user->can('loan_origination:update') || ! $user->can('borrowers:view')) {
            throw new AuthorizationException(self::REFUSAL);
        }

        $member = $borrowerId === null ? null : $this->member($user, $borrowerId);

        return DB::transaction(function () use ($application, $user, $member, $version): OriginationApplication {
            /** @var OriginationApplication $locked */
            $locked = OriginationApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->version !== $version) {
                throw LoanWriteTransaction::conflict(ApplicationTransitions::STALE);
            }

            if ($locked->loan_id !== null || ! in_array($locked->status, AssessmentService::ASSESSABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'A member can be linked only before the application is submitted for approval.']);
            }

            $before = $locked->borrower_id;

            if ($before === $member?->id) {
                return $locked;
            }

            $locked->borrower_id = $member?->id;
            $locked->save();

            AuditLogService::log(
                $member === null ? 'member_unlinked' : 'member_linked',
                $locked,
                oldValues: ['borrower_id' => $before],
                newValues: ['borrower_id' => $member?->id, 'created' => false],
                description: $member === null
                    ? "Member unlinked from online application #{$locked->id}"
                    : "Member #{$member->id} linked to online application #{$locked->id}",
                userId: $user->id,
            );

            return $locked;
        });
    }

    private function member(User $user, int $borrowerId): Borrower
    {
        $member = Borrower::query()->whereKey($borrowerId)->first(['id', 'branch_id', 'status', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code', 'membership_ended_on']);

        if ($member === null || ! $this->branches->canSee($user, $member->branch_id === null ? null : (int) $member->branch_id)) {
            throw ValidationException::withMessages(['borrower_id' => 'Choose a member in one of your branches.']);
        }

        if ($member->isFormerMember()) {
            throw ValidationException::withMessages(['borrower_id' => NotAFormerMember::messageFor($member)]);
        }

        if ($member->status === 'rejected') {
            throw ValidationException::withMessages(['borrower_id' => 'This member\'s registration was rejected, so they can\'t be linked.']);
        }

        return $member;
    }
}
