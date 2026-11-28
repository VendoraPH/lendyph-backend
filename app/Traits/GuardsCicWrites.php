<?php

namespace App\Traits;

use App\Models\Builders\CicGuardedBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * A CIC model whose rows must stay as they were once settled (captures, their
 * records, subjects, public keys). Each write goes through the model's own
 * check, cicGuardInsert(), cicGuardUpdate() or cicGuardDelete(), which throws
 * App\Exceptions\CicRecordIsFrozenException to refuse it. The checks run in
 * performInsert(), performUpdate() and performDeleteOnModel() rather than in
 * listeners, so saveQuietly() and withoutEvents() are checked too, and every
 * bulk update or delete is refused (CicGuardedBuilder).
 */
trait GuardsCicWrites
{
    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): CicGuardedBuilder
    {
        return new CicGuardedBuilder($query);
    }

    /** Throws to refuse a new row. */
    protected function cicGuardInsert(): void {}

    /** Throws to refuse this row's pending change (getDirty() against getOriginal()). */
    protected function cicGuardUpdate(): void {}

    /** Throws to refuse deleting this row. */
    abstract protected function cicGuardDelete(): void;

    /**
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        $this->cicGuardInsert();

        return parent::performInsert($query);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        $this->cicGuardUpdate();

        if ($query instanceof CicGuardedBuilder) {
            $query->cicModelWrite();
        }

        return parent::performUpdate($query);
    }

    /** Eloquent's own delete, on the one query the check let through. */
    protected function performDeleteOnModel(): void
    {
        $this->cicGuardDelete();

        $query = $this->newModelQuery();

        if ($query instanceof CicGuardedBuilder) {
            $query->cicModelWrite();
        }

        $this->setKeysForSaveQuery($query)->delete();

        $this->exists = false;
    }

    /**
     * The columns this change would write, `updated_at` left out.
     *
     * @return list<string>
     */
    protected function cicDirtyColumns(): array
    {
        return array_values(array_diff(array_keys($this->getDirty()), [$this->getUpdatedAtColumn()]));
    }
}
