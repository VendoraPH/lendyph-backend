<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Carries out an approved savings request (design §4). One class per kind,
 * named by convention and found by ApprovalGate::executor():
 * `Approvals\{StudlyKind}Executor` (WithdrawalExecutor, CloseExecutor and
 * ReversalExecutor from slice S2; TdPayoutExecutor and PreterminationExecutor
 * from S3).
 *
 * The approval desk opens the transaction, takes the locks in the design's
 * order — the accounts from {@see self::accountIds()} in one id-ordered
 * statement (SavingsLocks::accounts), then the time deposits from
 * {@see self::timeDepositIds()}, then the approval row — re-checks that the
 * approval is still pending, and only then calls {@see self::execute()} on
 * the final approval. It stores the returned transaction's id as the
 * approval's `result_transaction_id` and marks it approved.
 */
interface ApprovalExecutor
{
    /**
     * The savings account ids this approval moves money on, read before any
     * lock is taken (from the approval's own columns and payload).
     *
     * @return list<int>
     */
    public function accountIds(SavingsApproval $approval): array;

    /**
     * The time deposit ids it settles, locked after the accounts.
     *
     * @return list<int>
     */
    public function timeDepositIds(SavingsApproval $approval): array;

    /**
     * Do what was approved, under those locks and inside the desk's
     * transaction: re-validate against the locked rows (the money may be gone
     * since the request), write through SavingsLedger, post the journal
     * through AutomaticPoster, and return the main resulting transaction
     * (null when there is none).
     *
     * @throws ValidationException 422 when it can no longer be done; the desk rolls back and the approval stays pending
     */
    public function execute(SavingsApproval $approval, User $checker): ?SavingsTransaction;
}
