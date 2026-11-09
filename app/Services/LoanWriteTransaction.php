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
 * - recording a payment (RepaymentService::processRepayment());
 * - voiding a payment (RepaymentService::voidRepayment());
 * - creating a loan (LoanService::createLoan(), POST /loans);
 * - approving, rejecting and applying an adjustment
 *   (LoanAdjustmentService::approveAdjustment(), rejectAdjustment(),
 *   applyAdjustment());
 * - extending a loan (LoanAdjustmentService::extendLoan());
 * - submitting a loan for review (LoanService::submitForReview()), which
 *   seeds its approval chain and, on a submit -> release chain, approves it;
 * - approving and rejecting a loan in review (LoanService::approve(),
 *   reject());
 * - signing off an approval-chain step and sending a loan back
 *   (LoanApprovalChainService::approve(), sendBack());
 * - sending an approved restructure back for approval
 *   (LoanService::sendBackApproved());
 * - voiding a loan that was never released (LoanService::voidLoan());
 * - editing a loan with no collateral list (LoanService::updateLoan()), which
 *   locks the loan row and re-checks that it is still editable. With a list the
 *   edit is a collateral write (CollateralWriteTransaction).
 *
 * Two share capital writes touch no loan but use it for its 409: an auto-credit
 * run and a bulk entry (ShareCapitalService::processAutoCredit(),
 * bulkManualEntries()). Tried again, either could post a second time, so a
 * collision answers 409 once with nothing posted.
 *
 * ── The lock order ───────────────────────────────────────────────────────
 *
 * The order every collateral write takes too (see CollateralAttacher), so no
 * two of these paths, and none of them and a collateral write, hold a lock the
 * other is waiting for in the ordinary case:
 *
 *   1. the collateral rows the operation touches, in ONE id-ordered statement,
 *      as the transaction's first locking read (and before its first plain
 *      read, for CollateralPledgeGuard's snapshot rule). A release and a
 *      payment void can move a loan into Loan::PLEDGING_STATUSES, so they
 *      take it; a
 *      create pledges nothing, a payment only moves a loan within that set or
 *      out of it, an adjustment or an extension neither changes what a
 *      loan pledges nor moves it into or out of that set, and a submit or
 *      an approval decision (a send-back of an approved restructure included)
 *      only moves a loan between statuses that pledge nothing (draft,
 *      for_review, approved, rejected), and a loan void only moves one from
 *      those to void, so none of those has collateral rows to lock;
 *   2. the loan rows, in ONE id-ordered statement (a release, and a send-back
 *      of an approved restructure, also lock the loan it restructures, so the
 *      two queue up on the lower id instead of deadlocking; a create locks
 *      the newest loan, for the next application number, in Loan::booted()).
 *      With no collateral to lock, this is the transaction's first statement,
 *      before any plain read. A restructure's principal edit is the one path
 *      that locks the child first: its own row, then its source, because a release of that child needs
 *      `approved` while an edit needs `draft` or `for_review`, and no other
 *      write waits on an editable child's row while holding its source;
 *   3. the newest repayment row, for the next receipt number, when the
 *      operation records a payment: recording one (processRepayment(),
 *      processRepaymentWithAmountUnderLock()) or an extension collecting its
 *      interest (extendLoan()). A locking read in Repayment::booted(), after
 *      the loan rows. A preview numbers its rolled-back payment with a
 *      placeholder and takes no such lock;
 *   4. the operation's own row: the payment being voided, the adjustment
 *      being approved, rejected or applied, the approval-chain step being
 *      signed off or sent back (a direct approve or reject has none). A void locks only the repayment
 *      it voids and never the newest one, so it holds no receipt lock a
 *      payment could be waiting on while it waits for that payment's loan.
 *
 * Under those locks each path reads again what it decided on before the
 * transaction opened, and answers conflict() when another write got there
 * first: a payment already voided, an adjustment already decided or applied,
 * a loan already released or extended, one closed before a payment or an
 * adjustment reached it, a loan already submitted, or a loan or chain step
 * already decided. A request that was wrong from the start is
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
 * create was inserting the next one gets that number's duplicate-key error,
 * and so does a payment that read the newest receipt number while another
 * payment was inserting the next one (Repayment::RECEIPT_NUMBER_INDEX).
 * The caller names the index (run()'s `$racedUniqueIndex`), so only that
 * collision becomes a 409 and every other unique violation stays the error it
 * is. A co-maker or adjustment number these paths generate is the same race
 * (GeneratedNumberWrite::isCollision()): a 409 too, not retried, because a
 * loan's money write is never run twice in one request.
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
     * A duplicate-key error on `$racedUniqueIndex`, when given, or on a
     * generated number (GeneratedNumberWrite::isCollision()), is a 409 too. Any
     * other database error is rethrown untouched.
     *
     * `$conflictMessage` replaces the 409's wording for a path where "this
     * loan" is the wrong thing to say: a create has no loan yet.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  string|null  $racedUniqueIndex  the unique index ("loans_application_number_unique") whose collision means another write got there first
     * @param  string|null  $conflictMessage  the 409's message, when not conflict()'s own
     * @return TReturn
     *
     * @throws HttpResponseException 409 on a deadlock, a lock wait timeout or a collision on `$racedUniqueIndex`
     */
    public static function run(Closure $callback, ?string $racedUniqueIndex = null, ?string $conflictMessage = null): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException $e) {
            if (($racedUniqueIndex === null || $e->index !== $racedUniqueIndex) && ! GeneratedNumberWrite::isCollision($e)) {
                throw $e;
            }

            throw self::conflict($conflictMessage);
        } catch (PDOException $e) {
            if (! (new self)->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw self::conflict($conflictMessage);
        }
    }

    /**
     * Run `$callback` inside a transaction that is always rolled back, for a
     * preview that runs a real write to read its result and keeps none of it
     * (RepaymentService::previewAllocation()). A deadlock or lock wait timeout
     * on its locks answers 409, as run() does; any other error is rethrown.
     *
     * @throws HttpResponseException 409 on a concurrency error
     */
    public static function rolledBack(Closure $callback, ?string $conflictMessage = null): mixed
    {
        DB::beginTransaction();

        try {
            return $callback();
        } catch (PDOException $e) {
            if (! (new self)->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw self::conflict($conflictMessage);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * The 409 for a write that another one got to first, for a caller that
     * detects that itself rather than through a database error.
     */
    public static function conflict(?string $message = null): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message ?? 'Another change to this loan was saved at the same time. Reload and try again.',
        ], 409));
    }
}
