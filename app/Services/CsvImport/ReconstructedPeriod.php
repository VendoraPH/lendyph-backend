<?php

namespace App\Services\CsvImport;

/**
 * One rebuilt amortization schedule row. All money in INTEGER CENTAVOS.
 */
final class ReconstructedPeriod
{
    public function __construct(
        public readonly int $periodNumber,
        public readonly string $dueDate,
        public readonly int $principalDue,
        public readonly int $interestDue,
        public readonly int $principalPaid,
        public readonly int $interestPaid,
        public readonly int $remainingBalance,
        public readonly string $status,
    ) {}

    public function principalOutstanding(): int
    {
        return max(0, $this->principalDue - $this->principalPaid);
    }

    public function interestOutstanding(): int
    {
        return max(0, $this->interestDue - $this->interestPaid);
    }
}
