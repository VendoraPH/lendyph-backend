<?php

namespace App\Services\Dashboard;

use App\Models\Loan;
use App\Services\LoanListTotals;
use App\Services\PortfolioRisk;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The loans behind the executive dashboard's PAR card (Phase 6 EB-D1): the
 * Portfolio Summary's own PAR loans (PortfolioRisk::parLoans() over the same
 * ever-released loans, branches and product and officer filters), so their
 * count and principal are its `par_n`. PAR's basis is the summary's; a change
 * to it applies here by itself.
 *
 * Each row names the member by id, name and code only (the round fz
 * personal-data rule for loan rows): never contact details, addresses or
 * identity documents.
 */
final class ParLoans
{
    public function __construct(
        private PortfolioRisk $risk,
        private LoanListTotals $listTotals,
    ) {}

    /**
     * @param  list<int>|null  $scope  the branches read
     * @return array{page: LengthAwarePaginator, totals: array{loans: int, outstanding_principal: float}}
     */
    public function page(string $par, ?array $scope, LoanScope $loanScope, int $page, int $perPage): array
    {
        $loans = Loan::query()
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($scope !== null, fn ($q) => $q->whereIn('loans.branch_id', $scope))
            ->tap($loanScope->loans());

        $matching = $this->risk->parLoans($loans, $par);

        $totals = $this->risk->par($loans)[$par];

        $paginator = (clone $matching)
            ->with(['borrower:id,first_name,middle_name,last_name,suffix,borrower_code', 'branch:id,name', 'loanProduct:id,name'])
            ->paginate($perPage, ['*'], 'page', $page);

        $money = $this->listTotals->byLoan($paginator->getCollection()->modelKeys());

        $paginator->setCollection($paginator->getCollection()->map(fn (Loan $loan): array => [
            'loan_id' => $loan->id,
            'account_number' => $loan->loan_account_number,
            'member' => [
                'id' => (int) $loan->borrower_id,
                'name' => $loan->borrower?->full_name,
                'code' => $loan->borrower?->borrower_code,
            ],
            'branch' => $loan->branch === null ? null : ['id' => $loan->branch->id, 'name' => $loan->branch->name],
            'product' => $loan->loanProduct === null ? null : ['id' => $loan->loanProduct->id, 'name' => $loan->loanProduct->name],
            'display_status' => $loan->displayStatus(),
            'oldest_unpaid_due_date' => substr((string) $loan->getAttribute('oldest_unpaid_due'), 0, 10),
            'days_past_due' => (int) $loan->getAttribute('days_past_due'),
            'outstanding_principal' => round((float) $loan->getAttribute('par_principal'), 2),
            'past_due_amount' => $money[$loan->id]['overdue_amount'] ?? 0.0,
            'total_outstanding' => $money[$loan->id]['total_outstanding'] ?? 0.0,
        ]));

        return [
            'page' => $paginator,
            'totals' => ['loans' => $totals['loans'], 'outstanding_principal' => $totals['amount']],
        ];
    }
}
