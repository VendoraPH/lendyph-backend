<?php

namespace App\Services;

use App\Models\Loan;

/**
 * What the collectible loans (released, ongoing, defaulted) still owe, by
 * LoanListTotals' rule so it agrees with the loan pages to the centavo: the one
 * query behind the Portfolio Summary's `outstanding_principal` and
 * `total_outstanding` (ReportService::loanBalanceSummary()) and the Dashboard's
 * Total Portfolio (DashboardService::stats()), the standard gross loan
 * portfolio. A paid-off loan is not in it, and a defaulted loan's debt is.
 */
final class PortfolioOutstanding
{
    public function __construct(private LoanListTotals $listTotals) {}

    /**
     * `$releasedFrom` is inclusive and `$releasedBefore` exclusive, as day
     * bounds in the app timezone.
     *
     * @param  list<int>|null  $branchIds  null for every branch; an empty list matches nothing
     * @return array{total_outstanding: float, outstanding_principal: float, overdue_amount: float, overdue_count: int}
     */
    public function totals(?array $branchIds, ?string $releasedFrom = null, ?string $releasedBefore = null): array
    {
        return $this->listTotals->for(
            Loan::query()
                ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
                ->when($branchIds !== null, fn ($q) => $q->whereIn('loans.branch_id', $branchIds))
                ->when($releasedFrom !== null, fn ($q) => $q->where('loans.released_at', '>=', $releasedFrom))
                ->when($releasedBefore !== null, fn ($q) => $q->where('loans.released_at', '<', $releasedBefore)),
        );
    }
}
