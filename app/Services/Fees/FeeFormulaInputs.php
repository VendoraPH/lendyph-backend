<?php

namespace App\Services\Fees;

use App\Models\Loan;
use App\Services\FeeAmount;
use App\Services\LoanReleaseFeeService;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

/**
 * What a fee formula's variables stand for on one loan, saved or not.
 *
 * - Principal Amount and Loan Amount: the loan's `principal_amount` (this
 *   codebase has one amount).
 * - Interest Rate: the loan's `interest_rate` as stored (3 means 3%), per its
 *   own `interest_rate_frequency`.
 * - Loan Term: months, exact; a days term is its days over 30, as the recipe
 *   formula counts it ({@see FeeAmount::termMonths()}).
 * - Term in Days: calendar days from the start date to the maturity date, as
 *   the fee conditions count them ({@see LoanReleaseFeeService::termDays()}).
 *
 * A figure that isn't known yet (an unsaved form with no rate or dates) is
 * null, and a formula that uses it charges nothing until it is.
 * {@see self::snapshot()} is what each charged item stores, as strings.
 */
final readonly class FeeFormulaInputs
{
    public function __construct(
        public ?string $principalAmount,
        public ?string $interestRate,
        public ?string $interestRateFrequency,
        public ?BigRational $loanTerm,
        public ?int $termInDays,
    ) {}

    public static function fromLoan(Loan $loan): self
    {
        $months = FeeAmount::termMonths($loan->term, $loan->term_unit);
        $frequency = $loan->interest_rate_frequency;

        return new self(
            self::decimalOrNull($loan->principal_amount, 2),
            self::decimalOrNull($loan->interest_rate, 4),
            $frequency === null ? null : (is_string($frequency) ? $frequency : $frequency->value),
            $months === null ? null : BigRational::ofFraction($months[0], $months[1]),
            LoanReleaseFeeService::termDays($loan),
        );
    }

    /**
     * The inputs a charged item stored ({@see self::snapshot()}), for a
     * recompute that reads the item exactly as it was charged.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $term = $snapshot['loan_term'] ?? null;
        $days = $snapshot['term_in_days'] ?? null;

        return new self(
            self::decimalOrNull($snapshot['principal_amount'] ?? null, 2),
            self::decimalOrNull($snapshot['interest_rate'] ?? null, 4),
            isset($snapshot['interest_rate_frequency']) ? (string) $snapshot['interest_rate_frequency'] : null,
            is_numeric($term) ? BigRational::of((string) $term) : null,
            is_numeric($days) ? (int) $days : null,
        );
    }

    /**
     * Each variable's exact value, keyed as {@see FeeExpression::VARIABLES}.
     *
     * @return array<string, BigRational|null>
     */
    public function values(): array
    {
        $principal = $this->principalAmount === null ? null : BigRational::of($this->principalAmount);

        return [
            'principal_amount' => $principal,
            'loan_amount' => $principal,
            'interest_rate' => $this->interestRate === null ? null : BigRational::of($this->interestRate),
            'loan_term' => $this->loanTerm,
            'term_in_days' => $this->termInDays === null ? null : BigRational::of($this->termInDays),
        ];
    }

    /**
     * The inputs as the charged item stores them: money to the centavo, the
     * rate as stored, a days term's months with their decimals ("1.5").
     *
     * @return array{principal_amount: string|null, loan_amount: string|null, interest_rate: string|null, interest_rate_frequency: string|null, loan_term: string|null, term_in_days: string|null}
     */
    public function snapshot(): array
    {
        return [
            'principal_amount' => $this->principalAmount,
            'loan_amount' => $this->principalAmount,
            'interest_rate' => $this->interestRate,
            'interest_rate_frequency' => $this->interestRateFrequency,
            'loan_term' => $this->loanTerm === null ? null : self::plainDecimal($this->loanTerm),
            'term_in_days' => $this->termInDays === null ? null : (string) $this->termInDays,
        ];
    }

    /**
     * A figure as a decimal string at `$scale` places, or null when it isn't
     * known. The stored columns already hold that many places.
     */
    private static function decimalOrNull(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $decimal = is_float($value) ? BigDecimal::of(number_format($value, $scale + 2, '.', '')) : BigDecimal::of((string) $value);

        return (string) $decimal->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Exact when it ends ("12", "1.5"); a repeating fraction (100 days is
     * 3.333… months) is written to 6 places, as `raw_amount` is. The
     * evaluation itself always uses the exact fraction.
     */
    private static function plainDecimal(BigRational $value): string
    {
        try {
            return (string) $value->toBigDecimal()->strippedOfTrailingZeros();
        } catch (RoundingNecessaryException) {
            return (string) $value->toScale(6, RoundingMode::HalfUp)->strippedOfTrailingZeros();
        }
    }
}
