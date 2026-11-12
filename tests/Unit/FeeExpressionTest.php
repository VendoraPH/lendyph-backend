<?php

namespace Tests\Unit;

use App\Services\FeeAmount;
use App\Services\Fees\FeeExpression;
use App\Services\Fees\FeeExpressionException;
use App\Services\Fees\FeeFormulaInputs;
use Brick\Math\BigRational;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A formula fee written as an expression: the grammar, its refusals in words,
 * exact rational evaluation, and the rounding and bounds FeeAmount applies.
 *
 * No database and no application boot: this is pure arithmetic.
 */
class FeeExpressionTest extends TestCase
{
    private const VARIABLE_LIST = 'Use a variable name from the list: Principal Amount, Loan Amount, Interest Rate, Loan Term or Term in Days.';

    private const STRAY = 'Use only numbers, the variables, + − × ÷ % and brackets.';

    private const TOO_LONG = 'The formula is too long (at most 255 characters, 64 parts and 10 brackets deep).';

    private function inputs(string $principal = '100000.00', ?string $rate = '3.0000', string $frequency = 'monthly', ?BigRational $months = null, ?int $days = 365): FeeFormulaInputs
    {
        return new FeeFormulaInputs($principal, $rate, $frequency, $months ?? BigRational::of(12), $days);
    }

    private function refusal(string $source): string
    {
        try {
            FeeExpression::parse($source);
        } catch (FeeExpressionException $e) {
            return $e->getMessage();
        }

        $this->fail("'{$source}' was accepted.");
    }

    public function test_the_contract_example_gives_its_raw_and_rounded_amount(): void
    {
        $result = FeeAmount::forExpression(FeeExpression::parse('(Principal Amount * 2.5%) / 365'), $this->inputs());

        $this->assertSame('6.849315', $result->rawAmount);
        $this->assertSame('6.85', $result->roundedAmount);
        $this->assertSame('6.85', $result->amount);
        $this->assertSame(6.85, $result->amountAsFloat());
        $this->assertNull($result->clamped);
    }

    public function test_the_owners_formula_reads_left_to_right_in_ascii_and_unicode(): void
    {
        $inputs = $this->inputs('10000.00', months: BigRational::of(1), days: 30);

        foreach (['Loan Amount / Term in Days * 3%', 'Loan Amount ÷ Term in Days × 3%', 'loan   AMOUNT÷term in days×3%'] as $source) {
            $this->assertSame('10.00', FeeAmount::forExpression(FeeExpression::parse($source), $inputs)->amount, $source);
        }

        $this->assertSame('9.00', FeeAmount::forExpression(FeeExpression::parse('Loan Amount ÷ Term in Days × 3% − 1'), $inputs)->amount);
        $this->assertSame('9.00', FeeAmount::forExpression(FeeExpression::parse('−1 + Loan Amount / Term in Days * 3%'), $inputs)->amount);
    }

    public function test_precedence_brackets_unary_minus_and_percent(): void
    {
        $inputs = $this->inputs('1000.00');

        $this->assertSame('1070.00', FeeAmount::forExpression(FeeExpression::parse('Principal Amount + 2 * 35'), $inputs)->amount);
        $this->assertSame('72100.00', FeeAmount::forExpression(FeeExpression::parse('(Principal Amount + 2) * 72 - 44'), $inputs)->amount);
        $this->assertSame('1005.00', FeeAmount::forExpression(FeeExpression::parse('Principal Amount - -5'), $inputs)->amount);
        // Interest Rate% is the rate over 100: 3% of ₱1,000.00 for 12 months.
        $this->assertSame('360.00', FeeAmount::forExpression(FeeExpression::parse('Principal Amount × Interest Rate% × Loan Term'), $inputs)->amount);
        $this->assertSame('30.00', FeeAmount::forExpression(FeeExpression::parse('Principal Amount × (Interest Rate)%'), $inputs)->amount);
    }

    public function test_a_half_centavo_rounds_up_and_the_bounds_clamp(): void
    {
        // 0.1% of ₱10,005.00 is exactly ₱10.005.
        $half = FeeAmount::forExpression(FeeExpression::parse('Principal Amount * 0.1%'), $this->inputs('10005.00'));
        $this->assertSame('10.005000', $half->rawAmount);
        $this->assertSame('10.01', $half->amount);

        $low = FeeAmount::forExpression(FeeExpression::parse('Principal Amount * 0.1%'), $this->inputs('10005.00'), '50.00', '80.00');
        $this->assertSame('10.01', $low->roundedAmount);
        $this->assertSame('50.00', $low->amount);
        $this->assertSame('min', $low->clamped);

        $high = FeeAmount::forExpression(FeeExpression::parse('Principal Amount * 1%'), $this->inputs('10005.00'), '50.00', '80.00');
        $this->assertSame('100.05', $high->roundedAmount);
        $this->assertSame('80.00', $high->amount);
        $this->assertSame('max', $high->clamped);
    }

    public function test_a_days_term_keeps_its_fraction_and_the_inputs_read_as_strings(): void
    {
        $inputs = new FeeFormulaInputs('10000.00', '2.5000', 'weekly', BigRational::ofFraction(45, 30), 45);

        $this->assertSame([
            'principal_amount' => '10000.00',
            'loan_amount' => '10000.00',
            'interest_rate' => '2.5000',
            'interest_rate_frequency' => 'weekly',
            'loan_term' => '1.5',
            'term_in_days' => '45',
        ], $inputs->snapshot());

        $this->assertSame('150.00', FeeAmount::forExpression(FeeExpression::parse('Loan Amount × 1% × Loan Term'), $inputs)->amount);
    }

    public function test_an_unknown_input_leaves_the_fee_out(): void
    {
        $inputs = new FeeFormulaInputs('10000.00', null, 'monthly', null, null);

        $this->assertNull(FeeAmount::forExpression(FeeExpression::parse('Loan Amount / Term in Days'), $inputs));
        $this->assertNull(FeeAmount::forExpression(FeeExpression::parse('Loan Amount * Interest Rate%'), $inputs));
        $this->assertSame('100.00', FeeAmount::forExpression(FeeExpression::parse('Loan Amount * 1%'), $inputs)->amount);
    }

    public function test_the_variables_are_listed_once_in_order(): void
    {
        $this->assertSame(
            ['Loan Amount', 'Term in Days', 'Principal Amount'],
            FeeExpression::parse('loan amount / TERM IN DAYS + Principal Amount * 0 + Loan Amount')->variables(),
        );
    }

    public function test_a_zero_divisor_on_a_loan_throws_naming_the_variable(): void
    {
        try {
            FeeAmount::forExpression(FeeExpression::parse('Loan Amount / Term in Days * 3%'), $this->inputs(days: 0));
            $this->fail('A zero Term in Days was divided by.');
        } catch (FeeExpressionException $e) {
            $this->assertSame(FeeExpressionException::DIVIDES_BY_ZERO, $e->reason);
            $this->assertSame('Term in Days is 0', $e->detail);
        }
    }

    public function test_a_negative_result_on_a_loan_throws(): void
    {
        try {
            FeeAmount::forExpression(FeeExpression::parse('Principal Amount * 1% - 2000'), $this->inputs('10000.00'));
            $this->fail('A negative fee was worked out.');
        } catch (FeeExpressionException $e) {
            $this->assertSame(FeeExpressionException::NEGATIVE, $e->reason);
        }
    }

    public function test_an_unknown_name_is_refused_in_words(): void
    {
        $this->assertSame(self::VARIABLE_LIST." 'Principle' isn't one.", $this->refusal('Principle * 2%'));
        $this->assertSame(self::VARIABLE_LIST." 'e' isn't one.", $this->refusal('Loan Amount * 1e5'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strayCharacters(): array
    {
        return [
            'semicolon' => ['Loan Amount * 2%;'],
            'caret' => ['Loan Amount ^ 2'],
            'dollar' => ['$Loan Amount * 2%'],
            'comma' => ['Loan Amount * 1,000'],
            'quote' => ['"Loan Amount" * 2%'],
            'accented letter' => ['Loan Amount * 2% + é'],
        ];
    }

    #[DataProvider('strayCharacters')]
    public function test_a_stray_character_is_refused_in_words(string $source): void
    {
        $this->assertSame(self::STRAY, $this->refusal($source));
    }

    public function test_unbalanced_brackets_are_refused_in_words(): void
    {
        $this->assertSame('A bracket is opened and never closed.', $this->refusal('(Loan Amount * 2%'));
        $this->assertSame('A bracket is closed that was never opened.', $this->refusal('Loan Amount * 2%)'));
    }

    public function test_a_literal_divide_by_zero_is_refused_by_folding_constants(): void
    {
        $this->assertSame('The formula divides by zero.', $this->refusal('Loan Amount / 0'));
        $this->assertSame('The formula divides by zero.', $this->refusal('Loan Amount ÷ (2 − 2)'));
        $this->assertSame('The formula divides by zero.', $this->refusal('Loan Amount / (0 * 5%)'));
    }

    public function test_a_formula_with_no_variable_or_nothing_in_it_is_refused(): void
    {
        $this->assertSame('The formula needs at least one variable; for a fixed amount use the Fixed type.', $this->refusal('100 * 2%'));
        $this->assertSame('Write a formula, for example (Principal Amount × 2.5%) ÷ 365.', $this->refusal('   '));
    }

    public function test_an_incomplete_formula_is_refused_in_words(): void
    {
        $this->assertSame('Something is missing after "*": a number, a variable or a bracket.', $this->refusal('Loan Amount *'));
        $this->assertSame('Put an operator (+ − × ÷) between "Loan Amount" and "2".', $this->refusal('Loan Amount 2%'));
        $this->assertSame('Something is missing after "(": a number, a variable or a bracket.', $this->refusal('Loan Amount * ()'));
        $this->assertSame('Something is missing before "*": a number, a variable or a bracket.', $this->refusal('* Loan Amount'));
    }

    public function test_the_limits_are_refused_in_words(): void
    {
        $this->assertSame(self::TOO_LONG, $this->refusal('Loan Amount * 1'.str_repeat(' ', 250).'%'));
        $this->assertSame(self::TOO_LONG, $this->refusal('Loan Amount'.str_repeat(' + 1', 32)));
        $this->assertSame(self::TOO_LONG, $this->refusal(str_repeat('(', 11).'Loan Amount'.str_repeat(')', 11)));

        $this->assertSame('110.00', FeeAmount::forExpression(
            FeeExpression::parse(str_repeat('(', 10).'Loan Amount * 1%'.str_repeat(')', 10).' + 10'),
            $this->inputs('10000.00'),
        )->amount);
    }

    public function test_the_trimmed_source_is_kept_as_written(): void
    {
        $this->assertSame('(Principal Amount × 2.5%) ÷ 365', FeeExpression::parse("  (Principal Amount × 2.5%) ÷ 365 \n")->source());
    }
}
