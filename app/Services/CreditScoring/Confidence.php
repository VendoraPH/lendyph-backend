<?php

namespace App\Services\CreditScoring;

/**
 * How far a score can be trusted, apart from the score itself:
 * - high: at least 12 matured periods, with income and expenses declared;
 * - medium: at least 3 matured periods, or verified income with expenses;
 * - low: otherwise.
 * A thin file reads Limited History, always with Low confidence: a member
 * with no completed loan, unless one of their loans ended badly (defaulted,
 * written off or restructured), the same rule that leaves the
 * completed-loan factors without data. When most
 * matured periods rely on payment records from before 1 October 2026 (the
 * replayed allocations), confidence is capped at medium.
 */
final class Confidence
{
    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    public const HIGH_MIN_PERIODS = 12;

    public const MEDIUM_MIN_PERIODS = 3;

    private function __construct(
        public readonly string $level,
        public readonly string $reason,
        public readonly bool $limitedHistory,
    ) {}

    public static function assess(PaymentHistory $history, Capacity $capacity, bool $incomeVerified): self
    {
        $endedBadly = $history->defaultedLoans + $history->recoveredFromDefaultLoans + $history->writtenOffLoans + $history->restructuredLoans > 0;

        if ($history->completedLoans === 0 && ! $endedBadly) {
            return new self(self::LOW, 'Limited History: the member has no completed Lendy loan yet, so confidence is low.', true);
        }

        $periods = $history->maturedCount;
        $hasIncome = $capacity->incomeCentavos !== null;
        $hasExpenses = $capacity->expensesCentavos !== null;
        $periodText = $periods === 1 ? '1 matured period' : "{$periods} matured periods";

        [$level, $reason] = match (true) {
            $periods >= self::HIGH_MIN_PERIODS && $hasIncome && $hasExpenses => [self::HIGH, "{$periodText} of Lendy repayment history, with income and expenses declared."],
            $periods >= self::MEDIUM_MIN_PERIODS => [self::MEDIUM, $periods >= self::HIGH_MIN_PERIODS
                ? "{$periodText} of Lendy repayment history, but income or expenses are not declared."
                : "{$periodText} of Lendy repayment history."],
            $incomeVerified && $hasExpenses => [self::MEDIUM, "Verified income and declared expenses, with {$periodText} of Lendy repayment history."],
            $periods === 0 => [self::LOW, 'The member has no matured loan periods in Lendy yet.'],
            default => [self::LOW, "Only {$periodText} of Lendy repayment history, and no verified income with expenses."],
        };

        if ($level === self::HIGH && $history->legacyCount * 2 > $periods) {
            $level = self::MEDIUM;
            $reason .= ' Capped at medium: most matured periods rely on payment records from before 1 October 2026.';
        }

        return new self($level, $reason, false);
    }
}
