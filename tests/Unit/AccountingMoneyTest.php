<?php

namespace Tests\Unit;

use App\Services\Accounting\Money;
use PHPUnit\Framework\TestCase;

/**
 * Centavo arithmetic, ported from `src/lib/accounting/money.test.ts`.
 *
 * The two implementations have to agree case for case: the browser parses what
 * a user types and the server parses what arrives on the wire, and a journal
 * that balanced in the form must still balance in the database. Every case
 * below exists in the frontend suite as well, which is what makes a divergence
 * visible instead of theoretical.
 *
 * No database and no application boot — this is pure arithmetic.
 */
class AccountingMoneyTest extends TestCase
{
    public function test_parses_a_plain_peso_amount(): void
    {
        $this->assertSame(150050, Money::toCentavos('1500.50'));
    }

    public function test_parses_grouped_input_the_way_a_person_types_it(): void
    {
        $this->assertSame(150050, Money::toCentavos('1,500.50'));
        $this->assertSame(150050, Money::toCentavos('₱1,500.50'));
        $this->assertSame(150050, Money::toCentavos(' 1 500.50 '));
    }

    /**
     * The spaces `Intl.NumberFormat` emits are not the space bar's.
     *
     * A figure copied off a screen carries U+00A0 or U+202F between the
     * thousands, and `\s` alone does not match either. Without them a pasted
     * "₱1 500.50" is refused as text.
     */
    public function test_parses_the_no_break_spaces_a_currency_formatter_emits(): void
    {
        $this->assertSame(150050, Money::toCentavos("₱1\u{00A0}500.50"));
        $this->assertSame(150050, Money::toCentavos("₱1\u{202F}500.50"));
    }

    public function test_accepts_numbers_as_well_as_strings(): void
    {
        $this->assertSame(150050, Money::toCentavos(1500.50));
        $this->assertSame(0, Money::toCentavos(0));
        $this->assertSame(150000, Money::toCentavos(1500));
    }

    /**
     * A blank debit box and a debit of ₱0.00 are different statements: one says
     * "this side is unused", the other says "this side is zero". The manual
     * entry form needs to tell them apart to know which side a line is on.
     */
    public function test_treats_blank_as_absent_not_as_zero(): void
    {
        $this->assertNull(Money::toCentavos(''));
        $this->assertNull(Money::toCentavos('   '));
        $this->assertNull(Money::toCentavos(null));
    }

    public function test_rejects_text_that_is_not_a_number(): void
    {
        $this->assertNull(Money::toCentavos('abc'));
        $this->assertNull(Money::toCentavos('1.2.3'));
        $this->assertNull(Money::toCentavos('--5'));
        $this->assertNull(Money::toCentavos('1e5'));
    }

    /**
     * Direction is carried by which column the amount sits in, never by its
     * sign. A negative debit is really a credit, and allowing both spellings
     * means two entries that look different post identically.
     */
    public function test_rejects_a_negative_amount(): void
    {
        $this->assertNull(Money::toCentavos('-100'));
        $this->assertNull(Money::toCentavos(-100));
        $this->assertNull(Money::toCentavos(-100.50));
    }

    public function test_rounds_half_away_from_zero_at_the_centavo(): void
    {
        $this->assertSame(1, Money::toCentavos('0.005'));
        $this->assertSame(0, Money::toCentavos('0.004'));
        $this->assertSame(156, Money::toCentavos('1.555'));
    }

    /**
     * A fraction that rounds up to a whole peso carries, rather than returning
     * an impossible 100 centavos.
     */
    public function test_a_fraction_rounding_up_to_a_whole_peso_carries(): void
    {
        $this->assertSame(100, Money::toCentavos('0.999'));
        $this->assertSame(200, Money::toCentavos('1.999'));
    }

    /**
     * Strings are parsed digit by digit rather than through a float, so an
     * amount larger than a double can hold exactly still lands on the centavo.
     * This is the case the frontend cannot match and does not have to: it never
     * sees a portfolio-sized figure in a single field.
     */
    public function test_a_large_amount_keeps_its_last_centavo(): void
    {
        $this->assertSame(9007199254740993, Money::toCentavos('90071992547409.93'));
    }

    public function test_survives_the_float_that_breaks_naive_peso_arithmetic(): void
    {
        // 0.1 + 0.2 === 0.30000000000000004 in pesos. In centavos it is just 30.
        $a = Money::toCentavos('0.10');
        $b = Money::toCentavos('0.20');

        $this->assertSame(30, Money::sum([$a, $b]));
        $this->assertSame(Money::toCentavos('0.30'), Money::sum([$a, $b]));
    }

    public function test_a_thousand_small_amounts_still_sum_exactly(): void
    {
        $cents = array_fill(0, 1000, Money::toCentavos('0.01') ?? 0);

        $this->assertSame(1000, Money::sum($cents));
        $this->assertSame(10.0, Money::fromCentavos(Money::sum($cents)));
    }

    public function test_converts_back_to_pesos_for_display(): void
    {
        $this->assertSame(1500.5, Money::fromCentavos(150050));
        $this->assertSame(0.0, Money::fromCentavos(0));
        $this->assertSame(0.01, Money::fromCentavos(1));
    }

    public function test_formats_as_pesos_with_two_decimals(): void
    {
        $this->assertSame('₱1,500.50', Money::format(150050));
        $this->assertSame('₱0.00', Money::format(0));
        $this->assertSame('-₱1,500.50', Money::format(-150050));
    }

    public function test_sum_ignores_absent_values(): void
    {
        $this->assertSame(300, Money::sum([100, null, 200]));
        $this->assertSame(0, Money::sum([]));
    }

    public function test_balance_check_is_exact(): void
    {
        $this->assertTrue(Money::isBalanced(150050, 150050));
        $this->assertFalse(Money::isBalanced(150050, 150049));
        $this->assertTrue(Money::isBalanced(0, 0));
    }

    // ── Amounts as they actually arrive off the wire ──
    //
    // A `decimal:2` cast serialises to a JSON STRING. Every centavo field is
    // typed as a number on the strength of a contract nothing checks at the
    // boundary, so the types said this could not happen while the responses
    // said otherwise.

    public function test_regression_a_numeric_string_used_to_be_skipped_silently(): void
    {
        $wire = ['150050', '250000'];

        $this->assertSame('₱1,500.50', Money::format((int) $wire[0]));
        $this->assertSame(400050, Money::sum($wire));
    }

    public function test_string_and_number_amounts_mix_without_loss(): void
    {
        $this->assertSame(400149, Money::sum([150050, '250000', 99]));
    }

    public function test_a_decimal_string_from_a_decimal_column_still_lands_on_the_centavo(): void
    {
        $this->assertSame(150051, Money::sum(['150050.00', '1.00']));
    }

    public function test_blank_and_absent_values_are_skipped(): void
    {
        $this->assertSame(300, Money::sum([100, '', null, 200]));
    }

    /**
     * One bad row must not turn a money figure into NAN — the same house rule
     * `toShareCapitalBalance` follows.
     */
    public function test_unparseable_amounts_are_skipped_rather_than_poisoning_the_total(): void
    {
        $total = Money::sum([100, 'not a number', 200]);

        $this->assertSame(300, $total);
        $this->assertIsInt($total);
    }

    public function test_non_finite_values_are_skipped(): void
    {
        $this->assertSame(300, Money::sum([100, INF, -INF, NAN, 200]));
    }

    /**
     * `expenses/page.tsx` sums `amount - amount_paid`, which is legitimately
     * negative on an overpaid expense. This is a sum, not a validator.
     */
    public function test_negative_amounts_still_subtract(): void
    {
        $this->assertSame(75000, Money::sum([100000, -25000]));
        $this->assertSame(-25000, Money::sum(['-25000']));
    }

    /**
     * A centavo is the smallest unit that exists here, so a fraction of one is
     * already corrupt. Rounding each value keeps the total comparable by exact
     * equality against `isBalanced`, which has no tolerance band.
     */
    public function test_sub_centavo_dust_is_resolved_per_value(): void
    {
        $this->assertSame(2, Money::sum([0.5, 0.5]));
        $this->assertIsInt(Money::sum([100.4, 99.6]));
        $this->assertSame(200, Money::sum([100.4, 99.6]));
    }

    public function test_integers_are_untouched(): void
    {
        $this->assertSame(6, Money::sum([1, 2, 3]));
        $this->assertSame(150050, Money::sum([150050]));
        $this->assertSame(0, Money::sum([]));
    }

    public function test_a_drained_list_of_string_balances_totals_what_the_cards_show(): void
    {
        $total = Money::sum(['52000000', '125000000', '3500000']);

        $this->assertSame(180500000, $total);
        $this->assertSame('₱1,805,000.00', Money::format($total));
    }
}
