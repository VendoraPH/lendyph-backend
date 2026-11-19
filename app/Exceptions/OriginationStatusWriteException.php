<?php

namespace App\Exceptions;

use LogicException;

/**
 * An online application's status was written outside the transition table.
 * Only ApplicationTransitions::move() changes it, so every move is checked
 * and leaves a history row. Nothing was written.
 */
class OriginationStatusWriteException extends LogicException
{
    public static function outsideTransitions(mixed $id): self
    {
        return new self('origination_applications.id '.($id ?? 'new').': the status changes only through ApplicationTransitions::move(). Nothing was written.');
    }
}
