<?php

namespace App\Services\Branches;

use App\Models\Loan;
use App\Services\PortfolioOutstanding;

/**
 * A branch's lending figures for the Branches page (Phase 6 MB-BE1), read
 * only: what its collectible loans (released, ongoing, defaulted) still owe,
 * from PortfolioOutstanding, the query behind the Portfolio Summary and the
 * Dashboard's Total Portfolio, so the figures agree with them to the
 * centavo. Active members are members with a collectible loan, the Member
 * Report's rule.
 *
 * Counts come from one grouped query; the totals take one PortfolioOutstanding
 * call per branch (a cooperative has a handful of branches).
 */
class BranchFigures
{
    public function __construct(private PortfolioOutstanding $portfolio) {}

    /**
     * @param  list<int>  $branchIds
     * @return array<int, array{active_loans: int, active_members: int, total_outstanding: float, outstanding_principal: float, overdue_amount: float, overdue_count: int}>
     */
    public function for(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $counts = Loan::query()
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->whereIn('loans.branch_id', $branchIds)
            ->groupBy('loans.branch_id')
            ->selectRaw('loans.branch_id, count(*) as loans, count(distinct loans.borrower_id) as members')
            ->get()
            ->keyBy('branch_id');

        $figures = [];

        foreach ($branchIds as $id) {
            $totals = $this->portfolio->totals([$id]);
            $row = $counts->get($id);

            $figures[$id] = [
                'active_loans' => (int) ($row->loans ?? 0),
                'active_members' => (int) ($row->members ?? 0),
                'total_outstanding' => $totals['total_outstanding'],
                'outstanding_principal' => $totals['outstanding_principal'],
                'overdue_amount' => $totals['overdue_amount'],
                'overdue_count' => $totals['overdue_count'],
            ];
        }

        return $figures;
    }
}
