<?php

namespace App\Traits;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\Builders\AmlaAppendOnlyBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * An AMLA record, kept forever (AMLA design §4.14, §4.17): never deleted on
 * any path, never bulk-written, and never updated unless the model allows one
 * narrow change through amlaAllowsUpdate() (a pending change request's
 * decision, a calendar entry's removal). By default every update is refused.
 *
 * Refused in performUpdate() and performDeleteOnModel() rather than in
 * listeners, so saveQuietly(), withoutEvents() and destroy() are refused too.
 */
trait AmlaKeptForever
{
    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): AmlaAppendOnlyBuilder
    {
        return new AmlaAppendOnlyBuilder($query);
    }

    /**
     * Whether this row's pending change (getDirty() against getOriginal()) is
     * the one change the model allows. Append-only models allow none.
     */
    protected function amlaAllowsUpdate(): bool
    {
        return false;
    }

    /**
     * The values the stored row must still hold for the allowed change to be
     * written (a pending status, no removal yet), checked in the UPDATE itself
     * so two writers can never both make it. Null means the column is NULL.
     *
     * @return array<string, mixed>
     */
    protected function amlaUpdateConditions(): array
    {
        return [];
    }

    /**
     * Eloquent's own update, written through the base query so the bulk
     * refusals of AmlaAppendOnlyBuilder stay in place for everyone else, and
     * only when amlaAllowsUpdate() accepts the change.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        if (! $this->amlaAllowsUpdate()) {
            throw AmlaRecordIsAppendOnlyException::update($this->getTable(), $this->getKey());
        }

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();

        if ($dirty === []) {
            return true;
        }

        $base = $this->setKeysForSaveQuery($query)->toBase();

        foreach ($this->amlaUpdateConditions() as $column => $value) {
            $value === null ? $base->whereNull($column) : $base->where($column, $value);
        }

        if ($base->update($dirty) !== 1) {
            throw AmlaRecordIsAppendOnlyException::update($this->getTable(), $this->getKey());
        }

        $this->refreshSavedAttributes();
        $this->syncChanges();
        $this->fireModelEvent('updated', false);

        return true;
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    {
        throw AmlaRecordIsAppendOnlyException::update($this->getTable(), $this->getKey());
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw AmlaRecordIsAppendOnlyException::update($this->getTable(), $this->getKey());
    }

    protected function performDeleteOnModel(): never
    {
        throw AmlaRecordIsAppendOnlyException::delete($this->getTable(), $this->getKey());
    }
}
