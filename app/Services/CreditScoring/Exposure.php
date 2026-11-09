<?php

namespace App\Services\CreditScoring;

use App\Models\Loan;
use App\Services\LoanListTotals;
use Carbon\Carbon;

/**
 * What a member owes Lendy now, across every branch: the outstanding and
 * past due amounts by LoanListTotals' rules (without `visibleTo`, so another
 * branch's loan counts), the number of loans still owing, and the monthly
 * payment those loans take.
 *
 * The monthly payment of a loan is its highest calendar-month total of the
 * principal and interest still unpaid on its open instalments, from the
 * current month on: the same measure as the proposed loan's payment
 * (Capacity). Penalty never counts, and a part-paid period counts only what
 * is left of it. Principal and interest still unpaid on periods due before
 * this month are owed now, so they count in this month's total; a loan past
 * its maturity owes all of it this month.
 */
final class Exposure
{
    private function __construct(
        public readonly int $outstandingCentavos,
        public readonly int $pastDueCentavos,
        public readonly int $activeLoans,
        public readonly int $monthlyDebtCentavos,
    ) {}

    public static function from(MemberCreditData $data, LoanListTotals $totals): self
    {
        $collectible = $data->collectibleLoans();
        $ids = $collectible->pluck('id')->map(fn ($id) => (int) $id)->all();

        $figures = $ids === []
            ? ['total_outstanding' => 0.0, 'overdue_amount' => 0.0]
            : $totals->for(Loan::query()->whereIn('loans.id', $ids), $data->asOf);

        return new self(
            outstandingCentavos: Centavos::of($figures['total_outstanding']),
            pastDueCentavos: Centavos::of($figures['overdue_amount']),
            activeLoans: count($ids),
            monthlyDebtCentavos: self::monthlyDebt($data, $ids),
        );
    }

    /**
     * The past due amount as a percent of the outstanding amount; 0 when
     * nothing is past due.
     */
    public function pastDuePercent(): float
    {
        if ($this->pastDueCentavos <= 0) {
            return 0.0;
        }

        return $this->outstandingCentavos <= 0 ? 100.0 : $this->pastDueCentavos * 100 / $this->outstandingCentavos;
    }

    /**
     * @param  list<int>  $loanIds
     */
    private static function monthlyDebt(MemberCreditData $data, array $loanIds): int
    {
        $monthStart = $data->asOf->copy()->startOfMonth();
        $thisMonth = $monthStart->format('Y-m');
        $total = 0;

        foreach ($loanIds as $loanId) {
            $months = [];

            foreach ($data->periods->where('loan_id', $loanId) as $period) {
                $owed = Centavos::of($period->principal_due) + Centavos::of($period->interest_due);
                $paid = Centavos::of($period->principal_paid) + Centavos::of($period->interest_paid);
                $due = Carbon::parse($period->due_date);

                if ($period->closed_by_adjustment_id !== null || $period->closed_by_loan_id !== null || $paid >= $owed) {
                    continue;
                }

                $month = $due->lt($monthStart) ? $thisMonth : $due->format('Y-m');
                $months[$month] = ($months[$month] ?? 0) + $owed - $paid;
            }

            $total += $months === [] ? 0 : max($months);
        }

        return $total;
    }
}
