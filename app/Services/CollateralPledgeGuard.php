<?php

namespace App\Services;

use App\Models\Collateral;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * The single place that answers "would this leave one collateral securing two
 * live loans?".
 *
 * There are exactly two ways to reach that state, and they need opposite
 * checks, which is why the original guard only covered one of them:
 *
 *   1. A PIVOT WRITE — `loan_collaterals` gains a row for a collateral some
 *      other active loan already holds. CollateralController::attach() is the
 *      only route in. Guarded by assertCollateralIsFree().
 *   2. A STATUS TRANSITION — no pivot write at all. A loan that already holds
 *      collateral moves INTO Loan::ACTIVE_STATUSES while another active loan
 *      holds the same collateral. Guarded by lockCollateralsOf() +
 *      assertNoDoublePledge().
 *
 * Both live here rather than in the callers because the rule they enforce is
 * one rule. Three copies of it in three services is how (2) went unguarded in
 * the first place.
 *
 * ── The snapshot rule, which is what makes any of this sound ──────────────
 *
 * Nothing here opens its own transaction, and every conflict read below is a
 * PLAIN `SELECT`. Under MySQL REPEATABLE READ — this application's isolation
 * level, unoverridden in config/database.php — a plain SELECT is answered from
 * the consistent snapshot established by the transaction's FIRST plain SELECT.
 * Neither a locking read nor any DML advances that snapshot.
 *
 * So taking the row lock is necessary but NOT sufficient. If any plain read has
 * already run in the enclosing transaction, this guard will lock the right rows
 * and then answer from a view of the world that predates the lock, missing a
 * pledge some other transaction committed in between — which is precisely the
 * double pledge it exists to refuse.
 *
 * Hence the split, and hence the contract: lockCollateralsOf() must be the
 * FIRST statement inside the caller's transaction, before anything reads
 * anything. assertNoDoublePledge() may then run wherever the caller's own
 * ordering requires. The ids thread from one to the other so the assertion
 * cannot be written without having taken the lock that licenses it.
 */
class CollateralPledgeGuard
{
    /**
     * Take the X lock on every `collaterals` row this loan holds, and return
     * their ids for assertNoDoublePledge().
     *
     * CALL THIS AS THE FIRST STATEMENT INSIDE THE TRANSACTION. Not "early" —
     * first. Every other writer that could add an active holder for one of
     * these collaterals (CollateralController::attach(),
     * LoanService::inheritCollaterals(), and the other status transitions
     * through this class) opens by locking the same rows, so once this returns,
     * no conflicting pledge can be committed until the caller's transaction
     * ends. Any snapshot the transaction establishes afterwards is therefore
     * post-lock, and the plain read in assertNoDoublePledge() is answered from
     * a view that already contains everything it needs to see.
     *
     * Put a plain `SELECT` above this call and that stops being true silently:
     * no error, no failing query, just a guard that reads a stale world. See
     * the class docblock.
     *
     * Locking through the relation covers the `loan_collaterals` rows as well
     * as the `collaterals` rows, so a concurrent detach cannot empty the set
     * between the lock and the assertion.
     *
     * ONE MORE PRECONDITION: the caller's `DB::transaction()` must be the
     * OUTERMOST one. Laravel compiles a nested transaction to a SAVEPOINT, not a
     * new transaction — the outer transaction's read view is already fixed by
     * then, so taking this lock first inside a nested block buys nothing and the
     * hoist silently stops working. No caller does that today, and any new one
     * must open its own top-level transaction rather than reuse a caller's.
     *
     * @return array<int, int> the locked collateral ids, possibly empty
     */
    public static function lockCollateralsOf(Loan $loan): array
    {
        return $loan->collaterals()
            // A CONVENTION, not a guarantee, and worth being precise about:
            // `ORDER BY` does not control the order InnoDB acquires locks in.
            // Locks are taken as rows are scanned, and the optimiser is free to
            // sort afterwards, so this cannot promise that two activations
            // contending for an overlapping set of collaterals queue rather than
            // deadlock — a genuine cycle is still resolved by MySQL's deadlock
            // detector rolling one transaction back. What it does buy is that
            // every path that locks these rows asks for them in one stated
            // order, which makes a cycle less likely and, more usefully, makes
            // the intent reviewable. LoanService::inheritCollaterals() carries
            // the same clause for the same reason; keep them in step.
            ->orderBy('collaterals.id')
            ->lockForUpdate()
            ->pluck('collaterals.id')
            ->all();
    }

    /**
     * Refuse a transition INTO Loan::ACTIVE_STATUSES that would make `$loan` a
     * SECOND active holder of collateral another active loan is already
     * standing on.
     *
     * This is the check the attach guard structurally cannot do. attach() fires
     * on a write into `loan_collaterals`; a loan changing status writes only to
     * `loans`, so no pivot guard will ever see it. Every path that moves a loan
     * from an inactive status into an active one must call this, INSIDE its own
     * transaction, having opened that transaction with lockCollateralsOf():
     *
     *   - LoanService::release()             approved  → released
     *   - RepaymentService::voidRepayment()  completed → ongoing/released
     *
     * That is the complete list for this codebase: they are the only two writes
     * of an active status from an inactive one anywhere in app/.
     * RepaymentService::processRepayment() also writes `ongoing`, but only over
     * `released`, which is already active — it cannot add a holder, so it does
     * not call this.
     *
     * `$collateralIds` is threaded in from lockCollateralsOf() rather than
     * re-derived here, deliberately: the assertion is only meaningful while
     * those rows are locked, so it takes the lock's own output as its argument
     * and cannot be called without one.
     *
     * @param  array<int, int>  $collateralIds  as returned by lockCollateralsOf()
     *
     * @throws ValidationException naming the conflicting loan(s), on `collateral`
     */
    public static function assertNoDoublePledge(array $collateralIds, Loan $loan): void
    {
        if ($collateralIds === []) {
            return;
        }

        $conflicts = self::activeHoldersOf($collateralIds, $loan);

        if ($conflicts->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'collateral' => $conflicts->count() === 1
                ? 'This loan holds collateral already pledged to active loan '.self::references($conflicts).'. Detach it from that loan first.'
                : 'This loan holds collateral already pledged to active loans '.self::references($conflicts).'. Detach it from those loans first.',
        ]);
    }

    /**
     * Refuse a collateral that some other loan in Loan::ACTIVE_STATUSES already holds.
     *
     * Note which side the status test is on: it is the CURRENT holders that must
     * not be active, not the loan being attached to. Pledging to a draft loan a
     * collateral that a released loan is holding is exactly the double pledge
     * this rejects. The converse — attaching a collateral whose only other
     * holder is `completed` — is DELIBERATELY permitted, and is precisely why
     * assertNoDoublePledge() has to exist: voiding a payment on that completed
     * loan re-activates it.
     *
     * The caller is expected to be holding the row lock on `$collateral`
     * already, and to have taken it before reading anything else in its
     * transaction; CollateralController::attach() opens with that lock.
     *
     * @throws ValidationException naming the conflicting loan(s), on `collateral_id`
     */
    public static function assertCollateralIsFree(Collateral $collateral, Loan $loan): void
    {
        $conflicts = self::activeHoldersOf([$collateral->getKey()], $loan);

        if ($conflicts->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'collateral_id' => $conflicts->count() === 1
                ? 'This collateral is already pledged to active loan '.self::references($conflicts).'. Detach it there first.'
                : 'This collateral is already pledged to active loans '.self::references($conflicts).'. Detach it there first.',
        ]);
    }

    /**
     * The loans in Loan::ACTIVE_STATUSES holding any of `$collateralIds`, other
     * than `$loan` itself.
     *
     * The one query both guards above are built out of — the shared part is the
     * QUESTION, not the wording of the refusal, which differs because the two
     * callers fail on different fields.
     *
     * A PLAIN read, on purpose. Making it a locking read would take locks on
     * `loans` rows, which cycles with attach(): attach holds the collateral X
     * lock and needs a shared `loans` lock for the foreign-key check on its
     * pivot INSERT, so a guard wanting an exclusive lock on that same `loans`
     * row would deadlock. Correctness comes from the caller having locked
     * first, not from locking here — see the class docblock.
     *
     * Only the two columns the messages read are selected, matching
     * Collateral::activeLoans(); the loans this hydrates are deliberately
     * partial models.
     *
     * @param  array<int, int>  $collateralIds
     * @return EloquentCollection<int, Loan>
     */
    private static function activeHoldersOf(array $collateralIds, Loan $loan): EloquentCollection
    {
        return Loan::query()
            ->select(['loans.id', 'loans.loan_account_number'])
            ->whereIn('loans.status', Loan::ACTIVE_STATUSES)
            ->whereKeyNot($loan->getKey())
            ->whereHas('collaterals', fn ($query) => $query->whereIn('collaterals.id', $collateralIds))
            ->orderBy('loans.id')
            ->get();
    }

    /**
     * The conflicting loans as an operator would name them, comma separated.
     *
     * Falls back to the id for a loan with no account number yet — an active
     * loan always has one, but the message must not be the thing that breaks if
     * that ever stops being true.
     *
     * @param  EloquentCollection<int, Loan>  $conflicts
     */
    private static function references(EloquentCollection $conflicts): string
    {
        return $conflicts
            ->map(fn (Loan $active) => $active->loan_account_number ?? "loan #{$active->getKey()}")
            ->implode(', ');
    }
}
