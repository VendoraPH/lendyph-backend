<?php

namespace App\Services\Cda\Reports;

use InvalidArgumentException;

/**
 * How a report field's value is stored and sent (design §0, §2), by field
 * type: money and percent as 2-decimal strings, a number as an int (or a
 * string with its `decimals`), a date as YYYY-MM-DD, a boolean, a select or
 * compliance option's value, text, and a table as a list of rows holding
 * only its columns. Used for what staff type (fromInput, with a message in
 * words when it doesn't fit), what a source gives (fromSource) and what a
 * calculation gives (fromRational).
 */
final class FieldValue
{
    public const COMPLIANCE = ['compliant', 'partial', 'non_compliant', 'not_applicable'];

    public const TEXT_MAX = 255;

    public const TEXTAREA_MAX = 5000;

    public const TABLE_ROWS_MAX = 500;

    /**
     * A typed value, checked and in its stored form.
     *
     * @throws InvalidArgumentException with the problem in words
     */
    public static function fromInput(array $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $label = (string) ($field['label'] ?? $field['key']);

        return match ($field['type']) {
            'number' => self::number($value, (int) ($field['decimals'] ?? 0), $label),
            'money' => self::decimal($value, 2, $label, 'an amount in pesos with up to 2 decimals'),
            'percent' => self::decimal($value, 2, $label, 'a percent with up to 2 decimals'),
            'date' => self::date($value, $label),
            'boolean' => is_bool($value) ? $value : throw new InvalidArgumentException("{$label} must be yes or no."),
            'select' => self::option($value, array_column((array) ($field['options'] ?? []), 'value'), $label),
            'compliance' => self::option($value, self::COMPLIANCE, $label),
            'textarea' => self::text($value, self::TEXTAREA_MAX, $label),
            'table' => self::rows($value, (array) ($field['columns'] ?? []), $label),
            default => self::text($value, self::TEXT_MAX, $label),
        };
    }

    /** A source's value in the field's stored form; a value that doesn't fit is kept as given. */
    public static function fromSource(array $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            return match ($field['type']) {
                'number' => is_bool($value) ? $value : self::number($value, (int) ($field['decimals'] ?? 0), ''),
                'money', 'percent' => is_numeric($value) ? Rational::of(is_float($value) ? (string) $value : $value)->toFixed(2) : $value,
                'table' => is_array($value) ? self::project($value, (array) ($field['columns'] ?? [])) : $value,
                'text', 'textarea' => is_scalar($value) && ! is_bool($value) ? (string) $value : $value,
                default => $value,
            };
        } catch (InvalidArgumentException) {
            return $value;
        }
    }

    /** A calculation's result in the field's stored form. */
    public static function fromRational(array $field, ?Rational $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $decimals = (int) ($field['decimals'] ?? 0);

        return match ($field['type']) {
            'number' => $decimals === 0 ? $value->toInt() : $value->toFixed($decimals),
            default => $value->toFixed(2),
        };
    }

    /** Whether a stored value counts as filled in. */
    public static function filled(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }

    private static function number(mixed $value, int $decimals, string $label): int|string
    {
        if (! Rational::isNumeric($value) || is_bool($value)) {
            throw new InvalidArgumentException("{$label} must be a number.");
        }

        $number = Rational::of($value);

        if (! $number->round($decimals)->equals($number)) {
            throw new InvalidArgumentException($decimals === 0 ? "{$label} must be a whole number." : "{$label} takes up to {$decimals} decimals.");
        }

        return $decimals === 0 ? $number->toInt() : $number->toFixed($decimals);
    }

    private static function decimal(mixed $value, int $decimals, string $label, string $what): string
    {
        if (! Rational::isNumeric($value) || is_bool($value)) {
            throw new InvalidArgumentException("{$label} must be {$what}.");
        }

        $number = Rational::of($value);

        if (! $number->round($decimals)->equals($number)) {
            throw new InvalidArgumentException("{$label} must be {$what}.");
        }

        return $number->toFixed($decimals);
    }

    private static function date(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("{$label} must be a date (YYYY-MM-DD).");
        }

        return $value;
    }

    /** @param  list<mixed>  $options */
    private static function option(mixed $value, array $options, string $label): string
    {
        if (! is_string($value) || ! in_array($value, $options, true)) {
            throw new InvalidArgumentException("{$label} must be one of its options.");
        }

        return $value;
    }

    private static function text(mixed $value, int $max, string $label): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("{$label} must be text.");
        }

        $text = trim((string) $value);

        if (mb_strlen($text) > $max) {
            throw new InvalidArgumentException("{$label} takes up to {$max} characters.");
        }

        return $text;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value, array $columns, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::TABLE_ROWS_MAX) {
            throw new InvalidArgumentException("{$label} is a list of up to ".self::TABLE_ROWS_MAX.' rows.');
        }

        $keys = array_column($columns, 'key');
        $rows = [];

        foreach ($value as $n => $row) {
            if (! is_array($row) || array_is_list($row) && $row !== [] || array_diff(array_keys($row), $keys) !== []) {
                throw new InvalidArgumentException("{$label}, row ".($n + 1).': each row holds only the columns '.implode(', ', $keys).'.');
            }

            $clean = [];

            foreach ($columns as $column) {
                $cell = $row[$column['key']] ?? null;
                $cellField = ['key' => $column['key'], 'label' => "{$label}, row ".($n + 1).', '.($column['label'] ?? $column['key']), 'type' => match ($column['format'] ?? 'text') {
                    'money' => 'money', 'percent' => 'percent', 'date' => 'date', 'number' => 'number', default => 'text',
                }, 'decimals' => 2];
                $clean[$column['key']] = self::fromInput($cellField, $cell);
            }

            $rows[] = $clean;
        }

        return $rows;
    }

    /**
     * A source's table rows, keeping only the template's columns (an
     * empty column list keeps every cell).
     *
     * @return list<mixed>
     */
    private static function project(array $rows, array $columns): array
    {
        $keys = array_column($columns, 'key');

        return array_values(array_map(
            fn (mixed $row) => is_array($row) && $keys !== [] ? array_combine($keys, array_map(fn (string $key) => $row[$key] ?? null, $keys)) : $row,
            $rows,
        ));
    }
}
