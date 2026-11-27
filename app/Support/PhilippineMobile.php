<?php

namespace App\Support;

/**
 * Philippine mobile numbers in one stored form, 639XXXXXXXXX.
 *
 * Accepts 09XXXXXXXXX, 9XXXXXXXXX, 639XXXXXXXXX and +639XXXXXXXXX, with
 * spaces, dashes and brackets ignored. Anything else, a landline included,
 * is not a mobile number and normalises to null, so nothing is ever sent to
 * a number that was guessed.
 */
final class PhilippineMobile
{
    private const NORMALISED = '/^639\d{9}$/';

    public static function normalise(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $compact = preg_replace('/[\s\-()]/', '', $number);

        if (str_starts_with($compact, '+63')) {
            $compact = substr($compact, 1);
        }

        $normalised = match (true) {
            (bool) preg_match('/^09\d{9}$/', $compact) => '63'.substr($compact, 1),
            (bool) preg_match('/^9\d{9}$/', $compact) => '63'.$compact,
            default => $compact,
        };

        return preg_match(self::NORMALISED, $normalised) === 1 ? $normalised : null;
    }

    /**
     * The number with its middle hidden, for logs and screens: 63917*****67.
     *
     * A number that doesn't normalise keeps only its first three and last two
     * digits, and one shorter than seven digits is hidden entirely.
     */
    public static function mask(string $number): string
    {
        $normalised = self::normalise($number);

        if ($normalised !== null) {
            return substr($normalised, 0, 5).str_repeat('*', 5).substr($normalised, -2);
        }

        $digits = preg_replace('/\D/', '', $number);
        $length = strlen($digits);

        if ($length < 7) {
            return str_repeat('*', $length);
        }

        return substr($digits, 0, 3).str_repeat('*', $length - 5).substr($digits, -2);
    }
}
