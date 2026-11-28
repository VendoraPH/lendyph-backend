<?php

namespace App\Models\Builders;

use App\Exceptions\CicRecordIsFrozenException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Refuses every bulk update and delete on a guarded CIC model (captures, their
 * records, subjects, public keys), so each write goes through the model's own
 * checks (GuardsCicWrites), whose query alone is let through (cicModelWrite()).
 * Inserts go through; CicCaptureRecordBuilder also checks them.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class CicGuardedBuilder extends Builder
{
    /** Set only on the query a model's own checked save or delete runs (GuardsCicWrites). */
    private bool $modelWrite = false;

    /**
     * Marks this query as the model's own write, which its checks have already
     * passed: the one update or delete it runs goes through.
     */
    public function cicModelWrite(): static
    {
        $this->modelWrite = true;

        return $this;
    }

    public function update(array $values): int
    {
        if (! $this->modelWrite) {
            throw $this->refused('update');
        }

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw $this->refused('upsert');
    }

    public function touch($column = null): never
    {
        throw $this->refused('touch');
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw $this->refused('increment');
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw $this->refused('decrement');
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        throw $this->refused('incrementEach');
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        throw $this->refused('decrementEach');
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw $this->refused('updateOrInsert');
    }

    public function updateFrom(array $values): never
    {
        throw $this->refused('updateFrom');
    }

    public function delete(): mixed
    {
        if (! $this->modelWrite) {
            throw $this->refused('delete');
        }

        return parent::delete();
    }

    public function forceDelete(): never
    {
        throw $this->refused('forceDelete');
    }

    public function truncate(): never
    {
        throw $this->refused('truncate');
    }

    protected function refused(string $operation): CicRecordIsFrozenException
    {
        return CicRecordIsFrozenException::bulk($this->model->getTable(), $operation);
    }
}
