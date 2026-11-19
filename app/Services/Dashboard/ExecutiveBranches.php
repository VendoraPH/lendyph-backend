<?php

namespace App\Services\Dashboard;

use App\Models\Loan;
use App\Models\User;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\IncomeStatementBuilder;
use App\Services\LoanListTotals;
use App\Services\PortfolioRisk;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The executive dashboard's branch table (Phase 6 EB-D1): one row per branch
 * in the caller's scope, each figure from the same service as its card, so a
 * row equals that branch's report and the rows add up to the cards.
 *
 * - Lending figures count by the LOAN's branch: the Portfolio Summary's
 *   `by_branch` rows, its past due (LoanListTotals, grace rule) and PAR
 *   (PortfolioRisk) grouped by branch over the same loans, Cash Flow's
 *   `by_branch` rows (Releases as principal, Collections without the premium)
 *   and Collection Efficiency's.
 * - The books (revenue, expenses, net) count by the JOURNAL's branch
 *   (IncomeStatementBuilder::byJournalBranch()), with a "No branch" row for
 *   entries that carry none. They need `accounting:view`; the "No branch" row
 *   also needs every branch (`branches:view_all`, the accounting branch
 *   rule), and has no lending figures, since every loan has a branch.
 *
 * Balances are as of today (`balances_as_of`); period figures cover the
 * period. Cached per scope, filters, period and day; the books' permission
 * is applied after the cache.
 */
final class ExecutiveBranches
{
    public const NO_BRANCH = 'No branch';

    public function __construct(
        private ReportService $reports,
        private LoanListTotals $listTotals,
        private PortfolioRisk $risk,
        private IncomeStatementBuilder $incomeStatement,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  ExecutiveDashboardRequest::reportFilters()
     * @param  list<int>|null  $scope  the branches read
     * @return array<string, mixed>
     */
    public function build(User $user, DashboardPeriod $period, array $filters, ?array $scope): array
    {
        $key = 'dashboard:executive:branches:v1:'.sha1((string) json_encode([
            DashboardPeriod::today()->toDateString(),
            $period->toArray(),
            $scope,
            $filters['product_id'] ?? null,
            $filters['officer_id'] ?? null,
            $filters['collector_id'] ?? null,
        ]));

        $table = Cache::remember($key, ExecutiveOverview::CACHE_SECONDS, fn (): array => $this->table($period, $filters, $scope));

        if ($table['books_state'] === 'ready' && ! $user->can('accounting:view')) {
            $table['books_state'] = 'no_access';
        }

        if ($table['books_state'] !== 'ready') {
            $table['rows'] = array_values(array_map(
                fn (array $row): array => [...$row, 'revenue' => null, 'expenses' => null, 'net' => null],
                array_filter($table['rows'], fn (array $row): bool => $row['branch_id'] !== null),
            ));
            $table['totals'] = [...$table['totals'], 'revenue' => null, 'expenses' => null, 'net' => null];
        }

        return [
            'period' => $period->toArray(),
            'filters_applied' => LoanScope::fromFilters($filters)->applied(['product_id', 'officer_id', 'collector_id'])['filters_applied'],
            ...$table,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $scope
     * @return array{balances_as_of: string, books_state: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function table(DashboardPeriod $period, array $filters, ?array $scope): array
    {
        $dates = ['date_from' => $period->from->toDateString(), 'date_to' => $period->to->toDateString()];
        $loanScope = LoanScope::fromFilters($filters);

        $summary = $this->reports->loanBalanceSummary($filters);
        $flow = $this->reports->cashFlow([...$filters, ...$dates]);
        $efficiency = $this->reports->collectionEfficiency([...$filters, ...$dates]);

        $pastDue = $this->listTotals->grouped($this->loans(Loan::COLLECTIBLE_STATUSES, $scope, $loanScope), ['loans.branch_id']);
        $par = $this->risk->parBy($this->loans(Loan::EVER_RELEASED_STATUSES, $scope, $loanScope), ['loans.branch_id']);
        $flowRows = collect($flow['by_branch'])->filter(fn (array $row): bool => $row['branch_id'] !== null)->keyBy('branch_id');
        $efficiencyRows = collect($efficiency['by_branch'])->keyBy('branch_id');

        $booksReady = AccountMap::chartExists();
        $books = $booksReady ? $this->incomeStatement->byJournalBranch($dates['date_from'], $dates['date_to'], $scope) : [];
        $bookFigures = fn (int|string $key): array => [
            'revenue' => ($books[$key]['total_income'] ?? 0) / 100,
            'expenses' => ($books[$key]['total_expenses'] ?? 0) / 100,
            'net' => ($books[$key]['net_income'] ?? 0) / 100,
        ];
        $noPar = ['amount' => 0.0, 'ratio' => 0.0, 'loans' => 0];

        $rows = [];
        foreach ($summary['by_branch'] as $branch) {
            $id = (int) $branch['branch_id'];
            $cash = $flowRows->get($id);

            $rows[] = [
                'branch_id' => $id,
                'branch_name' => $branch['branch_name'],
                'gross_loan_portfolio' => $branch['outstanding_principal'],
                'total_outstanding' => $branch['total_outstanding'],
                'active_loans' => $branch['active_loan_count'],
                'past_due_amount' => $pastDue[(string) $id]['overdue_amount'] ?? 0.0,
                'past_due_loans' => $pastDue[(string) $id]['overdue_count'] ?? 0,
                'par_30' => $par[(string) $id]['par_30'] ?? $noPar,
                'loans_released' => $cash === null ? 0.0 : round($cash['outflow_net_proceeds'] + $cash['total_deductions'], 2),
                'collections' => $cash === null ? 0.0 : round($cash['inflow_repayments'] - $cash['inflow_insurance'], 2),
                'collected_insurance' => $cash['inflow_insurance'] ?? 0.0,
                'collection_efficiency' => $efficiencyRows->get($id)['collection_rate'] ?? 0.0,
                ...$bookFigures($id),
            ];
        }

        if ($booksReady && $scope === null) {
            $rows[] = [
                'branch_id' => null,
                'branch_name' => self::NO_BRANCH,
                'gross_loan_portfolio' => null,
                'total_outstanding' => null,
                'active_loans' => null,
                'past_due_amount' => null,
                'past_due_loans' => null,
                'par_30' => null,
                'loans_released' => null,
                'collections' => null,
                'collected_insurance' => null,
                'collection_efficiency' => null,
                ...$bookFigures('none'),
            ];
        }

        $statement = $booksReady ? $this->incomeStatement->build($dates['date_from'], $dates['date_to'], $scope) : null;
        $repayments = $flow['inflows']['repayments'];

        return [
            'balances_as_of' => DashboardPeriod::today()->toDateString(),
            'books_state' => $booksReady ? 'ready' : 'requires_configuration',
            'rows' => $rows,
            'totals' => [
                'gross_loan_portfolio' => $summary['outstanding_principal'],
                'total_outstanding' => $summary['total_outstanding'],
                'active_loans' => $summary['active_loans'],
                'past_due_amount' => $summary['past_due_amount'],
                'past_due_loans' => $summary['past_due_loan_count'],
                'par_30' => $summary['par_30'],
                'loans_released' => $flow['non_cash']['principal_released'],
                'collections' => round($repayments['total'] - $repayments['insurance'], 2),
                'collected_insurance' => $repayments['insurance'],
                'collection_efficiency' => $efficiency['collection_rate'],
                'revenue' => $statement === null ? null : $statement['total_income'] / 100,
                'expenses' => $statement === null ? null : $statement['total_expenses'] / 100,
                'net' => $statement === null ? null : $statement['net_income'] / 100,
            ],
        ];
    }

    /**
     * Loans in `$statuses`, in the branches and the product and officer
     * filters: the loans the Portfolio Summary reads for the same request.
     *
     * @param  list<string>  $statuses
     * @param  list<int>|null  $scope
     * @return Builder<Loan>
     */
    private function loans(array $statuses, ?array $scope, LoanScope $loanScope): Builder
    {
        return Loan::query()
            ->whereIn('loans.status', $statuses)
            ->when($scope !== null, fn ($q) => $q->whereIn('loans.branch_id', $scope))
            ->tap($loanScope->loans());
    }
}
