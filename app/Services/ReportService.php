<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReportService
{
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

        // Build transaction ledger with running balance
        $runningBalance = (float) $loan->principal_amount;
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
                'outstanding_principal' => round($outstandingPrincipal, 2),
                'outstanding_interest' => round($outstandingInterest, 2),
                'outstanding_penalty' => round($outstandingPenalty, 2),
                'outstanding_balance' => round($outstandingPrincipal + $outstandingInterest + $outstandingPenalty, 2),
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function subsidiaryLedger(Borrower $borrower, array $filters = []): array
    {
        $loans = $borrower->loans()
            ->with('loanProduct', 'amortizationSchedules', 'repayments')
            ->whereIn('status', ['released', 'ongoing', 'completed'])
            ->get();

        $loanSummaries = $loans->map(function ($loan) use ($filters) {
            $repayments = $loan->repayments->where('status', 'posted');

            if (! empty($filters['date_from'])) {
                $repayments = $repayments->where('payment_date', '>=', Carbon::parse($filters['date_from']));
            }
            if (! empty($filters['date_to'])) {
                $repayments = $repayments->where('payment_date', '<=', Carbon::parse($filters['date_to']));
            }

            $schedules = $loan->amortizationSchedules;
            $outstanding = $schedules->sum(fn ($s) => max(0, (float) $s->principal_due - (float) $s->principal_paid));

            return [
                'loan_account_number' => $loan->loan_account_number,
                'product_name' => $loan->loanProduct->name,
                'principal_amount' => (float) $loan->principal_amount,
                'released_at' => $loan->released_at?->toDateString(),
                'maturity_date' => $loan->maturity_date->toDateString(),
                'status' => $loan->status,
                'total_paid' => round($repayments->sum(fn ($r) => (float) $r->amount_paid), 2),
                'outstanding_balance' => round($outstanding, 2),
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

    public function listOfReleases(array $filters): LengthAwarePaginator
    {
        return Loan::with('borrower', 'branch', 'loanProduct')
            ->whereIn('status', ['released', 'ongoing', 'completed'])
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '<=', $d))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('released_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function listOfRepayments(array $filters): LengthAwarePaginator
    {
        return Repayment::with('loan.borrower', 'loan.branch', 'receivedByUser')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->when($filters['loan_id'] ?? null, fn ($q, $l) => $q->where('loan_id', $l))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->whereHas('loan', fn ($lq) => $lq->where('branch_id', $b)))
            ->latest('payment_date')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function listOfDuePastDue(array $filters): LengthAwarePaginator
    {
        $today = Carbon::today();

        return AmortizationSchedule::with('loan.borrower', 'loan.branch')
            ->whereHas('loan', fn ($q) => $q->where('status', 'released'))
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('due_date', '<=', $today)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '<=', $d))
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->whereHas('loan', fn ($lq) => $lq->where('branch_id', $b)))
            ->orderBy('due_date')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function loanBalanceSummary(array $filters): array
    {
        $query = Loan::query()
            ->whereIn('status', ['released', 'ongoing', 'completed'])
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('released_at', '<=', $d));

        $loanIds = $query->pluck('id');

        // Aggregate from amortization schedules
        $scheduleAgg = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->selectRaw('
                SUM(principal_due) as total_principal_due,
                SUM(interest_due) as total_interest_due,
                SUM(principal_paid) as total_principal_paid,
                SUM(interest_paid) as total_interest_paid,
                SUM(penalty_amount) as total_penalty_due,
                SUM(penalty_paid) as total_penalty_paid
            ')
            ->first();

        $overdueAgg = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('due_date', '<', Carbon::today())
            ->selectRaw('
                SUM(principal_due - principal_paid) as overdue_principal,
                SUM(interest_due - interest_paid) as overdue_interest,
                SUM(penalty_amount - penalty_paid) as overdue_penalty,
                COUNT(DISTINCT loan_id) as overdue_loan_count
            ')
            ->first();

        $portfolio = $query->selectRaw('COUNT(*) as loan_count, SUM(principal_amount) as total_released')->first();

        // Per-branch breakdown
        $byBranch = DB::table('loans')
            ->join('branches', 'loans.branch_id', '=', 'branches.id')
            ->whereIn('loans.status', ['released', 'ongoing', 'completed'])
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('loans.released_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('loans.released_at', '<=', $d))
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('
                branches.id as branch_id,
                branches.name as branch_name,
                COUNT(loans.id) as loan_count,
                SUM(loans.principal_amount) as total_released
            ')
            ->get()
            ->map(function ($branch) {
                $branchLoanIds = Loan::where('branch_id', $branch->branch_id)
                    ->whereIn('status', ['released', 'ongoing', 'completed'])
                    ->pluck('id');

                $outstanding = DB::table('amortization_schedules')
                    ->whereIn('loan_id', $branchLoanIds)
                    ->selectRaw('SUM(principal_due - principal_paid) as outstanding')
                    ->value('outstanding') ?? 0;

                return [
                    'branch_id' => $branch->branch_id,
                    'branch_name' => $branch->branch_name,
                    'loan_count' => $branch->loan_count,
                    'total_released' => round((float) $branch->total_released, 2),
                    'outstanding_balance' => round((float) $outstanding, 2),
                ];
            })->toArray();

        return [
            'portfolio' => [
                'loan_count' => $portfolio->loan_count ?? 0,
                'total_released' => round((float) ($portfolio->total_released ?? 0), 2),
            ],
            'outstanding' => [
                'principal' => round((float) ($scheduleAgg->total_principal_due ?? 0) - (float) ($scheduleAgg->total_principal_paid ?? 0), 2),
                'interest' => round((float) ($scheduleAgg->total_interest_due ?? 0) - (float) ($scheduleAgg->total_interest_paid ?? 0), 2),
                'penalty' => round((float) ($scheduleAgg->total_penalty_due ?? 0) - (float) ($scheduleAgg->total_penalty_paid ?? 0), 2),
            ],
            'overdue' => [
                'principal' => round((float) ($overdueAgg->overdue_principal ?? 0), 2),
                'interest' => round((float) ($overdueAgg->overdue_interest ?? 0), 2),
                'penalty' => round((float) ($overdueAgg->overdue_penalty ?? 0), 2),
                'loan_count' => $overdueAgg->overdue_loan_count ?? 0,
            ],
            'by_branch' => $byBranch,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function dailyCollection(array $filters): array
    {
        $date = isset($filters['date']) ? Carbon::parse($filters['date']) : Carbon::today();

        $totalDue = (float) AmortizationSchedule::whereDate('due_date', $date)
            ->whereHas('loan', fn ($q) => $q->where('status', 'released'))
            ->sum('total_due');

        $totalCollected = (float) Repayment::where('status', 'posted')
            ->whereDate('payment_date', $date)
            ->sum('amount_paid');

        $collectionRate = $totalDue > 0 ? round($totalCollected / $totalDue * 100, 1) : 0;

        return [
            'date' => $date->toDateString(),
            'total_due' => round($totalDue, 2),
            'total_collected' => round($totalCollected, 2),
            'collection_rate' => $collectionRate,
            'uncollected' => round(max(0, $totalDue - $totalCollected), 2),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function incomeReport(array $filters): array
    {
        $query = Repayment::where('status', 'posted')
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '<=', $d));

        $interestIncome = (float) (clone $query)->sum('interest_applied');
        $penaltyIncome = (float) (clone $query)->sum('penalty_applied');

        $processingFees = (float) DB::table('loans')
            ->join('loan_products', 'loans.loan_product_id', '=', 'loan_products.id')
            ->whereIn('loans.status', ['released', 'ongoing', 'completed'])
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

    public function agingReport(array $filters): array
    {
        $asOf = isset($filters['as_of_date']) ? Carbon::parse($filters['as_of_date']) : Carbon::today();

        $buckets = [
            '1_30' => [1, 30],
            '31_60' => [31, 60],
            '61_90' => [61, 90],
            'over_90' => [91, null],
        ];

        $result = [];
        foreach ($buckets as $key => [$minDays, $maxDays]) {
            $query = AmortizationSchedule::whereIn('status', ['pending', 'partial', 'overdue'])
                ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->whereHas('loan', fn ($lq) => $lq->where('branch_id', $b)))
                ->whereHas('loan', fn ($q) => $q->where('status', 'released'))
                ->where('due_date', '<=', $asOf->copy()->subDays($minDays - 1));

            if ($maxDays !== null) {
                $query->where('due_date', '>=', $asOf->copy()->subDays($maxDays));
            }

            $result[$key] = [
                'amount' => round((float) $query->sum(DB::raw('principal_due - principal_paid + (interest_due - interest_paid)')), 2),
                'count' => $query->distinct('loan_id')->count('loan_id'),
            ];
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'buckets' => $result,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public function borrowerReport(array $filters): array
    {
        $totalActive = Borrower::whereHas('loans', fn ($q) => $q->whereIn('status', ['released', 'ongoing', 'completed']))->count();

        $newBorrowers = Borrower::query()
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->count();

        $avgLoanSize = (float) Loan::whereIn('status', ['released', 'ongoing', 'completed'])->avg('principal_amount') ?? 0;

        $repeatBorrowers = Borrower::whereHas('loans', fn ($q) => $q->whereIn('status', ['released', 'ongoing', 'completed']), '>=', 2)->count();

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
        $query = Loan::whereIn('status', ['released', 'ongoing', 'completed'])
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
}
