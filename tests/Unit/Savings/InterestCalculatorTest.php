<?php

namespace Tests\Unit\Savings;

use App\Services\Savings\ApprovalGate;
use App\Services\Savings\InterestCalculator;
use App\Services\Savings\SavingsMath;
use OverflowException;
use PHPUnit\Framework\TestCase;

/**
 * Average daily balance interest on an Actual/365 Fixed basis (design §5):
 * gross = roundHalfUp(Σ S_seg × r_seg, 365 × 10⁶), tax = roundHalfUp(gross × t, 10⁶),
 * all in integer centavos. Plus the approval tier edges.
 */
class InterestCalculatorTest extends TestCase
{
    private function segment(string $from, string $to, string $rate): array
    {
        return ['from' => $from, 'to' => $to, 'rate' => $rate];
    }

    public function test_round_half_up_rounds_exactly_half_a_centavo_up(): void
    {
        $this->assertSame(1, SavingsMath::roundHalfUp(5, 10));
        $this->assertSame(0, SavingsMath::roundHalfUp(4, 10));
        $this->assertSame(2, SavingsMath::roundHalfUp(15, 10));
        $this->assertSame(3, SavingsMath::roundHalfUp(25, 10));
        $this->assertSame(-1, SavingsMath::roundHalfUp(-5, 10));
    }

    public function test_rates_are_read_exactly_to_four_decimals(): void
    {
        $this->assertSame(25_000, SavingsMath::rateUnits('2.5000'));
        $this->assertSame(12_345, SavingsMath::rateUnits('1.2345'));
        $this->assertSame(1_000_000, SavingsMath::rateUnits('100'));
        $this->assertSame(0, SavingsMath::rateUnits('0.0000'));
    }

    public function test_interest_exactly_on_half_a_centavo_rounds_up(): void
    {
        // One day at ₱3,650.00 (365,000 centavos) and 0.0500% (500 units):
        // 365,000 × 500 = 182,500,000, exactly half of 365,000,000.
        $result = (new InterestCalculator)->calculate(
            opening: 365_000,
            deltas: [],
            start: '2026-01-01',
            end: '2026-01-01',
            segments: [$this->segment('2026-01-01', '2026-01-01', '0.0500')],
            minimumAdb: 0,
            taxRate: '0',
        );

        // 365,000 × 500 = 182,500,000 = exactly 0.5 × 365,000,000.
        $this->assertSame(182_500_000, 365_000 * 500);
        $this->assertSame(1, $result['gross']);

        $below = (new InterestCalculator)->calculate(364_999, [], '2026-01-01', '2026-01-01', [$this->segment('2026-01-01', '2026-01-01', '0.0500')], 0, '0');
        $this->assertSame(0, $below['gross']);
    }

    public function test_a_leap_year_is_still_divided_by_365(): void
    {
        // ₱100,000.00 for the whole of 2028 (366 days) at 3.6500%:
        // 10,000,000 × 366 × 36,500 / 365,000,000 = 366,000 centavos.
        $result = (new InterestCalculator)->calculate(10_000_000, [], '2028-01-01', '2028-12-31', [$this->segment('2028-01-01', '2028-12-31', '3.6500')], 0, '0');

        $this->assertSame(366, $result['days']);
        $this->assertSame(10_000_000 * 366, $result['balance_days']);
        $this->assertSame(366_000, $result['gross']);
        $this->assertSame(10_000_000, $result['average_daily_balance']);
    }

    public function test_balances_follow_the_value_dated_deltas(): void
    {
        // ₱1,000.00 on the 1st, +₱500.00 value-dated the 3rd, −₱200.00 the 5th, over 1–5 Jan:
        // 100,000 + 100,000 + 150,000 + 150,000 + 130,000 = 630,000 centavo-days.
        $result = (new InterestCalculator)->calculate(100_000, ['2026-01-03' => 50_000, '2026-01-05' => -20_000], '2026-01-01', '2026-01-05', [$this->segment('2026-01-01', '2026-01-05', '36.5000')], 0, '0');

        $this->assertSame(630_000, $result['balance_days']);
        $this->assertSame(126_000, $result['average_daily_balance']);
        // 630,000 × 365,000 / 365,000,000 = 630.
        $this->assertSame(630, $result['gross']);
    }

    public function test_each_rate_segment_earns_its_own_rate(): void
    {
        // ₱365,000.00 flat: S1 = 36,500,000 × 10 = 365,000,000 centavo-days at 1%,
        // S2 = 36,500,000 × 5 = 182,500,000 at 2%.
        $result = (new InterestCalculator)->calculate(36_500_000, [], '2026-03-01', '2026-03-15', [
            $this->segment('2026-03-01', '2026-03-10', '1.0000'),
            $this->segment('2026-03-11', '2026-03-15', '2.0000'),
        ], 0, '0');

        // (365,000,000 × 10,000 + 182,500,000 × 20,000) / 365,000,000 = 10,000 + 10,000.
        $this->assertSame(20_000, $result['gross']);
        $this->assertCount(2, $result['rate_segments']);
        $this->assertSame('2.0000', $result['rate']);
        $this->assertSame(365_000_000, $result['rate_segments'][0]['balance_days']);
    }

    public function test_tax_is_withheld_half_up_and_net_is_the_rest(): void
    {
        $result = (new InterestCalculator)->calculate(36_500_000, [], '2026-03-01', '2026-03-10', [$this->segment('2026-03-01', '2026-03-10', '1.0000')], 0, '20.0000');

        $this->assertSame(10_000, $result['gross']);
        $this->assertSame(2_000, $result['tax']);
        $this->assertSame(8_000, $result['net']);

        // 3 centavos at 20% = 0.6 → 1; at 10% = 0.3 → 0.
        $this->assertSame(1, SavingsMath::share(3, '20.0000'));
        $this->assertSame(0, SavingsMath::share(3, '10.0000'));
    }

    public function test_an_average_below_the_minimum_earns_nothing_with_a_reason(): void
    {
        $calculator = new InterestCalculator;
        $segments = [$this->segment('2026-01-01', '2026-01-10', '5.0000')];

        $below = $calculator->calculate(49_999, [], '2026-01-01', '2026-01-10', $segments, 50_000, '0');
        $at = $calculator->calculate(50_000, [], '2026-01-01', '2026-01-10', $segments, 50_000, '0');

        $this->assertSame(0, $below['gross']);
        $this->assertSame(0, $below['tax']);
        $this->assertSame(InterestCalculator::SKIP_BELOW_MINIMUM_ADB, $below['skip_reason']);
        $this->assertNull($at['skip_reason']);
        $this->assertGreaterThan(0, $at['gross']);
    }

    public function test_an_empty_range_earns_nothing(): void
    {
        $result = (new InterestCalculator)->calculate(100_000, [], '2026-01-05', '2026-01-04', [], 0, '0');

        $this->assertSame(0, $result['days']);
        $this->assertSame(0, $result['gross']);
        $this->assertSame(InterestCalculator::SKIP_NO_DAYS, $result['skip_reason']);
    }

    public function test_an_overflowing_numerator_is_refused(): void
    {
        $this->expectException(OverflowException::class);

        // ₱90 trillion for a year at 100% overflows a 64-bit numerator.
        (new InterestCalculator)->calculate(9_000_000_000_000_000, [], '2026-01-01', '2026-12-31', [$this->segment('2026-01-01', '2026-12-31', '100.0000')], 0, '0');
    }

    public function test_multiply_refuses_an_overflow(): void
    {
        $this->assertSame(6, SavingsMath::multiply(2, 3));
        $this->expectException(OverflowException::class);
        SavingsMath::multiply(PHP_INT_MAX, 2);
    }

    public function test_the_tier_edges(): void
    {
        $tier1 = 1_000_000;
        $tier2 = 5_000_000;

        $this->assertSame(ApprovalGate::TIER_DIRECT, ApprovalGate::tier(1, $tier1, $tier2));
        $this->assertSame(ApprovalGate::TIER_DIRECT, ApprovalGate::tier(1_000_000, $tier1, $tier2));
        $this->assertSame(ApprovalGate::TIER_SINGLE, ApprovalGate::tier(1_000_001, $tier1, $tier2));
        $this->assertSame(ApprovalGate::TIER_SINGLE, ApprovalGate::tier(5_000_000, $tier1, $tier2));
        $this->assertSame(ApprovalGate::TIER_DUAL, ApprovalGate::tier(5_000_001, $tier1, $tier2));
    }
}
