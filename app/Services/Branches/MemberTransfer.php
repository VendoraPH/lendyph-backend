<?php

namespace App\Services\Branches;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
 * The check runs under the member's row lock and reads those records with
 * locking reads, so a loan or account written for the member at the same
 * moment is either seen (the transfer waits for it) or written after the move
 * (its creator then reads the new branch). The destination is read under a
 * shared lock: a suspended or closed branch can't receive a member (MB-BE1).
 *
 * The move writes one audit row on the member, naming both branches in words,
 * with the reason; the model's own `updated` row is replaced, not added to.
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

    /** @throws ValidationException */
    public function transfer(Borrower $borrower, int $toBranchId, string $reason): Borrower
    {
        return DB::transaction(function () use ($borrower, $toBranchId, $reason): Borrower {
            /** @var Borrower $member */
            $member = Borrower::query()->whereKey($borrower->getKey())->lockForUpdate()->firstOrFail();

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

            $open = $this->stillOpen((int) $member->id);

            if ($open !== []) {
                self::refuse(sprintf(
                    "This member can't be transferred while they have %s. Loans, savings, time deposits, portal requests and GCash Cash Ins stay in the branch where they were opened, so close or settle them first.",
                    self::listInWords($open),
                ));
            }

            /** @var Branch $from */
            $from = Branch::query()->findOrFail($member->branch_id);

            AuditLogService::withoutModelAuditing(fn () => $member->update(['branch_id' => $to->id]));

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

            return $member;
        });
    }

    /**
     * What still holds the member in their branch, in words ("1 loan still
     * being paid", "2 open savings accounts"). Locking reads: they see rows
     * committed by now and wait for rows being written for the member.
     *
     * @return list<string>
     */
    private function stillOpen(int $memberId): array
    {
        $counts = [
            'collectible' => $this->count(Loan::query()->whereIn('status', Loan::COLLECTIBLE_STATUSES), $memberId),
            'unreleased' => $this->count(Loan::query()->whereIn('status', self::UNRELEASED_LOAN_STATUSES), $memberId),
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
