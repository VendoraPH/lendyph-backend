<?php

namespace App\Services\CsvImport;

/**
 * A rebuilt loan schedule, or the reasons it could not be rebuilt.
 */
final class ReconstructedSchedule
{
    /**
     * @param  int  $term  The value for `loans.term` — a PERIOD COUNT in units of
     *                     `frequency`, which is not the CSV's "Term in Months".
     * @param  list<ReconstructedPeriod>  $periods
     * @param  list<RowNote>  $warnings
     * @param  list<RowNote>  $errors
     */
    private function __construct(
        public readonly int $term,
        public readonly string $frequency,
        public readonly string $maturityDate,
        public readonly array $periods,
        public readonly array $warnings,
        public readonly array $errors,
    ) {}

    /**
     * @param  list<ReconstructedPeriod>  $periods
     */
    public static function built(int $term, string $frequency, string $maturityDate, array $periods, RowNoteBag $notes): self
    {
        return new self($term, $frequency, $maturityDate, $periods, $notes->warnings(), $notes->errors());
    }

    public static function failed(RowNoteBag $notes): self
    {
        return new self(0, '', '', [], $notes->warnings(), $notes->errors());
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->periods !== [];
    }

    public function totalPrincipalDue(): int
    {
        return array_sum(array_map(static fn (ReconstructedPeriod $p): int => $p->principalDue, $this->periods));
    }

    public function totalInterestDue(): int
    {
        return array_sum(array_map(static fn (ReconstructedPeriod $p): int => $p->interestDue, $this->periods));
    }

    /**
     * The figure Loan::$outstanding_balance and
     * AmortizationSchedule::remainingPrincipalSql() will read back — floored
     * per row, never netted across rows.
     */
    public function outstandingPrincipal(): int
    {
        return array_sum(array_map(static fn (ReconstructedPeriod $p): int => $p->principalOutstanding(), $this->periods));
    }

    /**
     * The twin of AmortizationSchedule::remainingInterestSql().
     */
    public function outstandingInterest(): int
    {
        return array_sum(array_map(static fn (ReconstructedPeriod $p): int => $p->interestOutstanding(), $this->periods));
    }

    /**
     * Rows ready for AmortizationSchedule::create(), money rendered as exact
     * decimal STRINGS.
     *
     * Strings, not floats: the whole point of holding centavos through the
     * reconstruction is lost if the last step hands Eloquent a float to convert.
     * `penalty_amount` and `penalty_paid` are zero on every imported row — the
     * coop's penalties are not in the export, and inventing them would put
     * charges on a member's account that nobody levied.
     *
     * @return list<array{period_number: int, due_date: string, principal_due: string, interest_due: string, total_due: string, remaining_balance: string, principal_paid: string, interest_paid: string, penalty_amount: string, penalty_paid: string, status: string}>
     */
    public function toScheduleRows(): array
    {
        return array_map(static function (ReconstructedPeriod $period): array {
            $toPesos = static fn (int $centavos): string => sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);

            return [
                'period_number' => $period->periodNumber,
                'due_date' => $period->dueDate,
                'principal_due' => $toPesos($period->principalDue),
                'interest_due' => $toPesos($period->interestDue),
                'total_due' => $toPesos($period->principalDue + $period->interestDue),
                'remaining_balance' => $toPesos($period->remainingBalance),
                'principal_paid' => $toPesos($period->principalPaid),
                'interest_paid' => $toPesos($period->interestPaid),
                'penalty_amount' => '0.00',
                'penalty_paid' => '0.00',
                'status' => $period->status,
            ];
        }, $this->periods);
    }
}
