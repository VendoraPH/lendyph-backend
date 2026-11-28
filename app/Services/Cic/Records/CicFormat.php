<?php

namespace App\Services\Cic\Records;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Normalizer;

/**
 * CSDF 2016 value formatting, the server's only rules for it:
 *
 * - dates are DDMMYYYY;
 * - amounts are whole pesos as integer strings, rounded half away from zero
 *   (exact decimals, never floats), with no separators;
 * - text is trimmed with single spaces, and the delimiter, tabs and line
 *   breaks become spaces, so a value can never shift a position.
 *
 * Nothing is ever cut to fit: an over-length value is kept whole and the
 * validator reports it.
 */
final class CicFormat
{
    public static function date(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return $date->format('dmY');
    }

    /** Whole pesos, half away from zero: "1500.50" gives "1501", "-0.4" gives "0". */
    public static function amount(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $rounded = BigDecimal::of(is_float($value) ? number_format($value, 2, '.', '') : (string) $value)
            ->toScale(0, RoundingMode::HalfUp);

        return $rounded->isZero() ? '0' : (string) $rounded;
    }

    public static function text(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) preg_replace('/\s{2,}/u', ' ', (string) preg_replace('/[|\r\n\t]+/u', ' ', $value)));
    }

    /** A person's name parts as one line, empty parts dropped. */
    public static function name(?string ...$parts): string
    {
        return self::text(implode(' ', array_filter(array_map(fn (?string $part) => self::text($part), $parts), fn (string $part) => $part !== '')));
    }

    /**
     * A record's values with the empty positions dropped: a missing key is an
     * empty position when the file is written.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    public static function compact(array $values): array
    {
        return array_filter($values, fn (?string $value) => $value !== null && $value !== '');
    }

    /**
     * A key folded as the database's utf8mb4_unicode_ci unique indexes compare
     * it: compatibility-decomposed (NFKD), combining marks stripped, lower
     * case, so "PEÑA-01" and "pena-01" are one number (delta re-review).
     * `Normalizer` is intl's, or symfony/polyfill-intl-normalizer's where
     * intl is missing. Other characters ("BC/07-2024") are kept.
     */
    public static function fold(string $key): string
    {
        $decomposed = Normalizer::normalize($key, Normalizer::FORM_KD);

        // normalize() refuses bytes that aren't UTF-8; such a key is compared
        // as it is (the validator refuses it on the record anyway).
        return mb_strtolower($decomposed === false ? $key : (string) preg_replace('/\p{Mn}+/u', '', $decomposed));
    }

    /** A DDMMYYYY string as a sortable YYYYMMDD integer, or null when it isn't a real date. */
    public static function dateKey(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $parts)) {
            return null;
        }

        [, $day, $month, $year] = $parts;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return (int) ($year.$month.$day);
    }
}
