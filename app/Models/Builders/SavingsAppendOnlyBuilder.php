<?php

namespace App\Models\Builders;

use App\Exceptions\SavingsRecordIsAppendOnlyException;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Refuses every bulk write on an append-only savings model, as AuditLogBuilder
 * does for audit rows. Inserts go through as usual.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class SavingsAppendOnlyBuilder extends Builder
{
    public function update(array $values): never
    {
        throw $this->refused('update');
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

    public function updateOrCreate(array $attributes, Closure|array $values = []): never
    {
        throw $this->refused('updateOrCreate');
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw $this->refused('updateOrInsert');
    }

    public function updateFrom(array $values): never
    {
        throw $this->refused('updateFrom');
    }

    public function delete(): never
    {
        throw $this->refused('delete');
    }

    public function forceDelete(): never
    {
        throw $this->refused('forceDelete');
    }

    public function truncate(): never
    {
        throw $this->refused('truncate');
    }

    private function refused(string $operation): SavingsRecordIsAppendOnlyException
    {
        $exception = $this->model->appendOnlyException();

        return $exception::bulk($this->model->getTable(), $operation);
    }
}
