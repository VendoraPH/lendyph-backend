<?php

namespace App\Services\CreditScoring;

use App\Services\Accounting\Money;

/**
 * Integer centavo arithmetic for the engine. Pesos are read digit by digit
 * through Money::toCentavos(), and every share is rounded half up to the
 * centavo, so a capacity figure never carries binary rounding.
 */
final class Centavos
{
    /**
     * Pesos to centavos; null, blank and negative amounts read as zero.
     */
    public static function of(string|int|float|null $pesos): int
    {
        return Money::toCentavos($pesos) ?? 0;
    }

    /**
     * Pesos to centavos, keeping a blank amount blank.
     */
    public static function orNull(string|int|float|null $pesos): ?int
    {
        return $pesos === null ? null : self::of($pesos);
    }

    /**
     * `$percent` per cent of an amount, rounded half up (away from zero) to
     * the centavo. The percent is read to four decimal places, as the
     * settings store it, and never rounded further.
     */
    public static function share(int $centavos, string|int|float $percent): int
    {
        $tenThousandths = (int) round(((float) $percent) * 10000);
        $numerator = $centavos * $tenThousandths;
        $denominator = 1_000_000;
        $quotient = intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);

        return $numerator < 0 ? -$quotient : $quotient;
    }

    /**
     * ₱ with two decimals (Money::format()).
     */
    public static function format(int $centavos): string
    {
        return Money::format($centavos);
    }

    /**
     * Centavos as a two-decimal peso string, as the lending tables store it.
     */
    public static function toPesos(?int $centavos): ?string
    {
        return $centavos === null ? null : number_format($centavos / 100, 2, '.', '');
    }
}
