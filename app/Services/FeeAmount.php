<?php

namespace App\Services;

use App\Enums\TermUnit;
use App\Models\Fee;

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
 *
 * The term in months is the schedule engine's: a days term is its days over
 * {@see LoanTermSchedule::DAYS_PER_MONTH} (a 12-week term of 84 days is 2.8
 * months), and a months term, upon-maturity included, is its term.
 */
final class FeeAmount
{
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
     * A product custom fee or a stated deduction, as an array:
     * `{type, value|amount, per_month?, min_amount?, max_amount?}`.
     *
     * @param  array<string, mixed>  $fee
     * @param  array{0: int, 1: int}|null  $months
     */
    public static function forCustomFee(array $fee, float|int|string $principal, ?array $months): ?float
    {
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
     * items keep their `{name, amount, type, original_value}` shape.
     *
     * @param  array<string, mixed>  $fee
     * @return array{per_month?: bool, min_amount?: float|null, max_amount?: float|null}
     */
    public static function formulaFields(string $type, array $fee): array
    {
        if ($type !== 'formula') {
            return [];
        }

        return [
            'per_month' => (bool) ($fee['per_month'] ?? false),
            'min_amount' => self::nullableAmount($fee['min_amount'] ?? null),
            'max_amount' => self::nullableAmount($fee['max_amount'] ?? null),
        ];
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
