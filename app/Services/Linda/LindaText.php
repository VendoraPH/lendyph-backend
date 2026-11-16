<?php

namespace App\Services\Linda;

/**
 * The one cleanup every Linda question goes through before it is matched:
 * trimmed, lower case, single spaces, and no punctuation except the ₱ sign.
 *
 * A dash beside a number reads as "to" ("Nov 1-5"), ISO dates
 * (2026-10-01) stay whole, a comma inside a number is dropped (₱1,500.00)
 * and a decimal point inside one is kept.
 */
final class LindaText
{
    private const DATE_DASH = "\u{E000}";

    private const DECIMAL_POINT = "\u{E001}";

    public static function clean(string $text): string
    {
        // Two private-use characters hold an ISO date's dashes and a number's
        // decimal point while the punctuation around them is stripped.
        $text = str_replace([self::DATE_DASH, self::DECIMAL_POINT], ' ', mb_strtolower($text, 'UTF-8'));

        // Apostrophes join a word ("what's" → "whats").
        $text = preg_replace("/(?<=\\pL)['’‘`](?=\\pL)/u", '', $text) ?? '';

        $text = preg_replace('/\\b(\\d{4})-(\\d{2})-(\\d{2})\\b/', '$1'.self::DATE_DASH.'$2'.self::DATE_DASH.'$3', $text) ?? '';

        // A dash beside a number is a range: "1-5", "nov 1 – nov 5".
        $text = preg_replace('/(?<=\\d)\\s*[-–—]\\s*|\\s*[-–—]\\s*(?=\\d)/u', ' to ', $text) ?? '';

        $text = preg_replace('/(?<=\\d),(?=\\d{3})/', '', $text) ?? '';
        $text = preg_replace('/(?<=\\d)\\.(?=\\d)/', self::DECIMAL_POINT, $text) ?? '';

        // Everything else that isn't a letter, a digit or ₱ is a space.
        $text = preg_replace('/[^\\pL\\pN₱'.self::DATE_DASH.self::DECIMAL_POINT.']+/u', ' ', $text) ?? '';

        $text = str_replace([self::DATE_DASH, self::DECIMAL_POINT], ['-', '.'], $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * The words of a cleaned question.
     *
     * @return list<string>
     */
    public static function words(string $clean): array
    {
        return $clean === '' ? [] : explode(' ', $clean);
    }
}
