<?php

namespace App\Services;

use App\Models\Collateral;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * Pledging a collateral to a loan: the one implementation behind
 * POST /loans/{loan}/collaterals (CollateralController::attach()) and the
 * `collaterals` list on PUT /loans/{loan} (LoanService::updateLoan()).
 *
 * It comes in two calls because of CollateralPledgeGuard's snapshot rule. The
 * guards are read-then-insert, and the unique index on (loan_id,
 * collateral_id) cannot cover the important one: two requests pledging one
 * collateral to two DIFFERENT loans write two distinct rows. So every
 * contender for a collateral serializes on that collateral's row lock, and
 * the lock has to be taken before the transaction's first plain read, or the
 * guards answer from a snapshot that predates it. A caller attaching several
 * collaterals therefore locks them ALL with lock() first, then attaches each
 * with attachLocked(). Both calls must run inside the caller's OUTERMOST
 * transaction, as CollateralPledgeGuard::lockCollateralsOf() requires.
 */
class CollateralAttacher
{
    /**
     * Take the X lock on each collateral about to be attached, in id order.
     *
     * CALL THIS BEFORE ANY PLAIN READ IN THE TRANSACTION. See the class
     * docblock and CollateralPledgeGuard's.
     *
     * The rows are read FOR UPDATE, which always returns the latest committed
     * version rather than the snapshot, so attachLocked() sees a borrower
     * reassignment that committed before the lock was granted.
     *
     * @param  array<int, int>  $collateralIds
     * @return EloquentCollection<int, Collateral> keyed by id; an id that no longer exists is missing
     */
    public static function lock(array $collateralIds): EloquentCollection
    {
        if ($collateralIds === []) {
            return new EloquentCollection;
        }

        return Collateral::whereKey($collateralIds)
            // The same stated order as CollateralPledgeGuard::lockCollateralsOf();
            // see the note there on what it does and does not promise.
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Attach a collateral already locked by lock(), after the same guards the
     * attach endpoint has always run.
     *
     * @param  Collateral|null  $collateral  lock()'s row for this id, null when it was not found
     *
     * @throws ValidationException on `collateral_id`
     */
    public static function attachLocked(Loan $loan, ?Collateral $collateral, float|int|string $snapshotValue): void
    {
        if (! $collateral) {
            throw ValidationException::withMessages([
                'collateral_id' => 'This collateral no longer exists.',
            ]);
        }

        // Ownership, re-asserted under the lock.
        //
        // The form request already scoped the collateral id to this loan's
        // borrower, but that ran BEFORE the transaction opened, and
        // PUT /api/collaterals/{id} can move a collateral between members.
        // Validated at T0, reassigned at T1, attached at T2 pledges to this loan
        // a collateral now registered to somebody else. lock() read the row FOR
        // UPDATE, so this comparison sees the reassignment;
        // CollateralController::update() takes the same lock, so the two
        // serialize whichever way they interleave.
        //
        // `$loan->borrower_id` needs no such care: UpdateLoanRequest exposes no
        // `borrower_id`, so a loan's member is fixed at creation.
        //
        // Same message as the form request's, deliberately — see
        // ValidatesLoanCollaterals::notThisLoansBorrowerMessage() for why the
        // two failure modes are not told apart.
        if ((int) $collateral->borrower_id !== (int) $loan->borrower_id) {
            throw ValidationException::withMessages([
                'collateral_id' => 'This collateral is not registered to this loan\'s borrower.',
            ]);
        }

        if ($loan->collaterals()->where('collaterals.id', $collateral->id)->exists()) {
            throw ValidationException::withMessages([
                'collateral_id' => 'This collateral is already attached to the loan.',
            ]);
        }

        CollateralPledgeGuard::assertCollateralIsFree($collateral, $loan);

        $loan->collaterals()->attach($collateral->id, [
            'snapshot_value' => $snapshotValue,
            'attached_at' => now(),
        ]);
    }
}
