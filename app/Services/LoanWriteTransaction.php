<?php

namespace App\Services;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * The transaction the writes that change a loan's money or lifecycle run in,
 * and the order they lock in:
 *
 * - a loan release (LoanService::release());
 * - voiding a payment (RepaymentService::voidRepayment());
 * - creating a loan (LoanService::createLoan(), POST /loans);
 * - applying an approved adjustment (LoanAdjustmentService::applyAdjustment());
 * - extending a loan (LoanAdjustmentService::extendLoan()).
 *
 * ── The lock order ───────────────────────────────────────────────────────
 *
 * The order every collateral write takes too (see CollateralAttacher), so no
 * two of these paths, and none of them and a collateral write, hold a lock the
 * other is waiting for in the ordinary case:
 *
 *   1. the collateral rows the operation touches, in ONE id-ordered statement,
 *      as the transaction's first locking read (and before its first plain
 *      read, for CollateralPledgeGuard's snapshot rule). A release and a void
 *      can move a loan into Loan::PLEDGING_STATUSES, so they take it; a
 *      create pledges nothing, and an adjustment or an extension neither
 *      changes what a loan pledges nor moves it into or out of that set, so
 *      those three have no collateral rows to lock;
 *   2. the loan rows, in ONE id-ordered statement (a release also locks the
 *      loan it restructures; a create locks the newest loan, for the next
 *      application number, in Loan::booted());
 *   3. the operation's own row: the payment being voided, the adjustment
 *      being applied.
 *
 * Under those locks each path reads again what it decided on before the
 * transaction opened, and answers conflict() when another write got there
 * first: a payment already voided, an adjustment already applied, a loan
 * already released or extended. A request that was wrong from the start is
 * still the 422 it always was, checked before the transaction opens.
 *
 * ── What ordering cannot rule out ────────────────────────────────────────
 *
 * InnoDB does not promise that ORDER BY is the order it takes row locks in,
 * and the newest loan's row a release takes for the next account number, or a
 * create for the next application number, is one no ordering can keep free of
 * a cycle with another writer of loan rows. So a deadlock or a lock wait
 * timeout can still happen. When it does, nothing the client sent was wrong:
 * another change got there first. The transaction is rolled back, so nothing
 * of this one is left, and the answer is a 409 the client can reload and retry
 * from, never a 500.
 *
 * The same goes for a unique index two concurrent writers can both pass the
 * check for: a create that read the newest application number while another
 * create was inserting the next one gets that number's duplicate-key error.
 * The caller names the index (run()'s `$racedUniqueIndex`), so only that
 * collision becomes a 409 and every other unique violation stays the error it
 * is.
 *
 * Scoped to these paths on purpose, as CollateralWriteTransaction is: the
 * global exception handler is left alone, so a deadlock anywhere else still
 * surfaces as the error it is.
 */
final class LoanWriteTransaction
{
    use DetectsConcurrencyErrors;

    /**
     * Run `$callback` in a transaction, answering a deadlock or a lock wait
     * timeout with a 409. Must be the OUTERMOST transaction, as
     * CollateralPledgeGuard::assertNoDoublePledge() requires of its callers:
     * nested, it would be a savepoint, and a deadlock inside one is not rolled
     * back by Laravel.
     *
     * A duplicate-key error on `$racedUniqueIndex`, when given, is a 409 too.
     * Any other database error is rethrown untouched.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  string|null  $racedUniqueIndex  the unique index ("loans_application_number_unique") whose collision means another write got there first
     * @return TReturn
     *
     * @throws HttpResponseException 409 on a deadlock, a lock wait timeout or a collision on `$racedUniqueIndex`
     */
    public static function run(Closure $callback, ?string $racedUniqueIndex = null): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException $e) {
            if ($racedUniqueIndex === null || $e->index !== $racedUniqueIndex) {
                throw $e;
            }

            throw self::conflict();
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
