<?php

namespace App\Models\Builders;

use App\Exceptions\OriginationErasureRefusedException;
use App\Exceptions\OriginationStatusWriteException;
use App\Models\OriginationApplication;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query builder for online applications: a bulk update can't write the
 * status (only ApplicationTransitions moves it), and a bulk delete is
 * refused: only the retention erasure deletes, one application at a time,
 * through the model.
 *
 * @extends Builder<OriginationApplication>
 */
class OriginationApplicationBuilder extends Builder
{
    public function update(array $values)
    {
        if (array_key_exists('status', $values) && ! OriginationApplication::statusWriteIsOpen()) {
            throw OriginationStatusWriteException::outsideTransitions(null);
        }

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw OriginationStatusWriteException::outsideTransitions(null);
    }

    public function delete()
    {
        if (! OriginationApplication::erasureIsOpen()) {
            throw OriginationErasureRefusedException::outsideRetention(null);
        }

        return parent::delete();
    }

    public function forceDelete(): never
    {
        throw OriginationErasureRefusedException::outsideRetention(null);
    }

    public function truncate(): never
    {
        throw OriginationErasureRefusedException::outsideRetention(null);
    }
}
