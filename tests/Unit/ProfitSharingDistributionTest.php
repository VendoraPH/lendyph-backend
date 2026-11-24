<?php

namespace Tests\Unit;

use App\Services\Reports\ProfitSharingDistribution;
use PHPUnit\Framework\TestCase;

/**
 * The Profit Sharing report's arithmetic (finish run round 2, decision 17):
 * each member earns (their share capital ÷ all members' share capital) ×
 * distributable profit, in exact maths, half up to the centavo. What the
 * rounding leaves over is reported, never spread.
 *
 * Every amount is integer centavos. No database and no application boot.
 */
class ProfitSharingDistributionTest extends TestCase
{
    public function test_a_hand_checked_example(): void
    {
        // ₱10,000.00, ₱20,000.00 and ₱30,000.00 of ₱60,000.00 share ₱5,000.00:
        // 833.333… → ₱833.33, 1,666.666… → ₱1,666.67, and ₱2,500.00.
        $shares = ProfitSharingDistribution::of([7 => 1_000_000, 8 => 2_000_000, 9 => 3_000_000], 500_000);

        $this->assertTrue($shares->shared);
        $this->assertSame([7 => 83_333, 8 => 166_667, 9 => 250_000], $shares->earnings);
        $this->assertSame([7 => '16.6667', 8 => '33.3333', 9 => '50.0000'], $shares->sharePercent);
        $this->assertSame(6_000_000, $shares->totalShareCapital);
        $this->assertSame(500_000, $shares->totalEarnings);
        $this->assertSame(0, $shares->remainder);
    }

    public function test_what_the_rounding_leaves_over_is_reported(): void
    {
        // Three equal members share ₱100.00: ₱33.33 each, ₱99.99 in all, and
        // ₱0.01 left over that nobody is given.
        $shares = ProfitSharingDistribution::of([1 => 100_000, 2 => 100_000, 3 => 100_000], 10_000);

        $this->assertSame([1 => 3_333, 2 => 3_333, 3 => 3_333], $shares->earnings);
        $this->assertSame(9_999, $shares->totalEarnings);
        $this->assertSame(1, $shares->remainder);
    }

    public function test_a_half_centavo_rounds_up_even_past_the_profit(): void
    {
        // Two equal members share ₱0.01: half a centavo each rounds up to
        // ₱0.01, so ₱0.02 is shared and the remainder is minus ₱0.01.
        $shares = ProfitSharingDistribution::of([1 => 50_000, 2 => 50_000], 1);

        $this->assertSame([1 => 1, 2 => 1], $shares->earnings);
        $this->assertSame(2, $shares->totalEarnings);
        $this->assertSame(-1, $shares->remainder);
    }

    public function test_exact_maths_past_the_range_of_a_float(): void
    {
        // ₱1,234,567,890.12 each, sharing ₱9,876,543.21: each product is about
        // 1.2 × 10^20, far past the 2^53 a float holds exactly, and each share
        // is exactly 493,827,160.5 centavos, which rounds up.
        $shares = ProfitSharingDistribution::of([1 => 123_456_789_012, 2 => 123_456_789_012], 987_654_321);

        $this->assertSame([1 => 493_827_161, 2 => 493_827_161], $shares->earnings);
        $this->assertSame([1 => '50.0000', 2 => '50.0000'], $shares->sharePercent);
        $this->assertSame(-1, $shares->remainder);
    }

    public function test_no_profit_shares_nothing(): void
    {
        foreach ([0, -250_000] as $profit) {
            $shares = ProfitSharingDistribution::of([1 => 1_000_000, 2 => 3_000_000], $profit);

            $this->assertFalse($shares->shared);
            $this->assertSame([1 => 0, 2 => 0], $shares->earnings);
            // The shares of capital still read, for the reader.
            $this->assertSame([1 => '25.0000', 2 => '75.0000'], $shares->sharePercent);
            $this->assertSame(0, $shares->totalEarnings);
            $this->assertSame(0, $shares->remainder);
        }
    }

    public function test_with_no_share_capital_nothing_is_shared(): void
    {
        $shares = ProfitSharingDistribution::of([], 500_000);

        $this->assertFalse($shares->shared);
        $this->assertSame([], $shares->earnings);
        $this->assertSame(0, $shares->totalShareCapital);
        $this->assertSame(0, $shares->totalEarnings);
        $this->assertSame(0, $shares->remainder);
    }
}
