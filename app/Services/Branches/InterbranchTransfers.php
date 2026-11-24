<?php

namespace App\Services\Branches;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\InterbranchTransfer;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Money;
use App\Services\Accounting\ResolvesPostingAccounts;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\LoanWriteTransaction;
use App\Services\SequenceCode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Interbranch transfers (Phase 6, multi-branch decision 9).
 *
 * - **Create** (`interbranch_transfers:create`): a maker who can see the
 *   source branch names the two branches, the money account the money leaves
 *   and the one it arrives in, the amount, a purpose and a reference. Nothing
 *   posts yet.
 * - **Approve** (`:approve`): never by the maker, super_admin included, and
 *   only by someone who can see the source branch. Posts, in the source
 *   branch, Dr 1190 Interbranch Clearing / Cr the money account. In transit.
 * - **Receive** (`:receive`): by someone who can see the destination branch
 *   and is neither the maker nor the approver, super_admin included. Posts,
 *   in the destination branch, Dr the money account / Cr the clearing
 *   account the dispatch debited (stored at approval, so re-pointing the
 *   role in between never splits the pair across two accounts).
 * - **Reverse** (`:approve`, with a reason): only while in transit, never by
 *   the maker (super_admin included), by someone who can see the source.
 *   Reverses the dispatch through JournalPoster::reverse(), linked to its
 *   mirror. A received transfer can't be reversed (422): the money goes back
 *   as a new transfer from the destination to the source.
 * - **Reject** (a checker, with a note) and **cancel** (the maker) close a
 *   pending transfer; nothing was posted.
 *
 * Every journal goes through JournalPoster (PeriodGuard included), is dated
 * today in Manila, is source `transfer` and carries the transfer number as
 * its reference. Each step locks the two branch rows (shared, in id order, so
 * a closure approval waits) and then the transfer row, re-checks the status
 * (409 when another step got there first) and writes one audit row in words.
 *
 * While opening balances carry no branch, a source money account going below
 * ₱0.00 in its branch's books is a warning (self::warnings()), never a
 * refusal.
 */
class InterbranchTransfers
{
    use ResolvesPostingAccounts;

    public const CLEARING_ROLE = 'interbranch_clearing';

    public const MAKER_CANNOT_APPROVE = 'You made this transfer, so someone else has to approve it.';

    public const MAKER_CANNOT_RECEIVE = 'You made this transfer, so someone else has to receive it.';

    public const APPROVER_CANNOT_RECEIVE = 'You approved this transfer, so someone else has to receive it.';

    public const MAKER_CANNOT_REVERSE = 'You made this transfer, so someone else has to reverse it.';

    public const SOURCE_CLOSED = 'The source branch is closed, so no money can move out of it.';

    public const DESTINATION_CLOSED = 'The destination branch is closed, so no money can move into it.';

    public const SOURCE_CLOSING = 'The source branch is waiting to close, so no money can move out of it.';

    public const DESTINATION_CLOSING = 'The destination branch is waiting to close, so no money can move into it.';

    public function __construct(
        private JournalPoster $poster,
        private TrialBalanceBuilder $trialBalance,
        private BranchAccess $access,
    ) {}

    /**
     * @param  array{source_branch_id: int, destination_branch_id: int, from_account_id: int, to_account_id: int, amount: int, purpose: string, reference?: string|null}  $data
     * @return array{0: InterbranchTransfer, 1: list<string>}
     */
    public function create(array $data, User $maker): array
    {
        $this->requireMoneyAccount((int) $data['from_account_id'], 'from_account_id');
        $this->requireMoneyAccount((int) $data['to_account_id'], 'to_account_id');

        // A lock wait behind a closure step, or a deadlock, answers 409.
        $transfer = BranchRowLock::transaction(function () use ($data, $maker): InterbranchTransfer {
            $this->lockBranches((int) $data['source_branch_id'], (int) $data['destination_branch_id'], onCreate: true);

            $transfer = new InterbranchTransfer([...$data, 'created_by' => $maker->id]);
            $transfer->status = InterbranchTransfer::STATUS_PENDING;
            $transfer->save();

            // From the id, so two saves can never take the same number.
            $transfer->forceFill([
                'transfer_number' => InterbranchTransfer::CODE_PREFIX.'-'.str_pad((string) $transfer->id, SequenceCode::PAD_LENGTH, '0', STR_PAD_LEFT),
            ])->save();

            $this->audit('interbranch_transfer_created', $transfer, null, [
                'status' => $transfer->status,
                'amount' => $transfer->amount,
            ], sprintf(
                'Interbranch transfer %s of %s from %s (%s) to %s (%s) was requested. Purpose: %s',
                $transfer->transfer_number,
                Money::format($transfer->amount),
                $this->branchName($transfer->source_branch_id),
                $this->accountLabel($transfer->from_account_id),
                $this->branchName($transfer->destination_branch_id),
                $this->accountLabel($transfer->to_account_id),
                $transfer->purpose,
            ), $maker);

            return $transfer;
        });

        return [$transfer, $this->warnings($transfer)];
    }

    /** @return array{0: InterbranchTransfer, 1: list<string>} */
    public function approve(InterbranchTransfer $transfer, User $approver): array
    {
        return DB::transaction(function () use ($transfer, $approver): array {
            $locked = $this->lock($transfer);
            $this->assertStatus($locked, InterbranchTransfer::STATUS_PENDING, 'waiting for approval');

            if ($locked->created_by === $approver->id) {
                throw new HttpException(403, self::MAKER_CANNOT_APPROVE);
            }

            $this->assertSees($approver, $locked->source_branch_id, 'Approving posts in %s\'s books, so it needs access to that branch.');

            $clearing = $this->requirePostingRole(self::CLEARING_ROLE, 'An interbranch transfer');
            $from = $this->requireMoneyAccount($locked->from_account_id, 'from_account_id');
            $warnings = $this->warnings($locked);

            $journal = $this->post($locked, $locked->source_branch_id, sprintf(
                'Interbranch transfer %s to %s: %s',
                $locked->transfer_number,
                $this->branchName($locked->destination_branch_id),
                $locked->purpose,
            ), [
                ['account_id' => $clearing->id, 'debit' => $locked->amount, 'credit' => 0],
                ['account_id' => $from->id, 'debit' => 0, 'credit' => $locked->amount],
            ], $approver);

            $locked->forceFill([
                'status' => InterbranchTransfer::STATUS_IN_TRANSIT,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'dispatch_journal_id' => $journal->id,
                'clearing_account_id' => $clearing->id,
            ])->save();

            $this->audit('interbranch_transfer_approved', $locked, ['status' => InterbranchTransfer::STATUS_PENDING], [
                'status' => $locked->status,
                'journal_no' => $journal->journal_no,
            ], sprintf(
                'Interbranch transfer %s of %s was approved and sent from %s; %s posted.',
                $locked->transfer_number,
                Money::format($locked->amount),
                $this->branchName($locked->source_branch_id),
                $journal->journal_no,
            ), $approver);

            return [$locked, $warnings];
        });
    }

    public function receive(InterbranchTransfer $transfer, User $receiver): InterbranchTransfer
    {
        return DB::transaction(function () use ($transfer, $receiver): InterbranchTransfer {
            $locked = $this->lock($transfer);
            $this->assertStatus($locked, InterbranchTransfer::STATUS_IN_TRANSIT, 'in transit');
            if ($locked->created_by === $receiver->id) {
                throw new HttpException(403, self::MAKER_CANNOT_RECEIVE);
            }

            if ($locked->approved_by === $receiver->id) {
                throw new HttpException(403, self::APPROVER_CANNOT_RECEIVE);
            }

            $this->assertSees($receiver, $locked->destination_branch_id, 'Receiving posts in %s\'s books, so it needs access to that branch.');

            // The account the dispatch debited, never whatever the role
            // points at now: the pair must net to zero on one account.
            $clearingId = $locked->clearing_account_id;
            $to = $this->requireMoneyAccount($locked->to_account_id, 'to_account_id');

            $journal = $this->post($locked, $locked->destination_branch_id, sprintf(
                'Interbranch transfer %s from %s: %s',
                $locked->transfer_number,
                $this->branchName($locked->source_branch_id),
                $locked->purpose,
            ), [
                ['account_id' => $to->id, 'debit' => $locked->amount, 'credit' => 0],
                ['account_id' => $clearingId, 'debit' => 0, 'credit' => $locked->amount],
            ], $receiver);

            $locked->forceFill([
                'status' => InterbranchTransfer::STATUS_RECEIVED,
                'received_by' => $receiver->id,
                'received_at' => now(),
                'receipt_journal_id' => $journal->id,
            ])->save();

            $this->audit('interbranch_transfer_received', $locked, ['status' => InterbranchTransfer::STATUS_IN_TRANSIT], [
                'status' => $locked->status,
                'journal_no' => $journal->journal_no,
            ], sprintf(
                'Interbranch transfer %s of %s was received in %s; %s posted.',
                $locked->transfer_number,
                Money::format($locked->amount),
                $this->branchName($locked->destination_branch_id),
                $journal->journal_no,
            ), $receiver);

            return $locked;
        });
    }

    public function reverse(InterbranchTransfer $transfer, User $by, string $reason): InterbranchTransfer
    {
        return DB::transaction(function () use ($transfer, $by, $reason): InterbranchTransfer {
            $locked = $this->lock($transfer);

            if ($locked->status === InterbranchTransfer::STATUS_RECEIVED) {
                throw ValidationException::withMessages(['transfer' => [sprintf(
                    "A received transfer can't be reversed. To send the money back, make a new transfer from %s to %s.",
                    $this->branchName($locked->destination_branch_id),
                    $this->branchName($locked->source_branch_id),
                )]]);
            }

            $this->assertStatus($locked, InterbranchTransfer::STATUS_IN_TRANSIT, 'in transit');

            if ($locked->created_by === $by->id) {
                throw new HttpException(403, self::MAKER_CANNOT_REVERSE);
            }

            $this->assertSees($by, $locked->source_branch_id, 'Reversing posts in %s\'s books, so it needs access to that branch.');

            $mirror = $this->poster->reverse(AccountingJournal::query()->findOrFail($locked->dispatch_journal_id), now()->toDateString(), $reason, $by->id);

            $locked->forceFill([
                'status' => InterbranchTransfer::STATUS_REVERSED,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'dispatch_reversal_journal_id' => $mirror->id,
            ])->save();

            $this->audit('interbranch_transfer_reversed', $locked, ['status' => InterbranchTransfer::STATUS_IN_TRANSIT], [
                'status' => $locked->status,
                'reversal_reason' => $reason,
            ], sprintf(
                'Interbranch transfer %s of %s was reversed before it was received (%s posted). Reason: %s',
                $locked->transfer_number,
                Money::format($locked->amount),
                $mirror->journal_no,
                $reason,
            ), $by);

            return $locked;
        });
    }

    public function reject(InterbranchTransfer $transfer, User $by, string $note): InterbranchTransfer
    {
        return DB::transaction(function () use ($transfer, $by, $note): InterbranchTransfer {
            $locked = $this->lock($transfer);
            $this->assertStatus($locked, InterbranchTransfer::STATUS_PENDING, 'waiting for approval');

            if ($locked->created_by === $by->id) {
                throw new HttpException(403, 'You made this transfer, so cancel it instead of rejecting it.');
            }

            $this->assertSees($by, $locked->source_branch_id, 'Rejecting needs access to %s, the branch the money would leave.');

            $locked->forceFill([
                'status' => InterbranchTransfer::STATUS_REJECTED,
                'rejected_by' => $by->id,
                'rejected_at' => now(),
                'rejection_note' => $note,
            ])->save();

            $this->audit('interbranch_transfer_rejected', $locked, ['status' => InterbranchTransfer::STATUS_PENDING], [
                'status' => $locked->status,
                'rejection_note' => $note,
            ], "Interbranch transfer {$locked->transfer_number} was rejected. Note: {$note}", $by);

            return $locked;
        });
    }

    public function cancel(InterbranchTransfer $transfer, User $by): InterbranchTransfer
    {
        return DB::transaction(function () use ($transfer, $by): InterbranchTransfer {
            $locked = $this->lock($transfer);
            $this->assertStatus($locked, InterbranchTransfer::STATUS_PENDING, 'waiting for approval');

            if ($locked->created_by !== $by->id) {
                throw new HttpException(403, 'Only the person who made this transfer can cancel it.');
            }

            $locked->forceFill(['status' => InterbranchTransfer::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

            $this->audit('interbranch_transfer_cancelled', $locked, ['status' => InterbranchTransfer::STATUS_PENDING], [
                'status' => $locked->status,
            ], "Interbranch transfer {$locked->transfer_number} was cancelled by the person who made it.", $by);

            return $locked;
        });
    }

    /**
     * The warning for a source money account this transfer takes below ₱0.00
     * in its branch's books, by the trial balance's own aggregate; none once
     * the transfer has left. Opening balances are recorded with no branch, so
     * a branch's own books can read below zero while the money is there:
     * never a refusal.
     *
     * @return list<string>
     */
    public function warnings(InterbranchTransfer $transfer): array
    {
        if ($transfer->status !== InterbranchTransfer::STATUS_PENDING) {
            return [];
        }

        $accountId = $transfer->from_account_id;
        $balance = $this->trialBalance->signedBalances(null, $transfer->source_branch_id, [$accountId])[$accountId] ?? 0;
        $after = $balance - $transfer->amount;

        if ($after >= 0) {
            return [];
        }

        return [sprintf(
            "After this transfer, %s reads %s in %s's books. Opening balances are recorded with no branch, so a branch's own books can read below ₱0.00; check the money is there.",
            $this->accountLabel($accountId),
            Money::format($after),
            $this->branchName($transfer->source_branch_id),
        )];
    }

    /**
     * Shared locks on the two branch rows in id order (so a closure approval,
     * which locks its branch for update, waits for this step, and this step
     * for it), then a refusal when either is closed.
     *
     * The wording names neither branch: the maker may not be able to see the
     * destination (as BranchTakesNew never names one).
     *
     * @param  bool  $onCreate  blame the request fields rather than `branch`
     *
     * @throws ValidationException
     */
    private function lockBranches(int $sourceId, int $destinationId, bool $onCreate = false): void
    {
        $branches = Branch::query()->whereIn('id', [$sourceId, $destinationId])->orderBy('id')->sharedLock()->get()->keyBy('id');

        foreach ([[$sourceId, 'source_branch_id', self::SOURCE_CLOSED], [$destinationId, 'destination_branch_id', self::DESTINATION_CLOSED]] as [$id, $field, $message]) {
            if ($branches->get($id)?->status === Branch::STATUS_CLOSED) {
                throw ValidationException::withMessages([$onCreate ? $field : 'branch' => [$message]]);
            }
        }

        if (! $onCreate) {
            return;
        }

        // A new transfer is refused by a branch waiting to close too (finish
        // run round 2, decision 4), read under the branch locks just taken, as
        // BranchRowLock does. One already made goes on: the closure's
        // checklist waits for it.
        $closing = BranchClosure::query()->whereIn('branch_id', [$sourceId, $destinationId])->where('status', BranchClosure::STATUS_PENDING)
            ->orderBy('branch_id')->sharedLock()->pluck('branch_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ([[$sourceId, 'source_branch_id', self::SOURCE_CLOSING], [$destinationId, 'destination_branch_id', self::DESTINATION_CLOSING]] as [$id, $field, $message]) {
            if (in_array($id, $closing, true)) {
                throw ValidationException::withMessages([$field => [$message]]);
            }
        }
    }

    /** The two branches, then the transfer row, locked; the transfer read fresh. */
    private function lock(InterbranchTransfer $transfer): InterbranchTransfer
    {
        $this->lockBranches($transfer->source_branch_id, $transfer->destination_branch_id);

        return InterbranchTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @throws HttpResponseException 409 */
    private function assertStatus(InterbranchTransfer $transfer, string $expected, string $words): void
    {
        if ($transfer->status !== $expected) {
            throw LoanWriteTransaction::conflict(
                "This transfer is no longer {$words}: it is ".strtolower(InterbranchTransfer::STATUS_LABELS[$transfer->status]).'. Reload it.'
            );
        }
    }

    /** @throws HttpException 403, naming the branch */
    private function assertSees(User $user, int $branchId, string $message): void
    {
        if (! $this->access->canSee($user, $branchId)) {
            throw new HttpException(403, sprintf($message, $this->branchName($branchId)));
        }
    }

    /**
     * @param  list<array{account_id: int, debit: int, credit: int}>  $lines
     */
    private function post(InterbranchTransfer $transfer, int $branchId, string $description, array $lines, User $by): AccountingJournal
    {
        return $this->poster->postImmediately([
            'date' => now()->toDateString(),
            'source' => 'transfer',
            'reference' => $transfer->transfer_number,
            'description' => mb_substr($description, 0, 500),
            'branch_id' => $branchId,
        ], $lines, $by->id);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(string $action, InterbranchTransfer $transfer, ?array $old, array $new, string $description, User $by): void
    {
        AuditLogService::log($action, $transfer, $old, $new, $description, $by->id);
    }

    private function branchName(int $id): string
    {
        return (string) Branch::query()->whereKey($id)->value('name');
    }

    private function accountLabel(int $id): string
    {
        $account = AccountingAccount::query()->find($id, ['code', 'name']);

        return $account === null ? 'that account' : "{$account->code} {$account->name}";
    }
}
