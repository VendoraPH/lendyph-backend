<?php

namespace App\Services\Cda\Exports;

use App\Services\Accounting\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A report value as an export shows it: money as ₱ with two decimals
 * (₱1,500.00), percents with a % sign, dates as "Dec 31, 2026", booleans as
 * Yes or No, a select's option label, and "—" for nothing. Text is returned
 * as is; the Blade view and the Excel writer escape it.
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

    private static function money(mixed $value): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        try {
            return Money::format((int) Money::toCentavos(is_float($value) ? (string) $value : $value));
        } catch (Throwable) {
            return (string) $value;
        }
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

    private static function date(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return $value;
        }

        return Carbon::parse(substr($value, 0, 10))->format('M j, Y');
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

    /** @param  array<string, mixed>  $value */
    private static function compliance(array $value): string
    {
        $rating = match ($value['rating'] ?? $value['value'] ?? null) {
            'compliant' => 'Compliant',
            'partial' => 'Partly compliant',
            'non_compliant' => 'Not compliant',
            'not_applicable' => 'Not applicable',
            null => self::EMPTY,
            default => (string) ($value['rating'] ?? $value['value']),
        };

        return filled($value['remarks'] ?? null) ? $rating.' ('.$value['remarks'].')' : $rating;
    }
}
