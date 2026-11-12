<?php

namespace Tests\Unit\Savings;

use App\Services\Savings\InterestCalculator;
use App\Services\Savings\SavingsMath;
use PHPUnit\Framework\TestCase;

/**
 * Tiered rates (gap round S2): a segment can carry its whole ladder
 * (`tiers`), and each day's closing balance earns the rate of the highest
 * tier whose minimum balance it reaches (whole-balance tiering). The base
 * tier (no minimum) covers everything below the first tier. The ladder and
 * the tier each day used are recorded in `rate_segments`, so a posting can
 * be worked out again from the line alone.
 *
 * At 36.5% a ₱1.00 balance (100 centavos) earns 0.1 centavo a day, so one
 * day at ₱X earns X × 0.1 centavos; the figures below are picked to land on
 * whole centavos.
 */
class TieredInterestCalculatorTest extends TestCase
{
    /** Base 3.65%, ₱10,000.00 and up 7.3%, ₱50,000.00 and up 36.5%. */
    private function ladder(): array
    {
        return [
            ['min_balance' => null, 'rate' => '3.6500'],
            ['min_balance' => '10000.00', 'rate' => '7.3000'],
            ['min_balance' => '50000.00', 'rate' => '36.5000'],
        ];
    }

    private function tiered(string $from, string $to, ?array $tiers = null): array
    {
        return ['from' => $from, 'to' => $to, 'rate' => '3.6500', 'tiers' => $tiers ?? $this->ladder()];
    }

    private function oneDay(int $balance): array
    {
        return (new InterestCalculator)->calculate($balance, [], '2026-01-01', '2026-01-01', [$this->tiered('2026-01-01', '2026-01-01')], 0, '0');
    }

    public function test_a_balance_one_centavo_below_a_tier_earns_the_tier_under_it(): void
    {
        // 999,999 centavos × 36,500 units ÷ 365,000,000 = 99.9999 → 100 centavos at 3.65%.
        $below = $this->oneDay(999_999);

        $this->assertSame('3.6500', $below['rate']);
        $this->assertSame(100, $below['gross']);
        $this->assertSame([['min_balance' => null, 'rate' => '3.6500', 'days' => 1, 'balance_days' => 999_999]], $below['rate_segments'][0]['by_tier']);
    }

    public function test_a_balance_equal_to_a_tier_earns_that_tier(): void
    {
        // 1,000,000 × 73,000 ÷ 365,000,000 = 200 centavos at 7.3%.
        $equal = $this->oneDay(1_000_000);

        $this->assertSame('7.3000', $equal['rate']);
        $this->assertSame(200, $equal['gross']);
        $this->assertSame([['min_balance' => '10000.00', 'rate' => '7.3000', 'days' => 1, 'balance_days' => 1_000_000]], $equal['rate_segments'][0]['by_tier']);
    }

    public function test_a_balance_one_centavo_above_a_tier_earns_that_tier(): void
    {
        $above = $this->oneDay(1_000_001);

        $this->assertSame('7.3000', $above['rate']);
        $this->assertSame(200, $above['gross']);
    }

    public function test_the_top_tier_applies_from_its_minimum_up(): void
    {
        $this->assertSame('7.3000', $this->oneDay(4_999_999)['rate']);
        // 5,000,000 × 365,000 ÷ 365,000,000 = 5,000 centavos at 36.5%.
        $top = $this->oneDay(5_000_000);
        $this->assertSame('36.5000', $top['rate']);
        $this->assertSame(5_000, $top['gross']);
    }

    public function test_a_balance_crossing_tiers_within_a_period_earns_each_days_own_tier(): void
    {
        // Jan 1–3 at ₱5,000.00 (base), Jan 4–5 at ₱20,000.00 (₱10,000.00 tier), Jan 6 at ₱60,000.00 (top), Jan 7 back to ₱5,000.00.
        $result = (new InterestCalculator)->calculate(
            500_000,
            ['2026-01-04' => 1_500_000, '2026-01-06' => 4_000_000, '2026-01-07' => -5_500_000],
            '2026-01-01',
            '2026-01-07',
            [$this->tiered('2026-01-01', '2026-01-07')],
            0,
            '0',
        );

        // Base: 4 days, 2,000,000 centavo-days × 36,500 = 73,000,000,000.
        // ₱10,000.00 tier: 2 days, 4,000,000 × 73,000 = 292,000,000,000.
        // Top: 1 day, 6,000,000 × 365,000 = 2,190,000,000,000.
        // Σ 2,555,000,000,000 ÷ 365,000,000 = 7,000 centavos.
        $this->assertSame(7_000, $result['gross']);
        $this->assertSame(12_000_000, $result['balance_days']);
        $this->assertSame('3.6500', $result['rate'], 'The line reads the tier the last day earned.');
        $this->assertSame([
            [
                'from' => '2026-01-01',
                'to' => '2026-01-07',
                'rate' => '3.6500',
                'balance_days' => 12_000_000,
                'tiers' => [
                    ['min_balance' => null, 'rate' => '3.6500'],
                    ['min_balance' => '10000.00', 'rate' => '7.3000'],
                    ['min_balance' => '50000.00', 'rate' => '36.5000'],
                ],
                'by_tier' => [
                    ['min_balance' => null, 'rate' => '3.6500', 'days' => 4, 'balance_days' => 2_000_000],
                    ['min_balance' => '10000.00', 'rate' => '7.3000', 'days' => 2, 'balance_days' => 4_000_000],
                    ['min_balance' => '50000.00', 'rate' => '36.5000', 'days' => 1, 'balance_days' => 6_000_000],
                ],
            ],
        ], $result['rate_segments']);
    }

    public function test_a_ladder_changing_mid_period_is_taken_per_segment(): void
    {
        // ₱20,000.00 all of Jan 1–4. Jan 1–2: 7.3% for ₱10,000.00 and up. Jan 3–4: the tier rises to ₱25,000.00, so the base 3.65% applies.
        $result = (new InterestCalculator)->calculate(2_000_000, [], '2026-01-01', '2026-01-04', [
            $this->tiered('2026-01-01', '2026-01-02'),
            $this->tiered('2026-01-03', '2026-01-04', [
                ['min_balance' => null, 'rate' => '3.6500'],
                ['min_balance' => '25000.00', 'rate' => '7.3000'],
            ]),
        ], 0, '0');

        // 4,000,000 × 73,000 + 4,000,000 × 36,500 = 438,000,000,000 ÷ 365,000,000 = 1,200 centavos.
        $this->assertSame(1_200, $result['gross']);
        $this->assertSame('7.3000', $result['rate_segments'][0]['rate']);
        $this->assertSame('3.6500', $result['rate_segments'][1]['rate']);
        $this->assertSame([['min_balance' => null, 'rate' => '3.6500', 'days' => 2, 'balance_days' => 4_000_000]], $result['rate_segments'][1]['by_tier']);
    }

    public function test_tiers_inside_one_segment_are_not_overlapping_segments(): void
    {
        $result = (new InterestCalculator)->calculate(2_000_000, [], '2026-01-01', '2026-01-31', [$this->tiered('2026-01-01', '2026-01-31')], 0, '0');

        $this->assertCount(1, $result['rate_segments']);
        $this->assertNull($result['skip_reason']);
    }

    public function test_the_minimum_adb_still_applies_with_tiers(): void
    {
        $result = (new InterestCalculator)->calculate(2_000_000, [], '2026-01-01', '2026-01-31', [$this->tiered('2026-01-01', '2026-01-31')], 2_000_001, '0');

        $this->assertSame(0, $result['gross']);
        $this->assertSame(InterestCalculator::SKIP_BELOW_MINIMUM_ADB, $result['skip_reason']);
        $this->assertArrayHasKey('by_tier', $result['rate_segments'][0]);
    }

    public function test_the_gross_equals_the_recorded_tiers_worked_out_again(): void
    {
        $result = (new InterestCalculator)->calculate(
            987_654,
            ['2026-02-03' => 123_456, '2026-02-17' => 4_100_000, '2026-02-25' => -3_000_000],
            '2026-02-01',
            '2026-02-28',
            [$this->tiered('2026-02-01', '2026-02-28')],
            0,
            '0',
        );

        $numerator = 0;

        foreach ($result['rate_segments'] as $segment) {
            foreach ($segment['by_tier'] as $tier) {
                $numerator += $tier['balance_days'] * SavingsMath::rateUnits($tier['rate']);
            }
        }

        $this->assertSame(SavingsMath::roundHalfUp($numerator, 365_000_000), $result['gross']);
    }

    public function test_a_single_rate_segment_keeps_todays_shape_and_figures(): void
    {
        $plain = (new InterestCalculator)->calculate(100_000, ['2026-01-03' => 50_000], '2026-01-01', '2026-01-05', [
            ['from' => '2026-01-01', 'to' => '2026-01-05', 'rate' => '36.5000'],
        ], 0, '0');
        $baseOnly = (new InterestCalculator)->calculate(100_000, ['2026-01-03' => 50_000], '2026-01-01', '2026-01-05', [
            ['from' => '2026-01-01', 'to' => '2026-01-05', 'rate' => '36.5000', 'tiers' => [['min_balance' => null, 'rate' => '36.5000']]],
        ], 0, '0');

        $this->assertSame([['from' => '2026-01-01', 'to' => '2026-01-05', 'rate' => '36.5000', 'balance_days' => 650_000]], $plain['rate_segments']);
        $this->assertSame(650, $plain['gross']);
        $this->assertSame($plain, $baseOnly, 'A ladder with only its base tier reads exactly like a single rate.');
    }
}
