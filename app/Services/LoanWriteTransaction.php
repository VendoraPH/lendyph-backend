<?php

namespace App\Services;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * The transaction a loan release runs in (LoanService::release()).
 *
 * A release locks in the order every collateral write does (see
 * CollateralAttacher): the collateral rows the loan holds in one id-ordered
 * statement, then the loan rows (the loan, and the loan it restructures) in
 * one id-ordered statement. It then takes the newest loan account number's
 * row, which no ordering can promise is free of a cycle with another writer
 * of loan rows, so a deadlock or a lock wait timeout can still happen. When
 * it does, nothing the client sent was wrong: another change got there first.
 * The transaction is rolled back, so nothing of this one is left, and the
 * answer is a 409 the client can reload and retry from, never a 500.
 *
 * Scoped to that path on purpose, as CollateralWriteTransaction is: the global
 * exception handler is left alone, so a deadlock anywhere else still surfaces
 * as the error it is.
 */
final class LoanWriteTransaction
{
    use DetectsConcurrencyErrors;

    /**
     * Run `$callback` in a transaction, answering a deadlock or a lock wait
     * timeout with a 409. Must be the OUTERMOST transaction, as
     * CollateralPledgeGuard::assertNoDoublePledge() requires of its callers.
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
            'message' => 'Another change to this loan was saved at the same time. Reload and try again.',
        ], 409));
    }
}
