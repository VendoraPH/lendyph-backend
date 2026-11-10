<?php

declare(strict_types=1);

namespace App\Services\Savings;

use App\Services\Accounting\Money;
use InvalidArgumentException;
use OverflowException;

/**
 * Exact integer arithmetic for savings (design §0, §5). Money is whole
 * centavos; a rate is a percent with up to four decimals, read exactly as
 * "rate units" (percent × 10⁴, so 2.5000% is 25,000 and 100% is 1,000,000).
 * No floats and no bcmath (not installed). Pure: no database, no clock.
 */
final class SavingsMath
{
    /** Rate units in one whole (100%): a rate unit is 10⁻⁶ of the amount. */
    public const RATE_DENOMINATOR = 1_000_000;

    /**
     * round(numerator ÷ denominator), half away from zero, exactly:
     * 0.5 centavo rounds up.
     */
    public static function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator must be greater than zero.');
        }

        $magnitude = $numerator < 0 ? self::negate($numerator) : $numerator;
        $quotient = intdiv($magnitude, $denominator);
        $remainder = $magnitude % $denominator;

        if ($remainder >= $denominator - $remainder) {
            $quotient++;
        }

        return $numerator < 0 ? -$quotient : $quotient;
    }

    /**
     * A percent with up to four decimals as rate units, exactly ("1.2345"
     * reads 12,345). Refuses a negative rate, more than four decimals, or more
     * than 100%.
     */
    public static function rateUnits(string|int $percent): int
    {
        $text = trim((string) $percent);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException("\"{$text}\" is not a rate between 0 and 100 with at most four decimals.");
        }

        $units = (int) $parts[1] * 10_000 + (int) str_pad($parts[2] ?? '', 4, '0');

        if ($units > self::RATE_DENOMINATOR) {
            throw new InvalidArgumentException("{$text}% is above 100%.");
        }

        return $units;
    }

    /** Rate units back to a four-decimal percent string ("2.5000"). */
    public static function rateString(int $units): string
    {
        return intdiv($units, 10_000).'.'.str_pad((string) ($units % 10_000), 4, '0', STR_PAD_LEFT);
    }

    /**
     * a × b, refused when the product would leave a 64-bit integer.
     *
     * @throws OverflowException
     */
    public static function multiply(int $a, int $b): int
    {
        $product = $a * $b;

        if (! is_int($product)) {
            throw new OverflowException("{$a} × {$b} is beyond any figure this system computes exactly.");
        }

        return $product;
    }

    /** a + b, refused on overflow. */
    public static function add(int $a, int $b): int
    {
        $sum = $a + $b;

        if (! is_int($sum)) {
            throw new OverflowException("{$a} + {$b} is beyond any figure this system computes exactly.");
        }

        return $sum;
    }

    /** A percent of an amount in centavos, half up: roundHalfUp(centavos × units, 10⁶). */
    public static function share(int $centavos, string|int $percent): int
    {
        return self::roundHalfUp(self::multiply($centavos, self::rateUnits($percent)), self::RATE_DENOMINATOR);
    }

    /**
     * A stored peso amount (a `decimal:2` string) in centavos. A missing or
     * unusable value is refused, never read as zero.
     */
    public static function centavos(string|int|float|null $pesos): int
    {
        $centavos = Money::toCentavos($pesos);

        if ($centavos === null) {
            throw new InvalidArgumentException('That is not a usable peso amount.');
        }

        return $centavos;
    }

    /**
     * A signed stored amount (a balance that may read "-12.50") in centavos.
     */
    public static function signedCentavos(string|int|float|null $pesos): int
    {
        $text = trim((string) $pesos);

        return str_starts_with($text, '-') ? -self::centavos(substr($text, 1)) : self::centavos($text);
    }

    /** Centavos as the API's two-decimal string ("1234.50", "-0.05"). */
    public static function pesos(int $centavos): string
    {
        $magnitude = $centavos < 0 ? self::negate($centavos) : $centavos;

        return ($centavos < 0 ? '-' : '').intdiv($magnitude, 100).'.'.str_pad((string) ($magnitude % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function negate(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new OverflowException('That figure is beyond any this system computes exactly.');
        }

        return -$value;
    }
}
