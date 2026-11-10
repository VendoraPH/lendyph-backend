<?php

namespace App\Traits;

use App\Exceptions\SavingsRecordIsAppendOnlyException;
use App\Models\Builders\SavingsAppendOnlyBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * An append-only savings model: a row is inserted once and never updated or
 * deleted, on any path (save, update, delete, increment, a bulk query), the
 * way AuditLog refuses them. A rate's history goes only with a product that
 * never held an account, through the database's own cascade.
 */
trait SavingsAppendOnly
{
    /**
     * The exception class this model refuses with.
     *
     * @return class-string<SavingsRecordIsAppendOnlyException>
     */
    public function appendOnlyException(): string
    {
        return SavingsRecordIsAppendOnlyException::class;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): SavingsAppendOnlyBuilder
    {
        return new SavingsAppendOnlyBuilder($query);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): never
    {
        throw ($this->appendOnlyException())::update($this->getTable(), $this->getKey());
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    {
        throw ($this->appendOnlyException())::update($this->getTable(), $this->getKey());
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw ($this->appendOnlyException())::update($this->getTable(), $this->getKey());
    }

    protected function performDeleteOnModel(): never
    {
        throw ($this->appendOnlyException())::delete($this->getTable(), $this->getKey());
    }
}
