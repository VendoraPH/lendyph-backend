<?php

namespace App\Services\Branches;

use App\Models\Branch;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What a branch's status allows (Phase 6, multi-branch decision 2), checked
 * where a write happens:
 * - active: everything;
 * - suspended: no new members, loans, releases, savings accounts, placements
 *   or imports; collections and withdrawals go on;
 * - closed: nothing new, and existing rows aren't changed in ways that would
 *   reopen them (a void).
 *
 * The FormRequests refuse early, in words, through the BranchTakesNew and
 * MemberBranchTakesNew rules. The writes a closure's checklist counts (a new
 * loan, a restructure, a release) and the ones that would reopen a closed
 * branch's rows (a void) also run inside whileTakingNew() or whileOpen():
 * they read the status and write while holding the branch's lock, and a
 * status change or a closure approval (BranchStatus) holds the same lock, so
 * the two never interleave.
 *
 * It is one exclusive lock per branch, so the guarded writes in one branch
 * also queue behind each other: two releases in the same branch run one
 * after the other. They are short, and a coop's branch sees few at once; a
 * write that waits longer than `$waitSeconds` answers 409 (BUSY). Writes in
 * different branches never wait for each other. The lock lives in the
 * default cache store (`database` in `.env.example`), shared by every
 * php-fpm worker of a deployment.
 *
 * ## Why a lock, not the branch row FOR SHARE in an outer transaction
 *
 * Wrapping LoanService::release() or RepaymentService::voidRepayment() in a
 * transaction of our own pulls their pre-transaction plain reads into it. On
 * REPEATABLE READ the first of those fixes the snapshot before their
 * collateral and loan locks, and their guards then answer from a pre-lock
 * snapshot: CollateralIntegrityGuardsTest and RestructureReleaseConflictTest
 * fail exactly that way. The lock lives outside the database transaction, so
 * the services run their own transactions unchanged, and neither is edited.
 */
class BranchWriteGuard
{
    public const MEMBERS = 'no new members can be added there';

    public const LOANS = 'no new loans can be made there';

    public const RELEASES = 'no loans can be released there';

    public const SAVINGS_ACCOUNTS = 'no new savings accounts can be opened there';

    public const TIME_DEPOSITS = 'no new time deposits can be placed there';

    public const IMPORTS = 'no members or loans can be imported into it';

    public const PAYMENT_VOIDS = "its payments can't be voided";

    public const LOAN_VOIDS = "its loans can't be voided";

    public const BUSY = 'Another change in this branch is being saved. Try again in a moment.';

    /**
     * Longest a holder keeps the lock: a release slowed by row-lock waits
     * finishes well inside it, and a request killed midway frees the branch
     * after it at most.
     */
    public const LOCK_SECONDS = 120;

    /**
     * @param  int  $waitSeconds  how long a write waits for the lock before answering 409
     */
    public function __construct(private readonly int $waitSeconds = 15) {}

    /**
     * Run `$write` while the branch takes new work (active), or refuse.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function whileTakingNew(int $branchId, string $consequence, Closure $write): mixed
    {
        return $this->exclusively($branchId, function () use ($branchId, $consequence, $write): mixed {
            $branch = $this->current($branchId);

            if ($branch->status !== Branch::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['branch' => [self::refusal($branch, $consequence)]]);
            }

            return $write();
        });
    }

    /**
     * Run `$write` unless the branch is closed.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function whileOpen(int $branchId, string $consequence, Closure $write): mixed
    {
        return $this->exclusively($branchId, function () use ($branchId, $consequence, $write): mixed {
            $branch = $this->current($branchId);

            if ($branch->status === Branch::STATUS_CLOSED) {
                throw ValidationException::withMessages(['branch' => [self::refusal($branch, $consequence)]]);
            }

            return $write();
        });
    }

    /**
     * Run `$then` holding the branch's lock: the guarded writes above, and
     * BranchStatus's status changes and closure approval. Only waiting for the
     * lock answers 409 (BUSY); anything `$then` throws, a database lock
     * timeout included, passes through unchanged, and the lock is released
     * either way.
     *
     * @template T
     *
     * @param  Closure(): T  $then
     * @return T
     */
    public function exclusively(int $branchId, Closure $then): mixed
    {
        $lock = Cache::lock("branch-status:{$branchId}", self::LOCK_SECONDS);

        try {
            $lock->block($this->waitSeconds);
        } catch (LockTimeoutException) {
            throw new HttpException(409, self::BUSY);
        }

        try {
            return $then();
        } finally {
            $lock->release();
        }
    }

    /** `Branch "Town" (TWN) is suspended, so no new loans can be made there.` */
    public static function refusal(Branch $branch, string $consequence): string
    {
        return sprintf('Branch %s is %s, so %s.', $branch->auditName(), $branch->status, $consequence);
    }

    /**
     * The branch as committed now. A plain read outside any transaction of
     * ours, so it fixes no snapshot for the write that follows; the lock held
     * around it keeps the status from changing until the write is done.
     */
    private function current(int $branchId): Branch
    {
        return Branch::query()->whereKey($branchId)->firstOrFail(['id', 'name', 'code', 'status']);
    }
}
