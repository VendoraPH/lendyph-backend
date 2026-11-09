<?php

namespace App\Services\Accounting;

use App\Models\Loan;
use App\Services\LoanListTotals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Loan Accounting summary: the receivable aging as of a date, and what the
 * loan records hold.
 *
 * - `aging` is GET /accounting/loans/aging for the same date and scope.
 * - `records` are the loans {@see LoanAgingBuilder} lists (collectible, with
 *   something unpaid), totalled by {@see LoanListTotals::for()} in integer
 *   centavos. Loan records keep no history, so they are always as of today,
 *   and their own `as_of` says so.
 *
 * Reads only.
 */
final class LoanAccountingSummary
{
    public function __construct(
        private ReceivableAgingBuilder $aging,
        private LoanAgingBuilder $loanAging,
        private LoanListTotals $totals,
    ) {}

    /**
     * @param  int|list<int>|null  $branchId  the branch scope (null: every branch)
     * @param  Builder<Loan>|null  $loans  a starting query, such as Loan::visibleTo($user)
     * @return array{as_of: string, aging: array<string, mixed>, records: array{as_of: string, loan_count: int, outstanding_principal: int, total_outstanding: int, past_due_amount: int, past_due_count: int}}
     */
    public function build(string $asOf, int|array|null $branchId = null, ?Builder $loans = null): array
    {
        return [
            'as_of' => $asOf,
            'aging' => $this->aging->build($asOf, $branchId),
            'records' => $this->records($branchId, $loans),
        ];
    }

    /**
     * @param  int|list<int>|null  $branchId
     * @param  Builder<Loan>|null  $loans
     * @return array{as_of: string, loan_count: int, outstanding_principal: int, total_outstanding: int, past_due_amount: int, past_due_count: int}
     */
    private function records(int|array|null $branchId, ?Builder $loans): array
    {
        $today = Carbon::today();
        $collectible = $this->loanAging->query($today->toDateString(), $branchId, [], $loans);
        $totals = $this->totals->for($collectible, $today);

        return [
            'as_of' => $today->toDateString(),
            'loan_count' => (clone $collectible)->count(),
            'outstanding_principal' => $this->centavos($totals['outstanding_principal']),
            'total_outstanding' => $this->centavos($totals['total_outstanding']),
            'past_due_amount' => $this->centavos($totals['overdue_amount']),
            'past_due_count' => $totals['overdue_count'],
        ];
    }

    private function centavos(float $pesos): int
    {
        $centavos = Money::toCentavos($pesos);

        if ($centavos === null) {
            throw new \RuntimeException("Loan accounting summary: '{$pesos}' is not a convertible peso amount.");
        }

        return $centavos;
    }
}
