<?php

namespace App\Traits;

use App\Exceptions\OriginationRecordIsAppendOnlyException;

/**
 * An append-only loan origination model: inserted once, never updated or
 * deleted on any path, the way SavingsAppendOnly refuses it, with its own
 * exception.
 */
trait OriginationAppendOnly
{
    use SavingsAppendOnly;

    /**
     * @return class-string<OriginationRecordIsAppendOnlyException>
     */
    public function appendOnlyException(): string
    {
        return OriginationRecordIsAppendOnlyException::class;
    }
}
