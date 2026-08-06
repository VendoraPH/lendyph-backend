<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Days past due before a loan counts towards Portfolio at Risk.
     *
     * PAR>30 is the standard cooperative/microfinance measure and is what the
     * dashboard labels the figure ("At Risk (>30d overdue)"), so a schedule due
     * exactly 30 days ago is NOT yet at risk — it is the last day of the 1–30
     * aging bucket.
     */
    private const PAR_THRESHOLD_DAYS = 30;

    public function statementOfAccount(Loan $loan): array
    {
        $loan->load('borrower', 'loanProduct', 'branch', 'amortizationSchedules');

        $repayments = $loan->repayments()
            ->where('status', 'posted')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $totalPaid = $repayments->sum(fn ($r) => (float) $r->amount_paid);
        $schedules = $loan->amortizationSchedules;

        $outstandingPrincipal = $schedules->sum(fn ($s) => max(0, (float) $s->principal_due - (float) $s->principal_paid));
        $outstandingInterest = $schedules->sum(fn ($s) => max(0, (float) $s->interest_due - (float) $s->interest_paid));
        $outstandingPenalty = $schedules->sum(fn ($s) => max(0, (float) $s->penalty_amount - (float) $s->penalty_paid));

        // Build transaction ledger with running balance. The ledger opens at
        // principal + any insurance premium that was not collected at release,
        // so that after the last payment the running balance lands exactly on
        // Loan::$outstanding_balance instead of a figure short by the insurance.
        $openingBalance = round((float) $loan->principal_amount + (float) $loan->insurance_remaining_balance, 2);
        $runningBalance = $openingBalance;

        $transactions = $repayments->map(function ($r) use (&$runningBalance) {
            $runningBalance -= (float) $r->principal_applied;

            return [
                'date' => $r->payment_date->toDateString(),
                'receipt_number' => $r->receipt_number,
                'amount_paid' => (float) $r->amount_paid,
                'principal_applied' => (float) $r->principal_applied,
                'interest_applied' => (float) $r->interest_applied,
                'penalty_applied' => (float) $r->penalty_applied,
                'running_balance' => round(max(0, $runningBalance), 2),
            ];
        })->values()->toArray();

        return [
            'loan' => [
                'loan_account_number' => $loan->loan_account_number,
                'application_number' => $loan->application_number,
                'principal_amount' => (float) $loan->principal_amount,
                'interest_rate' => (float) $loan->interest_rate,
                'interest_method' => $loan->interest_method,
                'term' => $loan->term,
                'frequency' => $loan->frequency,
                'start_date' => $loan->start_date->toDateString(),
                'maturity_date' => $loan->maturity_date->toDateString(),
                'status' => $loan->status,
            ],
            'borrower' => [
                'borrower_code' => $loan->borrower->borrower_code,
                'full_name' => $loan->borrower->full_name,
                'address' => $loan->borrower->address,
            ],
            'transactions' => $transactions,
            'amortization_schedule' => $schedules->map(fn ($s) => [
                'period_number' => $s->period_number,
                'due_date' => $s->due_date->toDateString(),
                'principal_due' => (float) $s->principal_due,
                'interest_due' => (float) $s->interest_due,
                'total_due' => (float) $s->total_due,
                'principal_paid' => (float) $s->principal_paid,
                'interest_paid' => (float) $s->interest_paid,
                'penalty_amount' => (float) $s->penalty_amount,
                'penalty_paid' => (float) $s->penalty_paid,
                'status' => $s->status,
            ])->values()->toArray(),
            'summary' => [
                'total_paid' => round($totalPaid, 2),
                'opening_balance' => $openingBalance,
                'outstanding_principal' => round($outstandingPrincipal, 2),
                'outstanding_interest' => round($outstandingInterest, 2),
                'outstanding_penalty' => round($outstandingPenalty, 2),
                // Canonical principal-side balance (Loan::$outstanding_balance):
                // the closing figure of the running-balance ledger above.
                'principal_balance' => $loan->outstanding_balance,
                // Everything still owed — the amount to settle the loan today.
                'outstanding_balance' => round($outstandingPrincipal + $outstandingInterest + $outstandingPenalty, 2),
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function subsidiaryLedger(Borrower $borrower, array $filters = []): array
    {
        $loans = $borrower->loans()
            ->with('loanProduct', 'amortizationSchedules', 'repayments')
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->get();

        $loanSummaries = $loans->map(function ($loan) use ($filters) {
            $repayments = $loan->repayments->where('status', 'posted');

            if (! empty($filters['date_from'])) {
                $repayments = $repayments->where('payment_date', '>=', Carbon::parse($filters['date_from']));
            }
            if (! empty($filters['date_to'])) {
                $repayments = $repayments->where('payment_date', '<=', Carbon::parse($filters['date_to']));
            }

            return [
                'loan_account_number' => $loan->loan_account_number,
                'product_name' => $loan->loanProduct->name,
                'principal_amount' => (float) $loan->principal_amount,
                'released_at' => $loan->released_at?->toDateString(),
                'maturity_date' => $loan->maturity_date->toDateString(),
                'status' => $loan->status,
                'total_paid' => round($repayments->sum(fn ($r) => (float) $r->amount_paid), 2),
                'outstanding_balance' => $loan->outstanding_balance,
                'payments_count' => $repayments->count(),
            ];
        })->values()->toArray();

        $totalPortfolio = $loans->sum(fn ($l) => (float) $l->principal_amount);
        $totalOutstanding = array_sum(array_column($loanSummaries, 'outstanding_balance'));

        return [
            'borrower' => [
                'borrower_code' => $borrower->borrower_code,
                'full_name' => $borrower->full_name,
                'address' => $borrower->address,
                'contact_number' => $borrower->contact_number,
            ],
            'loans' => $loanSummaries,
            'totals' => [
                'total_loans' => $loans->count(),
                'total_portfolio' => round($totalPortfolio, 2),
                'total_outstanding' => round($totalOutstanding, 2),
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ── List of Releases ─────────────────────────────────────────────────

    /**
     * The single filtered query behind both the Releases preview and its CSV
     * export, so the two can never drift apart.
     */
    public function releasesQuery(array $filters): Builder
    {
        return Loan::query()
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '<=', $d))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('released_at')
            // Tiebreaker: loans released in the same batch share a timestamp,
            // and without a total order both paginated pages and chunked
            // exports can repeat or skip rows.
            ->orderByDesc('id');
    }

    public function listOfReleases(array $filters): LengthAwarePaginator
    {
        return $this->releasesQuery($filters)
            // `amortizationSchedules` is what makes outstanding_balance real —
            // without it every row serialised as 0.
            ->with('borrower', 'branch', 'loanProduct', 'amortizationSchedules')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Totals over the WHOLE filtered set, not the page the client happened to
     * fetch — summing a capped page silently under-reports past that cap.
     *
     * @return array<string, float|int>
     */
    public function listOfReleasesTotals(array $filters): array
    {
        $base = $this->releasesQuery($filters)->reorder();

        $agg = (clone $base)->selectRaw('
            COUNT(*) as row_count,
            COALESCE(SUM(principal_amount), 0) as total_principal,
            COALESCE(SUM(net_proceeds), 0) as total_net_proceeds,
            COALESCE(SUM(insurance_remaining_balance), 0) as total_insurance_remaining
        ')->first();

        $remainingPrincipal = (float) DB::table('amortization_schedules')
            ->whereIn('loan_id', (clone $base)->select('loans.id'))
            ->selectRaw('COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) as balance')
            ->value('balance');

        return [
            'count' => (int) ($agg->row_count ?? 0),
            'total_principal' => round((float) ($agg->total_principal ?? 0), 2),
            'total_net_proceeds' => round((float) ($agg->total_net_proceeds ?? 0), 2),
            'total_outstanding_balance' => round(
                $remainingPrincipal + (float) ($agg->total_insurance_remaining ?? 0),
                2,
            ),
        ];
    }

    // ── List of Repayments ───────────────────────────────────────────────

    /**
     * The single filtered query behind both the Repayments preview and its CSV
     * export.
     *
     * Defaults to posted payments: a voided receipt is not income, and neither
     * the reports page nor the export ever sent a status, so voided money was
     * being counted as collected.
     */
    public function repaymentsQuery(array $filters): Builder
    {
        return Repayment::query()
            ->where('status', $filters['status'] ?? 'posted')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->when($filters['loan_id'] ?? null, fn ($q, $l) => $q->where('loan_id', $l))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->whereHas('loan', fn ($lq) => $lq->where('branch_id', $b)))
            ->latest('payment_date')
            // `payment_date` is a DATE, so same-day receipts all tie — see the
            // note on releasesQuery().
            ->orderByDesc('id');
    }

    public function listOfRepayments(array $filters): LengthAwarePaginator
    {
        return $this->repaymentsQuery($filters)
            ->with('loan.borrower', 'loan.branch', 'receivedByUser')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * @return array<string, float|int>
     */
    public function listOfRepaymentsTotals(array $filters): array
    {
        $agg = $this->repaymentsQuery($filters)->reorder()->selectRaw('
            COUNT(*) as row_count,
            COALESCE(SUM(amount_paid), 0) as total_amount_paid,
            COALESCE(SUM(principal_applied), 0) as total_principal_applied,
            COALESCE(SUM(interest_applied), 0) as total_interest_applied,
            COALESCE(SUM(penalty_applied), 0) as total_penalty_applied
        ')->first();

        return [
            'count' => (int) ($agg->row_count ?? 0),
            'total_amount_paid' => round((float) ($agg->total_amount_paid ?? 0), 2),
            'total_principal_applied' => round((float) ($agg->total_principal_applied ?? 0), 2),
            'total_interest_applied' => round((float) ($agg->total_interest_applied ?? 0), 2),
            'total_penalty_applied' => round((float) ($agg->total_penalty_applied ?? 0), 2),
        ];
    }

    // ── List of Due / Past Due ───────────────────────────────────────────

    /**
     * The single filtered query behind both the Due/Past Due preview and its
     * CSV export.
     *
     * Preview and export used to build their own queries with different loan
     * statuses and different cutoffs (`Carbon::today()` vs `now()`), so the CSV
     * a manager downloaded did not match the screen they downloaded it from.
     */
    public function duePastDueQuery(array $filters): Builder
    {
        return AmortizationSchedule::query()
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::COLLECTIBLE_STATUSES))
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereDate('due_date', '<=', Carbon::today()->toDateString())
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '<=', $d))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->whereHas('loan', fn ($lq) => $lq->where('branch_id', $b)))
            ->orderBy('due_date')
            ->orderBy('id');
    }

    public function listOfDuePastDue(array $filters): LengthAwarePaginator
    {
        return $this->duePastDueQuery($filters)
            ->with('loan.borrower', 'loan.branch')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * @return array<string, float|int>
     */
    public function listOfDuePastDueTotals(array $filters): array
    {
        $base = $this->duePastDueQuery($filters)->reorder();

        $agg = (clone $base)->selectRaw('
            COUNT(*) as row_count,
            COALESCE(SUM(principal_due), 0) as total_principal_due,
            COALESCE(SUM(interest_due), 0) as total_interest_due,
            COALESCE(SUM(penalty_amount), 0) as total_penalty,
            COALESCE(SUM(total_due), 0) as total_due,
            COALESCE(SUM('.AmortizationSchedule::remainingTotalSql().'), 0) as total_balance
        ')->first();

        $overdueCount = (clone $base)
            ->whereDate('due_date', '<', Carbon::today()->toDateString())
            ->count();

        return [
            'count' => (int) ($agg->row_count ?? 0),
            'overdue_count' => $overdueCount,
            'total_principal_due' => round((float) ($agg->total_principal_due ?? 0), 2),
            'total_interest_due' => round((float) ($agg->total_interest_due ?? 0), 2),
            'total_penalty' => round((float) ($agg->total_penalty ?? 0), 2),
            'total_due' => round((float) ($agg->total_due ?? 0), 2),
            'total_balance' => round((float) ($agg->total_balance ?? 0), 2),
        ];
    }

    // ── Loan Balance Summary ─────────────────────────────────────────────

    public function loanBalanceSummary(array $filters): array
    {
        $today = Carbon::today()->toDateString();
        $parCutoff = Carbon::today()->subDays(self::PAR_THRESHOLD_DAYS)->toDateString();

        $query = Loan::query()
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '<=', $d));

        $loanIds = (clone $query)->select('loans.id');

        $scheduleAgg = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->selectRaw('
                COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) as outstanding_principal,
                COALESCE(SUM('.AmortizationSchedule::remainingInterestSql().'), 0) as outstanding_interest,
                COALESCE(SUM('.AmortizationSchedule::remainingPenaltySql().'), 0) as outstanding_penalty
            ')
            ->first();

        $overdueAgg = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereDate('due_date', '<', $today)
            ->selectRaw('
                COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) as overdue_principal,
                COALESCE(SUM('.AmortizationSchedule::remainingInterestSql().'), 0) as overdue_interest,
                COALESCE(SUM('.AmortizationSchedule::remainingPenaltySql().'), 0) as overdue_penalty,
                COUNT(DISTINCT loan_id) as overdue_loan_count
            ')
            ->first();

        $portfolio = (clone $query)
            ->selectRaw('
                COUNT(*) as loan_count,
                COALESCE(SUM(principal_amount), 0) as total_released,
                COALESCE(SUM(insurance_remaining_balance), 0) as insurance_remaining
            ')
            ->first();

        $activeLoans = (clone $query)->whereIn('status', ['released', 'ongoing'])->count();

        // Portfolio at Risk — the outstanding balance of every loan carrying at
        // least one schedule more than PAR_THRESHOLD_DAYS past due, over total
        // outstanding. The numerator is the loan's WHOLE remaining balance, not
        // just the late instalment; that is what makes it "at risk".
        $atRiskLoanIds = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereDate('due_date', '<', $parCutoff)
            ->select('loan_id');

        $atRiskAmount = (float) DB::table('amortization_schedules')
            ->whereIn('loan_id', $atRiskLoanIds)
            ->selectRaw('COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) as balance')
            ->value('balance');

        $outstandingPrincipal = round((float) ($scheduleAgg->outstanding_principal ?? 0), 2);
        $outstandingBalance = round($outstandingPrincipal + (float) ($portfolio->insurance_remaining ?? 0), 2);

        return [
            'portfolio' => [
                'loan_count' => (int) ($portfolio->loan_count ?? 0),
                'active_loan_count' => $activeLoans,
                'total_released' => round((float) ($portfolio->total_released ?? 0), 2),
            ],
            // Flat mirrors of the figures the dashboard shows as headline KPIs,
            // so the client is not left deriving them from the nested blocks.
            'active_loans' => $activeLoans,
            'outstanding_balance' => $outstandingBalance,
            'at_risk_amount' => round($atRiskAmount, 2),
            // Whole percent, e.g. 12.5 means 12.5%.
            'par_ratio' => $outstandingPrincipal > 0
                ? round($atRiskAmount / $outstandingPrincipal * 100, 2)
                : 0.0,
            'par_threshold_days' => self::PAR_THRESHOLD_DAYS,
            'outstanding' => [
                'principal' => $outstandingPrincipal,
                'interest' => round((float) ($scheduleAgg->outstanding_interest ?? 0), 2),
                'penalty' => round((float) ($scheduleAgg->outstanding_penalty ?? 0), 2),
                'insurance' => round((float) ($portfolio->insurance_remaining ?? 0), 2),
                'balance' => $outstandingBalance,
            ],
            'overdue' => [
                'principal' => round((float) ($overdueAgg->overdue_principal ?? 0), 2),
                'interest' => round((float) ($overdueAgg->overdue_interest ?? 0), 2),
                'penalty' => round((float) ($overdueAgg->overdue_penalty ?? 0), 2),
                'loan_count' => (int) ($overdueAgg->overdue_loan_count ?? 0),
            ],
            'by_branch' => $this->balanceSummaryByBranch($filters),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Per-branch portfolio and outstanding balance in ONE grouped query.
     *
     * This used to run two extra queries per branch and — worse — ignored both
     * `branch_id` and the date filters when computing the balance, so a branch's
     * outstanding never reconciled with the `total_released` printed beside it.
     * The schedule aggregate is pre-grouped per loan in a derived table so the
     * join cannot multiply `principal_amount` by the number of schedules.
     *
     * @return array<int, array<string, mixed>>
     */
    private function balanceSummaryByBranch(array $filters): array
    {
        $scheduleTotals = DB::table('amortization_schedules')
            ->groupBy('loan_id')
            ->selectRaw('loan_id, SUM('.AmortizationSchedule::remainingPrincipalSql().') as remaining_principal');

        return DB::table('loans')
            ->join('branches', 'loans.branch_id', '=', 'branches.id')
            ->leftJoinSub($scheduleTotals, 'schedule_totals', 'schedule_totals.loan_id', '=', 'loans.id')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('loans.released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('loans.released_at', '<=', $d))
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('
                branches.id as branch_id,
                branches.name as branch_name,
                COUNT(loans.id) as loan_count,
                COALESCE(SUM(loans.principal_amount), 0) as total_released,
                COALESCE(SUM(schedule_totals.remaining_principal), 0)
                    + COALESCE(SUM(loans.insurance_remaining_balance), 0) as outstanding_balance
            ')
            ->get()
            ->map(fn ($branch) => [
                'branch_id' => (int) $branch->branch_id,
                'branch_name' => $branch->branch_name,
                'loan_count' => (int) $branch->loan_count,
                'total_released' => round((float) $branch->total_released, 2),
                'outstanding_balance' => round((float) $branch->outstanding_balance, 2),
            ])->toArray();
    }

    // ── Daily Collection ─────────────────────────────────────────────────

    public function dailyCollection(array $filters): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $branchId = $filters['branch_id'] ?? null;

        // Both sides of the ratio must be scoped identically. Filtering only the
        // denominator by loan status or branch is how the collection rate ends
        // up above 100%: payments counted against amounts that were never in
        // the total due.
        $loanScope = fn ($q) => $q
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($lq, $b) => $lq->where('branch_id', $b));

        $totalDue = (float) AmortizationSchedule::query()
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('loan', $loanScope)
            ->sum('total_due');

        $totalCollected = (float) Repayment::query()
            ->where('status', 'posted')
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('loan', $loanScope)
            ->sum('amount_paid');

        return [
            // `date` is the as-of (end) date, kept for callers that predate the
            // range filter.
            'date' => $to->toDateString(),
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_due' => round($totalDue, 2),
            'total_collected' => round($totalCollected, 2),
            // Whole percent, e.g. 12.5 means 12.5%.
            'collection_rate' => $totalDue > 0 ? round($totalCollected / $totalDue * 100, 2) : 0.0,
            'uncollected' => round(max(0, $totalDue - $totalCollected), 2),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function incomeReport(array $filters): array
    {
        $branchId = $filters['branch_id'] ?? null;

        $query = Repayment::where('status', 'posted')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->when($branchId, fn ($q, $b) => $q->whereHas('loan', fn ($lq) => $lq->where('branch_id', $b)));

        $interestIncome = (float) (clone $query)->sum('interest_applied');
        $penaltyIncome = (float) (clone $query)->sum('penalty_applied');

        $processingFees = (float) DB::table('loans')
            ->join('loan_products', 'loans.loan_product_id', '=', 'loan_products.id')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('loans.released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('loans.released_at', '<=', $d))
            ->selectRaw('SUM(loan_products.processing_fee / 100 * loans.principal_amount) as total')
            ->value('total') ?? 0;

        $total = $interestIncome + $processingFees + $penaltyIncome;

        return [
            'interest_income' => round($interestIncome, 2),
            'processing_fees' => round($processingFees, 2),
            'penalty_income' => round($penaltyIncome, 2),
            'total' => round($total, 2),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ── Aging ────────────────────────────────────────────────────────────

    public function agingReport(array $filters): array
    {
        $asOf = $this->resolveAsOfDate($filters);

        /**
         * Inclusive day-count boundaries, expressed as days past due.
         *
         * These must be DISJOINT and must exclude anything not yet late. The
         * old query anchored on `subDays($minDays - 1)`, which put a schedule
         * due today (0 days overdue) in "1–30" and counted anything due exactly
         * 30 / 60 / 90 days ago in two buckets at once, so the buckets never
         * summed to total overdue.
         *
         * @var array<string, array{0: int, 1: int|null}>
         */
        $buckets = [
            '1_30' => [1, 30],
            '31_60' => [31, 60],
            '61_90' => [61, 90],
            'over_90' => [91, null],
        ];

        $result = [];
        foreach ($buckets as $key => [$minDays, $maxDays]) {
            $query = $this->overdueScheduleQuery($filters, $asOf)
                ->whereDate('due_date', '<=', $asOf->copy()->subDays($minDays)->toDateString());

            if ($maxDays !== null) {
                $query->whereDate('due_date', '>=', $asOf->copy()->subDays($maxDays)->toDateString());
            }

            $result[$key] = [
                // Penalty included, so an overdue peso is counted the same way
                // here as in the Loan Balance Summary's `overdue` block.
                'amount' => round((float) (clone $query)->sum(DB::raw(AmortizationSchedule::remainingTotalSql())), 2),
                // Distinct LOANS touching this bucket. Amounts sum to `total`;
                // counts deliberately do not — one loan with a 20-day-late and
                // a 70-day-late instalment is a delinquent loan in both
                // buckets, and must not be double-counted in the total.
                'count' => (clone $query)->distinct()->count('loan_id'),
            ];
        }

        // Computed independently rather than by adding the buckets up, so that
        // "the buckets sum to the total" is a genuine assertion about the
        // boundaries and not a tautology.
        $overall = $this->overdueScheduleQuery($filters, $asOf)
            ->whereDate('due_date', '<=', $asOf->copy()->subDay()->toDateString());

        return [
            'as_of_date' => $asOf->toDateString(),
            'buckets' => $result,
            'total' => [
                'amount' => round((float) (clone $overall)->sum(DB::raw(AmortizationSchedule::remainingTotalSql())), 2),
                'count' => (clone $overall)->distinct()->count('loan_id'),
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Unpaid schedules on loans that can still owe, as of a given date.
     */
    private function overdueScheduleQuery(array $filters, Carbon $asOf): Builder
    {
        return AmortizationSchedule::query()
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::COLLECTIBLE_STATUSES))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->whereHas('loan', fn ($lq) => $lq->where('branch_id', $b)))
            // Nothing that is not yet late may enter an aging bucket, whatever
            // the bucket boundaries do.
            ->whereDate('due_date', '<', $asOf->toDateString());
    }

    public function borrowerReport(array $filters): array
    {
        $branchId = $filters['branch_id'] ?? null;
        $releasedLoans = fn ($q) => $q
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($lq, $b) => $lq->where('branch_id', $b));

        $totalActive = Borrower::whereHas('loans', $releasedLoans)->count();

        $newBorrowers = Borrower::query()
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->count();

        $avgLoanSize = (float) Loan::whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->avg('principal_amount') ?? 0;

        $repeatBorrowers = Borrower::whereHas('loans', $releasedLoans, '>=', 2)->count();

        return [
            'total_active_borrowers' => $totalActive,
            'new_borrowers' => $newBorrowers,
            'avg_loan_size' => round($avgLoanSize, 2),
            'repeat_borrowers' => $repeatBorrowers,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function disbursementReport(array $filters): array
    {
        $query = Loan::whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '<=', $d))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b));

        $loansReleased = (clone $query)->count();
        $totalDisbursed = (float) (clone $query)->sum('net_proceeds');
        $avgDisbursement = $loansReleased > 0 ? round($totalDisbursed / $loansReleased, 2) : 0;

        $pendingRelease = Loan::where('status', 'approved')
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->count();

        return [
            'loans_released' => $loansReleased,
            'total_disbursed' => round($totalDisbursed, 2),
            'avg_disbursement' => $avgDisbursement,
            'pending_release' => $pendingRelease,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ── Filter resolution ────────────────────────────────────────────────

    /**
     * The date a point-in-time report is run "as of".
     *
     * The reports page sends `date_from`/`date_to`; `as_of_date` and `date` are
     * the original single-date parameters and still work.
     */
    public function resolveAsOfDate(array $filters): Carbon
    {
        foreach (['date_to', 'as_of_date', 'date'] as $key) {
            if (! empty($filters[$key])) {
                return Carbon::parse($filters[$key])->startOfDay();
            }
        }

        return Carbon::today();
    }

    /**
     * Resolve a reporting period from whichever parameters the caller sent.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(array $filters): array
    {
        $legacy = ! empty($filters['date']) ? Carbon::parse($filters['date'])->startOfDay() : null;

        $from = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : ($legacy ?? Carbon::today());

        $to = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->startOfDay()
            : ($legacy ?? Carbon::today());

        return $to->lt($from) ? [$to, $from] : [$from, $to];
    }
}
