<?php

namespace App\Services;

use App\Models\Collateral;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
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
 *   1. every collateral row the operation touches, in ONE statement ordered by
 *      id (lock());
 *   2. the loan row (lockEditableLoan(), or the source loan's lock on a
 *      restructure).
 *
 * Nothing locks `loan_collaterals` itself, and that is deliberate. A locking
 * read of a loan's pledges takes gap locks, and gap locks do not conflict with
 * each other: two requests both get them, then each blocks the other's INSERT
 * into the gap, and MySQL kills one. Verified against MySQL 8: two parallel
 * attaches to one loan (what the new-loan page sends) deadlock whenever the
 * pledges are read FOR UPDATE first, and queue when they are not.
 *
 * A loan's pledges need no lock of their own, because every write that adds
 * or removes one holds the loan's row lock: attach, detach and the list here,
 * the restructure copy on its own new loan, and a loan's deletion. Under that
 * lock the set cannot change, so a PLAIN read of it made after it is exact
 * (heldBy()). The list
 * on PUT /loans/{loan} has to know what the loan holds before it can lock it,
 * so it reads that before its transaction opens and checks it again under the
 * loan's lock (lockForList()).
 *
 * Two requests for overlapping rows in this order queue rather than deadlock:
 * on the collateral row they share, or on the loan row. Two separately ordered
 * collateral statements do not have that property: {1, 3} then {2} in one
 * transaction and {2, 3} in another can cycle. CollateralController::update()
 * keeps the same shape (collateral, then the loans holding it), and so does
 * the restructure. What ordering cannot rule out (see
 * CollateralPledgeGuard::lockCollateralsOf() on what ORDER BY does and does
 * not promise) CollateralWriteTransaction turns into a 409.
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
     * The collateral ids the loan holds, in id order. A PLAIN read.
     *
     * Exact when the caller holds the loan's row lock, which every write that
     * adds or removes one of the loan's pledges also holds (see the class
     * docblock). Read before a
     * transaction opens it is only a guess, to be checked again under that
     * lock; it runs in its own autocommit transaction there, so it does not
     * fix the later transaction's snapshot.
     *
     * @return list<int>
     */
    public static function heldBy(Loan $loan): array
    {
        return $loan->collaterals()
            ->newPivotQuery()
            ->orderBy('collateral_id')
            ->pluck('collateral_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The locks for a `collaterals` list on PUT /loans/{loan}: what the loan
     * holds (it may be detached) and what the list names (it may be attached)
     * as ONE id-ordered lock, then the loan row.
     *
     * What the loan holds has to be known to be locked, and it cannot be read
     * under a lock of its own (see the class docblock), so `$heldBefore` is
     * read by the caller before the transaction opens. Under the loan's lock
     * it is read again, exactly. A collateral attached in between and not on
     * the list would have to be detached without its row lock, so that is
     * refused as the conflict it is; one detached in between was merely
     * locked for nothing.
     *
     * The loan's status is checked under its lock, by lockEditableLoan().
     *
     * @param  array<int, int>  $listedIds
     * @param  array<int, int>  $heldBefore  heldBy() read before the transaction opened
     * @return array{held: list<int>, collaterals: EloquentCollection<int, Collateral>}
     *
     * @throws HttpResponseException 409 when the loan gained an unlisted collateral since `$heldBefore`
     * @throws ValidationException on `status`
     */
    public static function lockForList(Loan $loan, array $listedIds, array $heldBefore): array
    {
        $collaterals = self::lock([...$heldBefore, ...$listedIds]);
        self::lockEditableLoan($loan);

        $held = self::heldBy($loan);

        if (array_diff($held, $collaterals->keys()->all()) !== []) {
            throw CollateralWriteTransaction::conflict();
        }

        return ['held' => $held, 'collaterals' => $collaterals];
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
     * on these collaterals and on the source loan; see it for why the guards
     * would refuse a transfer they exist to protect.
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
     * lockEditableLoan(). Every write of this pledge holds one of those two
     * locks, so the pledge read here cannot change before it is removed.
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
