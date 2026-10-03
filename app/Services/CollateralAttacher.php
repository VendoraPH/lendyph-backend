<?php

namespace App\Services;

use App\Models\Collateral;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * Pledging a collateral to a loan and taking it off again: the one
 * implementation behind POST and DELETE /loans/{loan}/collaterals
 * (CollateralController), the `collaterals` list on PUT /loans/{loan}
 * (LoanService::updateLoan()) and the restructure copy
 * (LoanService::inheritCollaterals()). Every pledge it writes or removes is
 * recorded in the audit log against the loan, `collateral_attached` or
 * `collateral_detached`, with the value it was pledged at, so no path can
 * change what secures a loan without leaving that on the record.
 *
 * It comes in separate calls because of CollateralPledgeGuard's snapshot rule.
 * The guards are read-then-insert, and the unique index on (loan_id,
 * collateral_id) cannot cover the important one: two requests pledging one
 * collateral to two DIFFERENT loans write two distinct rows. So every
 * contender for a collateral serializes on that collateral's row lock, and
 * the lock has to be taken before the transaction's first plain read, or the
 * guards answer from a snapshot that predates it. A caller therefore takes
 * every lock it needs first, then attaches or detaches each collateral. All
 * of it must run inside the caller's OUTERMOST transaction, as
 * CollateralPledgeGuard::lockCollateralsOf() requires.
 *
 * ── The lock order ───────────────────────────────────────────────────────
 *
 * Every path that changes a pledge locks in the same order:
 *
 *   1. what the loan holds, read off its `loan_collaterals` rows with a
 *      locking read, only when the path needs to know (the list on PUT
 *      /loans/{loan});
 *   2. every collateral row the operation touches, in ONE statement ordered by
 *      id (lock());
 *   3. the loan row (lockEditableLoan()).
 *
 * Two transactions asking for overlapping rows in one stated order queue
 * rather than deadlock. Two separately ordered statements do not have that
 * property: {1, 3} then {2} in one transaction and {2, 3} in another can cycle.
 * CollateralController::update() keeps the same shape (collateral, then the
 * loans holding it), and so do LoanService::release() and the restructure.
 * What ordering cannot rule out (see CollateralPledgeGuard::lockCollateralsOf()
 * on what ORDER BY does and does not promise) CollateralWriteTransaction turns
 * into a 409.
 */
class CollateralAttacher
{
    /**
     * Take the X lock on each collateral the operation touches, in ONE
     * statement, in id order.
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

        $ids = array_values(array_unique(array_map('intval', $collateralIds)));
        sort($ids);

        return Collateral::whereKey($ids)
            // The same stated order as CollateralPledgeGuard::lockCollateralsOf();
            // see the note there on what it does and does not promise.
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * The locks for a `collaterals` list on PUT /loans/{loan}: what the loan
     * holds now (it may be detached) and what the list names (it may be
     * attached), locked together as one id-ordered set.
     *
     * What the loan holds is read off its `loan_collaterals` rows with a
     * locking read. A plain read would fix the transaction's snapshot before
     * the collateral locks are granted, which is the one thing the snapshot
     * rule forbids. Locking those rows also keeps the set from changing
     * underneath the reconcile.
     *
     * @param  array<int, int>  $listedIds
     * @return array{held: list<int>, collaterals: EloquentCollection<int, Collateral>}
     */
    public static function lockForList(Loan $loan, array $listedIds): array
    {
        $held = $loan->collaterals()
            ->newPivotQuery()
            ->orderBy('collateral_id')
            ->lockForUpdate()
            ->pluck('collateral_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return ['held' => $held, 'collaterals' => self::lock([...$held, ...$listedIds])];
    }

    /**
     * Lock the loan row, after its collateral, and refuse unless the loan is
     * still editable.
     *
     * Read from the locked row rather than from the model the request was
     * routed with, so a status change committed since then is seen, and none
     * can commit until this transaction ends. A loan's collateral is part of
     * the application that gets approved, so once it is approved (or any
     * status after) its pledges are fixed.
     *
     * @throws ValidationException on `status`
     */
    public static function lockEditableLoan(Loan $loan): Loan
    {
        $locked = Loan::whereKey($loan->getKey())->lockForUpdate()->first();

        if (! $locked?->is_editable) {
            throw ValidationException::withMessages([
                'status' => ['Collateral can only be attached or detached while the loan is in draft or for_review status.'],
            ]);
        }

        return $locked;
    }

    /**
     * Attach a collateral already locked by lock(), after the same guards the
     * attach endpoint has always run, and record it.
     *
     * @param  Collateral|null  $collateral  lock()'s row for this id, null when it was not found
     *
     * @throws ValidationException on `collateral_id`
     */
    public static function attachLocked(Loan $loan, ?Collateral $collateral, float|int|string $snapshotValue, ?User $user): void
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

        self::pledge($loan, $collateral->id, $snapshotValue, $user);
    }

    /**
     * Copy collateral onto a restructure at the value each was pledged at, and
     * record each copy, WITHOUT the attach guards.
     *
     * Only LoanService::inheritCollaterals() calls this, holding the row locks
     * on these collaterals; see it for why the guards would refuse a transfer
     * they exist to protect.
     *
     * @param  array<int, float|int|string>  $snapshotValues  keyed by collateral id
     */
    public static function inheritLocked(Loan $newLoan, array $snapshotValues, ?User $user): void
    {
        foreach ($snapshotValues as $collateralId => $snapshotValue) {
            self::pledge($newLoan, (int) $collateralId, $snapshotValue, $user);
        }
    }

    /**
     * Take a collateral off a loan, and record the value it had been pledged
     * at.
     *
     * The caller holds the collateral's row lock and has passed
     * lockEditableLoan(). Every write of a pledge of this collateral takes the
     * same lock, so the pledge read here cannot change before it is removed.
     *
     * @throws ValidationException on `collateral` when the loan does not hold it
     */
    public static function detachLocked(Loan $loan, int $collateralId, ?User $user): void
    {
        $pledge = $loan->collaterals()
            ->newPivotQuery()
            ->where('collateral_id', $collateralId)
            ->first();

        if (! $pledge) {
            throw ValidationException::withMessages([
                'collateral' => 'This collateral is not attached to the loan.',
            ]);
        }

        $loan->collaterals()->detach($collateralId);

        $snapshotValue = round((float) $pledge->snapshot_value, 2);

        AuditLogService::log(
            action: 'collateral_detached',
            auditable: $loan,
            oldValues: ['collateral_id' => $collateralId, 'snapshot_value' => $snapshotValue],
            newValues: ['collateral_id' => $collateralId, 'snapshot_value' => null],
            description: sprintf(
                'Collateral #%d, pledged at ₱%s, detached from loan %s',
                $collateralId,
                number_format($snapshotValue, 2),
                self::reference($loan),
            ),
            userId: $user?->id,
        );
    }

    private static function pledge(Loan $loan, int $collateralId, float|int|string $snapshotValue, ?User $user): void
    {
        $loan->collaterals()->attach($collateralId, [
            'snapshot_value' => $snapshotValue,
            'attached_at' => now(),
        ]);

        // As the decimal(14, 2) column stores it.
        $pledged = round((float) $snapshotValue, 2);

        AuditLogService::log(
            action: 'collateral_attached',
            auditable: $loan,
            oldValues: ['collateral_id' => $collateralId, 'snapshot_value' => null],
            newValues: ['collateral_id' => $collateralId, 'snapshot_value' => $pledged],
            description: sprintf(
                'Collateral #%d attached to loan %s at ₱%s',
                $collateralId,
                self::reference($loan),
                number_format($pledged, 2),
            ),
            userId: $user?->id,
        );
    }

    /**
     * The loan as an operator would name it: its account number once released,
     * its application number before.
     */
    public static function reference(Loan $loan): string
    {
        return $loan->loan_account_number ?? $loan->application_number ?? "#{$loan->getKey()}";
    }
}
