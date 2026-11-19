<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An online application was asked to be deleted outside the retention
 * erasure, or while under a legal hold. Deleting one takes its consents,
 * notes and history with it, so only OriginationApplication::eraseForRetention()
 * may. Nothing was deleted.
 */
class OriginationErasureRefusedException extends RuntimeException
{
    public const LEGAL_HOLD = 'This application is under a legal hold, so it can\'t be erased.';

    public static function outsideRetention(mixed $id): self
    {
        return new self('origination_applications.id '.($id ?? 'unknown').' can only be deleted by the retention erasure. Nothing was deleted.');
    }

    public static function legalHold(): self
    {
        return new self(self::LEGAL_HOLD);
    }
}
