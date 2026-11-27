<?php

namespace App\Services;

use App\Enums\TermUnit;
use App\Models\Fee;
use App\Services\Fees\FeeExpression;
use App\Services\Fees\FeeExpressionException;
use App\Services\Fees\FeeFormulaInputs;
use App\Services\Fees\FeeFormulaResult;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The one place a fee's peso amount is worked out, for a catalog fee
 * ({@see Fee}, charged by {@see LoanReleaseFeeService}), a product custom fee
 * (`loan_products.custom_fees`) and a loan's stated deductions
 * ({@see LoanService::computeDeductions()}).
 *
 * - `fixed`: the peso figure, to the centavo.
 * - `percentage`: the rate as a percentage of the principal, to the centavo.
 *   Both keep the float arithmetic they always had, so no existing fee moves
 *   by a centavo.
 * - `formula` (decision 5e): principal × rate / 100, times the term in months
 *   when `per_month` is set, kept between `min_amount` and `max_amount` (each
 *   optional), rounded half up to the centavo. Worked in whole numbers
 *   (centavos, ten-thousandths of a percent, days), so a half centavo is
 *   exactly a half and always rounds up.
 * - `formula` written as an expression (`formula_expression`, Phase 4):
 *   {@see self::forExpression()}, exact rational maths, half up to the
 *   centavo, then the same bounds. `value` is 0 and `per_month` false on such
 *   a fee; with no expression the recipe above is unchanged.
 *
 * The term in months is the schedule engine's: a days term is its days over
 * {@see LoanTermSchedule::DAYS_PER_MONTH} (a 12-week term of 84 days is 2.8
 * months), and a months term, upon-maturity included, is its term.
 */
final class FeeAmount
{
    /**
     * The most a fee may come to: what the 12,2 money columns (a loan's
     * deductions totals, the bounds) can hold.
     */
    public const LARGEST_AMOUNT = '9999999999.99';

    /** The fee types a catalog fee or a product custom fee may have. */
    public const TYPES = ['fixed', 'percentage', 'formula'];

    /**
     * The term in months as a fraction `[numerator, denominator]`, or null when
     * the term is unknown.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function termMonths(mixed $term, TermUnit|string|null $termUnit): ?array
    {
        $term = (int) $term;

        if ($term <= 0) {
            return null;
        }

        $unit = $termUnit instanceof TermUnit ? $termUnit->value : ($termUnit ?: TermUnit::Months->value);

        return [LoanTermSchedule::termDays($term, $unit), LoanTermSchedule::DAYS_PER_MONTH];
    }

    /**
     * A fee's amount in pesos, or null when it can't be worked out: a
     * per-month formula on a loan whose term isn't known yet.
     *
     * @param  array{0: int, 1: int}|null  $months  {@see self::termMonths()}
     */
    public static function forRule(
        string $type,
        mixed $value,
        float|int|string $principal,
        ?array $months = null,
        bool $perMonth = false,
        mixed $minAmount = null,
        mixed $maxAmount = null,
    ): ?float {
        $rate = (float) $value;

        return match ($type) {
            'percentage' => round((float) $principal * $rate / 100, 2),
            'formula' => self::formula((float) $principal, $rate, $perMonth ? $months : [1, 1], $minAmount, $maxAmount),
            default => round($rate, 2),
        };
    }

    public static function forFee(Fee $fee, float|int|string $principal, ?array $months): ?float
    {
        return self::forRule((string) $fee->type, $fee->value, $principal, $months, (bool) $fee->per_month, $fee->min_amount, $fee->max_amount);
    }

    /**
     * An expression fee's amount on a loan, or null when an input the formula
     * uses isn't known yet (the form preview before its rate or dates).
     *
     * The exact result is cut to 6 decimals half up for `raw_amount`, rounded
     * half up to the centavo from the exact figure (never from the 6-decimal
     * one, so nothing is rounded twice), then kept between `$minAmount` and
     * `$maxAmount` when they're set. An amount above
     * {@see self::LARGEST_AMOUNT} after the bounds is refused.
     *
     * @throws FeeExpressionException DIVIDES_BY_ZERO, NEGATIVE or TOO_LARGE on these inputs
     */
    public static function forExpression(FeeExpression $expression, FeeFormulaInputs $inputs, mixed $minAmount = null, mixed $maxAmount = null): ?FeeFormulaResult
    {
        $raw = $expression->evaluate($inputs->values());

        if ($raw === null) {
            return null;
        }

        $rounded = $raw->toScale(2, RoundingMode::HalfUp);
        $amount = $rounded;
        $clamped = null;
        $min = self::decimalOrNull($minAmount);
        $max = self::decimalOrNull($maxAmount);

        // Compared as decimals, never as integers: a formula can work out far
        // beyond what an int holds before a maximum brings it back down.
        if ($min !== null && $amount->isLessThan($min)) {
            [$amount, $clamped] = [$min, 'min'];
        }

        if ($max !== null && $amount->isGreaterThan($max)) {
            [$amount, $clamped] = [$max, 'max'];
        }

        if ($amount->isGreaterThan(self::LARGEST_AMOUNT)) {
            throw FeeExpressionException::tooLarge();
        }

        return new FeeFormulaResult(
            raw: $raw,
            rawAmount: (string) $raw->toScale(6, RoundingMode::HalfUp),
            roundedAmount: (string) $rounded,
            amount: (string) $amount,
            clamped: $clamped,
        );
    }

    /**
     * A bound as a 2-place decimal, or null when it isn't set.
     */
    private static function decimalOrNull(mixed $amount): ?BigDecimal
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return null;
        }

        $written = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;

        return BigDecimal::of($written)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * A product custom fee or a stated deduction, as an array:
     * `{type, value|amount, per_month?, min_amount?, max_amount?}`.
     *
     * @param  array<string, mixed>  $fee
     * @param  array{0: int, 1: int}|null  $months
     */
    public static function forCustomFee(array $fee, float|int|string $principal, ?array $months): ?float
    {
        if (self::carriesExpression($fee)) {
            return self::forExpression(
                FeeExpression::parse((string) $fee['formula_expression']),
                FeeFormulaInputs::fromSnapshot($fee['formula_inputs']),
                $fee['min_amount'] ?? null,
                $fee['max_amount'] ?? null,
            )?->amountAsFloat();
        }

        return self::forRule(
            (string) ($fee['type'] ?? 'fixed'),
            $fee['value'] ?? $fee['amount'] ?? 0,
            $principal,
            $months,
            (bool) ($fee['per_month'] ?? false),
            $fee['min_amount'] ?? null,
            $fee['max_amount'] ?? null,
        );
    }

    /**
     * The formula fields a deduction item carries, so a recompute reads a
     * formula item exactly as it was charged. Empty for any other type, whose
     * items keep their `{name, amount, type, original_value}` shape. An
     * expression item also keeps its snapshot: `formula_expression`,
     * `formula_inputs` and `raw_amount`.
     *
     * @param  array<string, mixed>  $fee
     * @return array{per_month?: bool, min_amount?: float|null, max_amount?: float|null, formula_expression?: string, formula_inputs?: array<string, string|null>, raw_amount?: string|null}
     */
    public static function formulaFields(string $type, array $fee): array
    {
        if ($type !== 'formula') {
            return [];
        }

        $fields = [
            'per_month' => (bool) ($fee['per_month'] ?? false),
            'min_amount' => self::nullableAmount($fee['min_amount'] ?? null),
            'max_amount' => self::nullableAmount($fee['max_amount'] ?? null),
        ];

        if (! self::carriesExpression([...$fee, 'type' => $type])) {
            return $fields;
        }

        return [
            ...$fields,
            'formula_expression' => (string) $fee['formula_expression'],
            'formula_inputs' => $fee['formula_inputs'],
            'raw_amount' => isset($fee['raw_amount']) ? (string) $fee['raw_amount'] : null,
        ];
    }

    /**
     * Whether a deduction item was charged from an expression and carries its
     * snapshot (`formula_expression` and `formula_inputs`), so a recompute
     * reads it from what it was charged with, never from today's fee.
     *
     * @param  array<string, mixed>  $fee
     */
    private static function carriesExpression(array $fee): bool
    {
        return ($fee['type'] ?? null) === 'formula'
            && is_string($fee['formula_expression'] ?? null)
            && trim($fee['formula_expression']) !== ''
            && is_array($fee['formula_inputs'] ?? null);
    }

    /**
     * @param  array{0: int, 1: int}|null  $months
     */
    private static function formula(float $principal, float $rate, ?array $months, mixed $minAmount, mixed $maxAmount): ?float
    {
        if ($months === null) {
            return null;
        }

        [$monthsNumerator, $monthsDenominator] = $months;

        $principalCentavos = (int) round($principal * 100);
        $rateTenThousandths = (int) round($rate * 10000);

        // centavos = principal centavos × (rate ten-thousandths / 10,000) / 100 × months
        $numerator = $principalCentavos * $rateTenThousandths * $monthsNumerator;
        $denominator = 1_000_000 * $monthsDenominator;

        $centavos = is_int($numerator) && is_int(2 * $numerator + $denominator)
            ? intdiv(2 * $numerator + $denominator, 2 * $denominator)
            : (int) round($principal * $rate / 100 * $monthsNumerator / $monthsDenominator * 100);

        $min = self::centavosOrNull($minAmount);
        $max = self::centavosOrNull($maxAmount);

        if ($min !== null && $centavos < $min) {
            $centavos = $min;
        }

        if ($max !== null && $centavos > $max) {
            $centavos = $max;
        }

        return round($centavos / 100, 2);
    }

    private static function centavosOrNull(mixed $amount): ?int
    {
        return $amount === null || $amount === '' ? null : (int) round((float) $amount * 100);
    }

    private static function nullableAmount(mixed $amount): ?float
    {
        return $amount === null || $amount === '' ? null : round((float) $amount, 2);
    }
}
