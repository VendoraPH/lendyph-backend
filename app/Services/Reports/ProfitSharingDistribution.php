<?php

namespace App\Services\Reports;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Each member's share of a distributable profit (finish run round 2, decision
 * 17): (their share capital ÷ all members' share capital) × the profit, in
 * exact maths, rounded half up to the centavo.
 *
 * Every amount is integer centavos, and each product is taken in arbitrary
 * precision: ₱1,000,000,000.00 of share capital times a profit of the same
 * size is 10^22 centavos², far past what a PHP integer or float holds exactly.
 *
 * Nothing is shared unless there is a profit (above ₱0.00) and some share
 * capital. What the rounding leaves over, the profit less the earnings added
 * up, is `remainder`: reported, never spread over the members. It is at most
 * half a centavo per member, either way.
 */
final readonly class ProfitSharingDistribution
{
    /**
     * @param  array<int, int>  $earnings  centavos, by member, in the order given
     * @param  array<int, string>  $sharePercent  each member's part of the share capital, a percentage rounded half up to four places
     */
    private function __construct(
        public bool $shared,
        public array $earnings,
        public array $sharePercent,
        public int $totalShareCapital,
        public int $totalEarnings,
        public int $remainder,
    ) {}

    /**
     * @param  array<int, int>  $shareCapital  each member's share capital in centavos, above zero, keyed by member
     * @param  int  $profit  the distributable profit in centavos; at or below zero nothing is shared
     *
     * @throws InvalidArgumentException when a member's share capital isn't above zero
     */
    public static function of(array $shareCapital, int $profit): self
    {
        $total = BigInteger::zero();

        foreach ($shareCapital as $member => $centavos) {
            if ($centavos <= 0) {
                throw new InvalidArgumentException("Member {$member} holds no share capital to share a profit by.");
            }

            $total = $total->plus($centavos);
        }

        $shared = $profit > 0 && $total->isPositive();
        $earnings = [];
        $sharePercent = [];

        foreach ($shareCapital as $member => $centavos) {
            $sharePercent[$member] = (string) BigDecimal::of($centavos)
                ->multipliedBy(100)
                ->dividedBy($total, 4, RoundingMode::HalfUp);

            $earnings[$member] = $shared
                ? BigInteger::of($centavos)->multipliedBy($profit)->dividedBy($total, RoundingMode::HalfUp)->toInt()
                : 0;
        }

        $totalEarnings = array_sum($earnings);

        return new self(
            shared: $shared,
            earnings: $earnings,
            sharePercent: $sharePercent,
            totalShareCapital: $total->toInt(),
            totalEarnings: $totalEarnings,
            remainder: $shared ? $profit - $totalEarnings : 0,
        );
    }
}
