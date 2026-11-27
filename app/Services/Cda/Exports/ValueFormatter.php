<?php

namespace App\Services\Cda\Exports;

use App\Services\Accounting\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A report value as an export shows it: money as ₱ with two decimals
 * (₱1,500.00), percents with a % sign, dates as "Dec 31, 2026", booleans as
 * Yes or No, a select's option label, and "—" for nothing. Negative money
 * reads in parentheses, "(₱400.00)". Anything that can't be read as its type
 * is shown as written, never an error. Text is returned as is; the Blade view
 * and the Excel writers escape it.
 */
final class ValueFormatter
{
    public const EMPTY = '—';

    /** @param  array<string, mixed>  $field  the template's field or column */
    public static function display(mixed $value, string $type, array $field = []): string
    {
        if ($value === null || $value === '' || $value === []) {
            return self::EMPTY;
        }

        if ($type === 'compliance' && is_array($value)) {
            return self::compliance($value);
        }

        if (is_array($value)) {
            return array_is_list($value) ? count($value).' entries' : count($value).' fields';
        }

        return match ($type) {
            'money' => self::money($value),
            'percent' => is_numeric($value) ? self::decimalText((string) $value).'%' : (string) $value,
            'number' => is_numeric($value) ? self::number((string) $value, (int) ($field['decimals'] ?? 0)) : (string) $value,
            'boolean' => self::truthy($value) ? 'Yes' : 'No',
            'date' => self::date((string) $value),
            'select' => self::option($value, (array) ($field['options'] ?? [])),
            default => is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value,
        };
    }

    /**
     * ₱ with two decimals; a negative amount in parentheses, "(₱400.00)", as
     * the accounting screens show it. The sign is read apart from the digits,
     * so the amount itself is parsed as a positive figure.
     */
    private static function money(mixed $value): string
    {
        $text = is_float($value) ? (string) $value : trim((string) $value);

        if (! is_numeric($text) || preg_match('/^[+-]?\d+(\.\d+)?$/', $text) !== 1) {
            return (string) $value;
        }

        try {
            $centavos = Money::toCentavos(ltrim($text, '+-'));
        } catch (Throwable) {
            return (string) $value;
        }

        if ($centavos === null) {
            return (string) $value;
        }

        $formatted = Money::format($centavos);

        return str_starts_with($text, '-') && $centavos > 0 ? '('.$formatted.')' : $formatted;
    }

    /** Thousands separators, the decimals kept as given (never rounded here). */
    private static function number(string $value, int $decimals): string
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
        $whole = number_format((int) $whole, 0, '.', ',');
        $fraction = $decimals > 0 ? str_pad($fraction, $decimals, '0') : $fraction;

        return ($negative ? '-' : '').$whole.($fraction === '' ? '' : '.'.$fraction);
    }

    private static function decimalText(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }

    /** "Dec 31, 2026"; anything that isn't a real date stays as written. */
    private static function date(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }

        try {
            return Carbon::parse(substr($value, 0, 10))->format('M j, Y');
        } catch (Throwable) {
            return $value;
        }
    }

    /** @param  array<int, mixed>  $options */
    private static function option(mixed $value, array $options): string
    {
        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $value) {
                return (string) ($option['label'] ?? $value);
            }
        }

        return (string) $value;
    }

    /**
     * The rating in words, with its remarks. A rating or remarks that aren't
     * plain text are shown as their raw JSON, never an error.
     *
     * @param  array<string, mixed>  $value
     */
    private static function compliance(array $value): string
    {
        $raw = $value['rating'] ?? $value['value'] ?? null;

        $rating = match (true) {
            $raw === null => self::EMPTY,
            ! is_scalar($raw) => self::raw($raw),
            default => match ($raw) {
                'compliant' => 'Compliant',
                'partial' => 'Partly compliant',
                'non_compliant' => 'Not compliant',
                'not_applicable' => 'Not applicable',
                default => (string) $raw,
            },
        };

        $remarks = $value['remarks'] ?? null;

        if ($remarks === null || $remarks === '' || $remarks === []) {
            return $rating;
        }

        return $rating.' ('.(is_scalar($remarks) ? (string) $remarks : self::raw($remarks)).')';
    }

    private static function raw(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
