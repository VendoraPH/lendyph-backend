<?php

namespace App\Services\Cda\Reports;

use Closure;
use InvalidArgumentException;

/**
 * Evaluates a template's structured calculation (design §2): `{op, args}`,
 * never parsed text. An arg is a number, a field reference or a nested
 * calculation. Numbers are exact (Rational), so money stays in whole
 * centavos until it's written out.
 *
 * - add, mul: any number of args; sub: a − b.
 * - div: a ÷ b; ratio: a ÷ b × 100, a percent. Both give null when b is 0.
 * - sum_column: the sum of a table field's column (empty cells skipped).
 * - coalesce: the first arg that isn't null.
 * - round_half_up: a rounded to n decimals, half away from zero.
 *
 * A null operand makes the result null (except in coalesce), so a missing
 * figure never reads as zero.
 */
final class CalcEngine
{
    /** @param  Closure(string): mixed  $valueOf  a field's stored value by reference */
    public function evaluate(array $calc, Closure $valueOf): ?Rational
    {
        $op = (string) ($calc['op'] ?? '');
        $args = array_values((array) ($calc['args'] ?? []));

        return match ($op) {
            'add' => $this->fold($args, $valueOf, fn (Rational $a, Rational $b) => $a->add($b)),
            'mul' => $this->fold($args, $valueOf, fn (Rational $a, Rational $b) => $a->multiply($b)),
            'sub' => $this->pair($args, $valueOf, fn (Rational $a, Rational $b) => $a->subtract($b)),
            'div' => $this->pair($args, $valueOf, fn (Rational $a, Rational $b) => $a->divide($b)),
            'ratio' => $this->pair($args, $valueOf, fn (Rational $a, Rational $b) => $a->divide($b)?->multiply(Rational::of(100))),
            'coalesce' => $this->coalesce($args, $valueOf),
            'sum_column' => $this->sumColumn($args, $valueOf),
            'round_half_up' => $this->operand($args[0] ?? null, $valueOf)?->round((int) ($args[1] ?? 0)),
            default => throw new InvalidArgumentException("Unknown calculation \"{$op}\"."),
        };
    }

    /** A value as a number: null when empty, an InvalidArgumentException when it isn't a number. */
    public static function number(mixed $value): ?Rational
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return Rational::of($value ? 1 : 0);
        }

        if (! Rational::isNumeric($value)) {
            throw new InvalidArgumentException('"'.(is_scalar($value) ? $value : gettype($value)).'" is not a number.');
        }

        return Rational::of($value);
    }

    private function operand(mixed $arg, Closure $valueOf): ?Rational
    {
        if (is_int($arg) || is_float($arg)) {
            return Rational::of($arg);
        }

        if (is_string($arg)) {
            return self::number($valueOf($arg));
        }

        if (is_array($arg)) {
            return $this->evaluate($arg, $valueOf);
        }

        return null;
    }

    /** @param  Closure(Rational, Rational): ?Rational  $step */
    private function fold(array $args, Closure $valueOf, Closure $step): ?Rational
    {
        $result = null;

        foreach ($args as $arg) {
            $value = $this->operand($arg, $valueOf);

            if ($value === null) {
                return null;
            }

            $result = $result === null ? $value : $step($result, $value);
        }

        return $result;
    }

    /** @param  Closure(Rational, Rational): ?Rational  $step */
    private function pair(array $args, Closure $valueOf, Closure $step): ?Rational
    {
        $a = $this->operand($args[0] ?? null, $valueOf);
        $b = $this->operand($args[1] ?? null, $valueOf);

        return $a === null || $b === null ? null : $step($a, $b);
    }

    private function coalesce(array $args, Closure $valueOf): ?Rational
    {
        foreach ($args as $arg) {
            $value = $this->operand($arg, $valueOf);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function sumColumn(array $args, Closure $valueOf): ?Rational
    {
        $rows = is_string($args[0] ?? null) ? $valueOf($args[0]) : null;

        if (! is_array($rows)) {
            return null;
        }

        $total = Rational::of(0);

        foreach ($rows as $row) {
            $cell = self::number(is_array($row) ? ($row[$args[1]] ?? null) : null);

            if ($cell !== null) {
                $total = $total->add($cell);
            }
        }

        return $total;
    }
}
