<?php

namespace App\Services\CreditScoring;

use UnexpectedValueException;

/**
 * Exact fixed-point reading of the scorecard's and the settings' figures: a
 * weight in hundredths, a rate in ten-thousandths. A value is read from its
 * shortest decimal form, so 16.67 is 1667 hundredths exactly, and a value
 * with more decimals than allowed is an error, never rounded.
 */
final class Decimal
{
    /**
     * A non-negative number as an integer count of 10^-$places.
     */
    public static function scaled(int|float|string $value, int $places): int
    {
        $text = is_string($value) ? trim($value) : json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        if (! is_string($text) || preg_match('/^(\d+)(?:\.(\d+))?$/', $text, $parts) !== 1) {
            throw new UnexpectedValueException("'{$text}' is not a non-negative decimal number.");
        }

        $fraction = rtrim($parts[2] ?? '', '0');

        if (strlen($fraction) > $places) {
            throw new UnexpectedValueException("'{$text}' has more than {$places} decimals.");
        }

        return (int) $parts[1] * 10 ** $places + (int) str_pad($fraction, $places, '0');
    }

    /**
     * An integer count of 10^-$places back as a JSON-friendly number: an
     * int when whole, otherwise the float whose shortest form is exact.
     */
    public static function unscaled(int $scaled, int $places): int|float
    {
        $unit = 10 ** $places;

        return $scaled % $unit === 0 ? intdiv($scaled, $unit) : (float) self::text($scaled, $places);
    }

    /**
     * An integer count of 10^-$places as a decimal string with $places
     * decimals, as a decimal column stores it.
     */
    public static function text(int $scaled, int $places): string
    {
        $unit = 10 ** $places;

        return intdiv($scaled, $unit).($places > 0 ? '.'.str_pad((string) ($scaled % $unit), $places, '0', STR_PAD_LEFT) : '');
    }
}
