<?php

namespace App\Services\Savings;

use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Savings row locks, in the design's order (§4): savings accounts (step 3),
 * then time deposits (step 4), each in ONE id-ordered `SELECT … FOR UPDATE`,
 * so two writers touching the same rows always wait in the same order and
 * never deadlock. Taken inside the caller's transaction; refused outside one,
 * where the lock would be released at once.
 */
class SavingsLocks
{
    /**
     * The accounts, locked, keyed by id.
     *
     * @param  iterable<int>  $ids
     * @return Collection<int, SavingsAccount>
     */
    public function accounts(iterable $ids): Collection
    {
        return $this->lock(SavingsAccount::class, $ids);
    }

    /** One account, locked; 404 when it doesn't exist. */
    public function account(int $id): SavingsAccount
    {
        return $this->accounts([$id])->get($id) ?? throw (new ModelNotFoundException)->setModel(SavingsAccount::class, [$id]);
    }

    /**
     * The time deposits, locked, keyed by id.
     *
     * @param  iterable<int>  $ids
     * @return Collection<int, TimeDeposit>
     */
    public function timeDeposits(iterable $ids): Collection
    {
        return $this->lock(TimeDeposit::class, $ids);
    }

    /** One time deposit, locked; 404 when it doesn't exist. */
    public function timeDeposit(int $id): TimeDeposit
    {
        return $this->timeDeposits([$id])->get($id) ?? throw (new ModelNotFoundException)->setModel(TimeDeposit::class, [$id]);
    }

    /**
     * @template T of SavingsAccount|TimeDeposit
     *
     * @param  class-string<T>  $model
     * @param  iterable<int>  $ids
     * @return Collection<int, T>
     */
    private function lock(string $model, iterable $ids): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Savings rows can only be locked inside a transaction.');
        }

        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($ids === []) {
            return new Collection;
        }

        return $model::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }
}
