<?php

namespace App\Services\Branches;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Moving a member to another branch (Phase 6, multi-branch decision 7).
 *
 * Loans, savings accounts, time deposits, portal requests and GCash rows stay
 * in the branch where they were opened, and nothing moves one. So a transfer
 * is refused, in words, while the member has any of these still open:
 * - a loan from draft to defaulted (D11: the refusal never asks to move a loan);
 * - a savings account that isn't closed, or a time deposit not yet settled (D5);
 * - a portal request still open;
 * - a GCash Cash In not yet paid (the new branch couldn't mark it paid).
 *
 * The transfer holds the member's branch lock (MemberLock), which loan
 * creation, time deposit placement, member GCash creation and portal request
 * submission also take, so none of those copies the old branch around a
 * move. Inside it, the transfer reads the member's loans with a locking read,
 * then locks the member's row (the order a release and an extension take),
 * then reads the other records with locking reads, so a record written for
 * the member by any other path at the same moment is seen too (the transfer
 * waits for it). A collision that still deadlocks answers 409 in words
 * (LoanWriteTransaction), and the caller's reach is re-checked on the
 * member's branch under the lock (404). The destination is read under a
 * shared lock: a suspended or closed branch can't receive a member (MB-BE1).
 *
 * The move writes one audit row on the member, naming both branches in words,
 * with the reason, filed under the source branch; the model's own `updated` row is replaced, not added to.
 */
final class MemberTransfer
{
    /** Each open record's words, one and many, in the refusal's order. */
    private const WORDS = [
        'collectible' => ['loan still being paid', 'loans still being paid'],
        'unreleased' => ['loan application not yet released', 'loan applications not yet released'],
        'savings' => ['open savings account', 'open savings accounts'],
        'deposits' => ['open time deposit', 'open time deposits'],
        'portal' => ['open portal request', 'open portal requests'],
        'gcash' => ['GCash Cash In not yet paid', 'GCash Cash Ins not yet paid'],
    ];

    /** Loans not yet released: a draft, one for approval and an approved one. */
    private const UNRELEASED_LOAN_STATUSES = ['draft', 'for_review', 'approved'];

    public const CONFLICT = 'This member changed at the same moment. Reload and try again.';

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException when `$by` can no longer see the member's branch
     */
    public function transfer(Borrower $borrower, int $toBranchId, string $reason, User $by): Borrower
    {
        return MemberLock::whileHeld($borrower->getKey(), fn (): Borrower => LoanWriteTransaction::run(function () use ($borrower, $toBranchId, $reason, $by): Borrower {
            // The member's loans first, in one id-ordered statement, then the
            // member: the order a release and an extension take (the loan,
            // then the member, through FormerMemberGuard), so the two queue
            // instead of deadlocking.
            $loanStatuses = Loan::query()->where('borrower_id', $borrower->getKey())->orderBy('id')->sharedLock()->pluck('status')->all();

            /** @var Borrower $member */
            $member = Borrower::query()->whereKey($borrower->getKey())->lockForUpdate()->firstOrFail();

            // The caller's reach, re-checked on the branch the member is in now.
            if (! app(BranchAccess::class)->canSee($by, $member->branch_id === null ? null : (int) $member->branch_id)) {
                throw (new ModelNotFoundException)->setModel(Borrower::class, [$member->getKey()]);
            }

            if ($member->branch_id === null) {
                self::refuse('This member has no branch yet. Assign one by editing the member.');
            }

            /** @var Branch $to */
            $to = Branch::query()->whereKey($toBranchId)->sharedLock()->firstOrFail();

            if ((int) $member->branch_id === (int) $to->id) {
                self::refuse("This member is already in {$to->auditName()}.");
            }

            if ($to->status !== Branch::STATUS_ACTIVE) {
                $words = $to->status === Branch::STATUS_CLOSED ? 'is closed' : 'is suspended';
                self::refuse("{$to->auditName()} {$words}, so it can't receive members.");
            }

            $open = $this->stillOpen((int) $member->id, $loanStatuses);

            if ($open !== []) {
                self::refuse(sprintf(
                    "This member can't be transferred while they have %s. Loans, savings, time deposits, portal requests and GCash Cash Ins stay in the branch where they were opened, so close or settle them first.",
                    self::listInWords($open),
                ));
            }

            /** @var Branch $from */
            $from = Branch::query()->findOrFail($member->branch_id);

            // Written before the move, in the same transaction, so the row is
            // filed under the source branch, as an interbranch transfer is.
            AuditLogService::log(
                'member_transferred',
                $member,
                ['branch_id' => (int) $from->id],
                ['branch_id' => (int) $to->id],
                sprintf(
                    'Member %s (%s) was transferred from %s to %s. Reason: %s',
                    $member->full_name,
                    $member->borrower_code,
                    $from->auditName(),
                    $to->auditName(),
                    $reason,
                ),
            );

            AuditLogService::withoutModelAuditing(fn () => $member->update(['branch_id' => $to->id]));

            return $member;
        }, conflictMessage: self::CONFLICT));
    }

    /**
     * What still holds the member in their branch, in words ("1 loan still
     * being paid", "2 open savings accounts"). The loans were read with a
     * locking read before the member; the rest are locking reads too: they
     * see rows committed by now and wait for rows being written for the
     * member.
     *
     * @param  list<string>  $loanStatuses
     * @return list<string>
     */
    private function stillOpen(int $memberId, array $loanStatuses): array
    {
        $counts = [
            'collectible' => count(array_intersect($loanStatuses, Loan::COLLECTIBLE_STATUSES)),
            'unreleased' => count(array_intersect($loanStatuses, self::UNRELEASED_LOAN_STATUSES)),
            'savings' => $this->count(SavingsAccount::query()->where('status', '!=', SavingsAccount::STATUS_CLOSED), $memberId),
            'deposits' => $this->count(TimeDeposit::query()->whereIn('status', BranchStatus::OPEN_TIME_DEPOSIT_STATUSES), $memberId),
            'portal' => $this->count(PortalRequest::query()->whereIn('status', PortalRequest::OPEN_STATUSES), $memberId),
            'gcash' => $this->count(GCashTransaction::query()->where('type', 'cash_in')->where('status', 'pending'), $memberId),
        ];

        $open = [];

        foreach ($counts as $key => $count) {
            if ($count > 0) {
                [$one, $many] = self::WORDS[$key];
                $open[] = $count.' '.($count === 1 ? $one : $many);
            }
        }

        return $open;
    }

    /** @param  Builder<Model>  $query */
    private function count(Builder $query, int $memberId): int
    {
        return $query->where('borrower_id', $memberId)->sharedLock()->pluck('id')->count();
    }

    /** @throws ValidationException */
    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['branch_id' => $message]);
    }

    /** @param  list<string>  $items */
    private static function listInWords(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
