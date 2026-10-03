<?php

namespace App\Services;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * The transaction every collateral write runs in: attach and detach
 * (CollateralController), PUT /collaterals/{id}, PUT /loans/{loan} when it
 * carries `collaterals`, restructure creation, which copies pledges onto
 * the new loan (LoanService::restructure()), and DELETE /loans/{loan}, which
 * detaches a draft's pledges before deleting it.
 *
 * Those paths all lock in one order (see CollateralAttacher), which keeps two
 * of them from deadlocking in the ordinary case but cannot rule it out: InnoDB
 * does not promise that ORDER BY is the order it takes row locks in, and a
 * payment void, which locks its loan's collateral through
 * CollateralPledgeGuard::lockCollateralsOf(), still takes gap locks on
 * `loan_collaterals`. The loan writes outside this set (a release, a payment
 * void, a loan create, an adjustment apply and an extension) lock in the same
 * order, collateral rows, then loan rows, then their own row, and answer their
 * own clashes with a 409 through LoanWriteTransaction. When MySQL breaks a deadlock
 * or a lock wait times out, nothing the client sent was wrong; another change
 * got there first. The transaction is rolled back, so nothing of this one is
 * left, and the answer is a 409 the client can reload and retry from, never a
 * 500.
 *
 * Scoped to these paths on purpose. The global exception handler is left
 * alone, so a deadlock anywhere else still surfaces as the error it is.
 */
final class CollateralWriteTransaction
{
    use DetectsConcurrencyErrors;

    /**
     * Run `$callback` in a transaction, answering a deadlock or a lock wait
     * timeout with a 409. Must be the OUTERMOST transaction, as
     * CollateralPledgeGuard::lockCollateralsOf() requires of its callers.
     *
     * Any other database error is rethrown untouched.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws HttpResponseException 409 on a deadlock or a lock wait timeout
     */
    public static function run(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (PDOException $e) {
            if (! (new self)->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw self::conflict();
        }
    }

    /**
     * The 409 for a write that another one got to first, for a caller that
     * detects that itself rather than through a database error.
     */
    public static function conflict(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'Another change to this collateral was saved at the same time. Reload and try again.',
        ], 409));
    }
}
