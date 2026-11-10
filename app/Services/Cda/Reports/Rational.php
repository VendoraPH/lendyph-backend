<?php

namespace App\Services\Cda\Reports;

use App\Services\Savings\SavingsMath;
use InvalidArgumentException;
use OverflowException;

/**
 * An exact number for CDA calculations (design §2): an integer numerator over
 * a positive integer denominator, kept in lowest terms. Money read as
 * "1500.50" is 150050/100, so sums and comparisons are exact to the centavo
 * and a ratio is never a float. Rounding happens only when a figure is
 * written out (toFixed), half up (away from zero), as everywhere else.
 *
 * A number is read with at most 18 significant digits (else an
 * InvalidArgumentException, a 422 in words); a result too large for a 64-bit
 * integer throws OverflowException, which the engine reports as an issue
 * instead of a wrong figure. Never a TypeError.
 */
final class Rational
{
    /** The most significant digits a number may have, so it never overflows. */
    public const MAX_DIGITS = 18;

    private function __construct(
        public readonly int $numerator,
        public readonly int $denominator,
    ) {}

    /** From an int, a finite float written as a decimal, or a decimal string ("1500.50", "-3"). */
    public static function of(int|float|string $value): self
    {
        if (is_int($value)) {
            return new self($value, 1);
        }

        $text = is_float($value) ? self::floatText($value) : trim($value);

        if (! self::parse($text, $m)) {
            throw new InvalidArgumentException("\"{$text}\" is not a number of up to ".self::MAX_DIGITS.' digits.');
        }

        $decimals = $m[3] ?? '';
        $denominator = 10 ** strlen($decimals);
        $numerator = self::checked(self::checked((int) $m[2] * $denominator) + (int) ($decimals === '' ? 0 : $decimals));

        return self::make($m[1] === '-' ? -$numerator : $numerator, $denominator);
    }

    /** Whether the value reads as a number this class takes. */
    public static function isNumeric(mixed $value): bool
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return true;
        }

        return is_string($value) && self::parse(trim($value), $m);
    }

    /**
     * A decimal of up to 18 significant digits (at most 9 after the point),
     * so its numerator always fits a 64-bit integer; leading zeros of the
     * whole part don't count.
     *
     * @param  array<int, string>|null  $m
     */
    private static function parse(string $text, ?array &$m): bool
    {
        if (preg_match('/^(-)?(\d{1,30})(?:\.(\d{1,9}))?$/', $text, $m) !== 1) {
            return false;
        }

        $whole = ltrim($m[2], '0');

        return strlen($whole) + strlen($m[3] ?? '') <= self::MAX_DIGITS;
    }

    public static function fromCentavos(int $centavos): self
    {
        return self::make($centavos, 100);
    }

    public function add(self $other): self
    {
        return self::make(
            self::checked($this->numerator * $other->denominator + $other->numerator * $this->denominator),
            self::checked($this->denominator * $other->denominator),
        );
    }

    public function subtract(self $other): self
    {
        return $this->add(new self(-$other->numerator, $other->denominator));
    }

    public function multiply(self $other): self
    {
        return self::make(self::checked($this->numerator * $other->numerator), self::checked($this->denominator * $other->denominator));
    }

    /** Null when the divisor is zero. */
    public function divide(self $other): ?self
    {
        if ($other->numerator === 0) {
            return null;
        }

        $sign = $other->numerator < 0 ? -1 : 1;

        return self::make(
            self::checked($this->numerator * $other->denominator * $sign),
            self::checked($this->denominator * abs($other->numerator)),
        );
    }

    public function isZero(): bool
    {
        return $this->numerator === 0;
    }

    /** -1, 0 or 1. */
    public function compare(self $other): int
    {
        return self::checked($this->numerator * $other->denominator) <=> self::checked($other->numerator * $this->denominator);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function abs(): self
    {
        return new self(abs($this->numerator), $this->denominator);
    }

    /** Rounded half up (away from zero) to the given decimals. */
    public function round(int $decimals): self
    {
        $scale = 10 ** $decimals;

        return self::make(SavingsMath::roundHalfUp(self::checked($this->numerator * $scale), $this->denominator), $scale);
    }

    /** "1500.50": rounded half up, always with the given decimals. */
    public function toFixed(int $decimals): string
    {
        $scaled = $this->round($decimals);
        $units = intdiv($scaled->numerator * (10 ** $decimals), $scaled->denominator);
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        if ($decimals === 0) {
            return $sign.$units;
        }

        $scale = 10 ** $decimals;

        return $sign.intdiv($units, $scale).'.'.str_pad((string) ($units % $scale), $decimals, '0', STR_PAD_LEFT);
    }

    /** Whole centavos, rounded half up. */
    public function toCentavos(): int
    {
        return SavingsMath::roundHalfUp(self::checked($this->numerator * 100), $this->denominator);
    }

    /** An int when the rounded value is whole, for counts. */
    public function toInt(): int
    {
        return SavingsMath::roundHalfUp($this->numerator, $this->denominator);
    }

    private static function make(int $numerator, int $denominator): self
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('A denominator is positive.');
        }

        $gcd = self::gcd(abs($numerator), $denominator);

        return new self(intdiv($numerator, $gcd), intdiv($denominator, $gcd));
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }

    /** An integer product or sum, or an OverflowException when PHP turned it into a float. */
    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw new OverflowException('The figure is too large to calculate exactly.');
        }

        return $value;
    }

    private static function floatText(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('A number must be finite.');
        }

        $whole = strlen(number_format(floor(abs($value)), 0, '.', ''));
        $text = number_format($value, max(0, min(9, self::MAX_DIGITS - $whole)), '.', '');

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }
}
