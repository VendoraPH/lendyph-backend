<?php

namespace App\Services\Branches;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A member's branch lock (Phase 6 MB-BE4), held around a member transfer and
 * around every write that copies the member's branch onto a new record: a
 * loan application, a time deposit placement, a member's GCash transaction
 * and a portal request.
 *
 * The two queue: a creation that starts while a transfer is being written
 * waits for it and then reads the new branch, and a transfer that starts
 * while a creation is being written waits and then sees its record and
 * refuses. Without it, a creation that read the branch just before a transfer
 * could write its record in the old branch just after.
 *
 * Like BranchWriteGuard, it's an application lock held outside the database
 * transaction, not the member's row FOR SHARE in a transaction of our own:
 * that would pull the services' plain reads into it and fix their REPEATABLE
 * READ snapshot before their own collateral and loan locks. The services run
 * their own transactions unchanged.
 *
 * Waiting is always bounded, and refused in words (409) when it runs out:
 * - at most WAIT_SECONDS of wall-clock time, measured with microtime(), not
 *   now(), so a frozen test clock can't make it wait forever;
 * - not at all while a database transaction is open, so a caller never holds
 *   row locks while it waits for the member (a busy member answers 409 at
 *   once there);
 * - and never on itself: a member already held by this process runs the
 *   nested write straight away, and the outer holder releases the lock.
 */
final class MemberLock
{
    public const BUSY = "This member's branch is being changed. Try again in a moment.";

    /** Longest a holder keeps the lock. */
    private const LOCK_SECONDS = 60;

    /** How long a write waits for the lock before answering 409. */
    private const WAIT_SECONDS = 5;

    /** Pause between tries while waiting, in microseconds. */
    private const RETRY_MICROSECONDS = 100_000;

    /** @var array<int, true> Members this process holds the lock for now. */
    private static array $held = [];

    public static function key(int $borrowerId): string
    {
        return "member-branch:{$borrowerId}";
    }

    /**
     * Run `$write` holding the member's lock. With no member (a walk-in's
     * GCash transaction), `$write` runs as it is.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public static function whileHeld(int|string|null $borrowerId, Closure $write): mixed
    {
        if ($borrowerId === null || $borrowerId === '') {
            return $write();
        }

        $id = (int) $borrowerId;

        if (isset(self::$held[$id])) {
            return $write();
        }

        $lock = Cache::lock(self::key($id), self::LOCK_SECONDS);
        $deadline = microtime(true) + (DB::transactionLevel() > 0 ? 0 : self::WAIT_SECONDS);

        while (! $lock->get()) {
            if (microtime(true) >= $deadline) {
                throw new HttpException(409, self::BUSY);
            }

            usleep(self::RETRY_MICROSECONDS);
        }

        self::$held[$id] = true;

        try {
            return $write();
        } finally {
            unset(self::$held[$id]);
            $lock->release();
        }
    }
}
