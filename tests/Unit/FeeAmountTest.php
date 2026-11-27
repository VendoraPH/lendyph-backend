<?php

namespace Tests\Unit;

use App\Services\FeeAmount;
use PHPUnit\Framework\TestCase;

/**
 * The one calculation every fee amount comes from: a fixed peso figure, a
 * percentage of the principal, or a formula, which is a percentage of the
 * principal, optionally per month of the term, kept between a minimum and a
 * maximum and rounded half up to the centavo.
 *
 * No database and no application boot: this is pure arithmetic.
 */
class FeeAmountTest extends TestCase
{
    private function formula(float $principal, string $rate, ?array $months = null, bool $perMonth = false, ?string $min = null, ?string $max = null): ?float
    {
        return FeeAmount::forRule('formula', $rate, $principal, $months, $perMonth, $min, $max);
    }

    public function test_a_formula_below_its_minimum_charges_the_minimum(): void
    {
        // 1% of ₱10,000.00 is ₱100.00, under the ₱150.00 minimum.
        $this->assertSame(150.0, $this->formula(10000, '1.0000', min: '150.00', max: '300.00'));
    }

    public function test_a_formula_above_its_maximum_charges_the_maximum(): void
    {
        // 1% of ₱50,000.00 is ₱500.00, over the ₱300.00 maximum.
        $this->assertSame(300.0, $this->formula(50000, '1.0000', min: '150.00', max: '300.00'));
    }

    public function test_a_formula_between_its_bounds_charges_the_percentage(): void
    {
        $this->assertSame(200.0, $this->formula(20000, '1.0000', min: '150.00', max: '300.00'));
    }

    public function test_a_formula_without_bounds_is_the_plain_percentage(): void
    {
        $this->assertSame(200.0, $this->formula(20000, '1.0000'));
        $this->assertSame(200.0, FeeAmount::forRule('percentage', '1.0000', 20000));
    }

    public function test_per_month_multiplies_by_a_six_month_term(): void
    {
        $months = FeeAmount::termMonths(6, 'months');

        // 0.5% × 6 months of ₱10,000.00.
        $this->assertSame(300.0, $this->formula(10000, '0.5000', $months, perMonth: true));
        // Without per_month the term is ignored.
        $this->assertSame(50.0, $this->formula(10000, '0.5000', $months));
    }

    public function test_a_days_term_converts_on_the_schedule_engines_thirty_day_month(): void
    {
        // 84 days (12 weekly instalments) is 2.8 months; 45 days is 1.5.
        $this->assertSame(280.0, $this->formula(10000, '1.0000', FeeAmount::termMonths(84, 'days'), perMonth: true));
        $this->assertSame(150.0, $this->formula(10000, '1.0000', FeeAmount::termMonths(45, 'days'), perMonth: true));
    }

    public function test_per_month_is_clamped_after_multiplying(): void
    {
        // 1% × 6 months of ₱10,000.00 is ₱600.00, capped at ₱500.00.
        $this->assertSame(500.0, $this->formula(10000, '1.0000', FeeAmount::termMonths(6, 'months'), true, '100.00', '500.00'));
    }

    public function test_per_month_without_a_known_term_cannot_be_computed(): void
    {
        $this->assertNull($this->formula(10000, '1.0000', null, perMonth: true));
        $this->assertNull(FeeAmount::termMonths(null, 'months'));
        $this->assertNull(FeeAmount::termMonths(0, 'months'));
    }

    public function test_it_rounds_half_up_to_the_centavo(): void
    {
        // 0.1% of ₱10,005.00 is exactly ₱10.005: half up is ₱10.01.
        $this->assertSame(10.01, $this->formula(10005, '0.1000'));
        // 0.0125% × 45/30 months of ₱10,001.00 is ₱1.8751875: ₱1.88.
        $this->assertSame(1.88, $this->formula(10001, '0.0125', FeeAmount::termMonths(45, 'days'), perMonth: true));
        // ₱1.8749… rounds down.
        $this->assertSame(1.87, $this->formula(9999, '0.0125', FeeAmount::termMonths(45, 'days'), perMonth: true));
    }

    public function test_fixed_and_percentage_keep_their_arithmetic(): void
    {
        $this->assertSame(500.0, FeeAmount::forRule('fixed', '500.0000', 10000));
        $this->assertSame(33.33, FeeAmount::forRule('percentage', '0.3333', 10000));
        // Bounds and per_month only mean something on a formula.
        $this->assertSame(200.0, FeeAmount::forRule('percentage', '2.0000', 10000, FeeAmount::termMonths(6, 'months'), true, '500.00', '100.00'));
    }

    public function test_a_custom_fee_array_reads_the_same_fields(): void
    {
        $fee = ['name' => 'Handling', 'type' => 'formula', 'value' => 0.5, 'per_month' => true, 'min_amount' => 100, 'max_amount' => 250];

        $this->assertSame(250.0, FeeAmount::forCustomFee($fee, 10000, FeeAmount::termMonths(6, 'months')));
        $this->assertSame(100.0, FeeAmount::forCustomFee($fee, 1000, FeeAmount::termMonths(6, 'months')));
        $this->assertSame(150.0, FeeAmount::forCustomFee($fee, 5000, FeeAmount::termMonths(6, 'months')));
    }
}
