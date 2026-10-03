<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

    /**
     * Decimal places an interest-rate figure is reported to: the scale of
     * `loans.interest_rate`. An average over loans that share one rate IS that
     * stored rate, so any fewer places would misreport a 2.1234% product as
     * 2.12%.
     */
    private const RATE_SCALE = 4;

    /**
     * Borrower::full_name in SQL: first, middle, last and suffix, empty parts
     * dropped (the same expression CollateralRegister orders its groups by).
     */
    private const BORROWER_NAME_SQL = "CONCAT_WS(' ', NULLIF(borrowers.first_name, ''), NULLIF(borrowers.middle_name, ''), NULLIF(borrowers.last_name, ''), NULLIF(borrowers.suffix, ''))";

    /**
     * Loan-loss provision rate per aging bucket, keyed exactly as
     * {@see self::agingReport()} keys its buckets.
     *
     * These are POLICY, not arithmetic: 5 / 15 / 25 / 50 % is the conventional
     * cooperative allowance ladder, but every board may set its own. They live
     * in a class constant only because there is no settings table for them
     * yet — that is where they belong the moment one deployment needs to
     * differ from another.
     *
     * @var array<string, float>
     */
    public const PROVISION_RATES = [
        '1_30' => 0.05,
        '31_60' => 0.15,
        '61_90' => 0.25,
        'over_90' => 0.50,
    ];

    /**
     * Peso figures added in whole centavos, so a total of figures that are each
     * exact to the centavo is exact too.
     *
     * @param  iterable<mixed>  $figures
     */
    private static function sumOfFigures(iterable $figures): float
    {
        $centavos = 0;

        foreach ($figures as $figure) {
            $centavos += (int) round((float) $figure * 100);
        }

        return round($centavos / 100, 2);
    }

    /**
     * `$part` as a percentage of `$whole`, from whole centavos, rounded half up
     * to two places; null when the whole is 0, which has no parts.
     *
     * Not forced to add up to 100 across a set: three equal thirds are 33.33
     * each, which is what each of them is.
     */
    private static function sharePercent(float $part, float $whole): ?float
    {
        $wholeCentavos = (int) round($whole * 100);

        if ($wholeCentavos <= 0) {
            return null;
        }

        $partCentavos = (int) round($part * 100);

        // Hundredths of a percent, rounded half up in integers.
        return intdiv($partCentavos * 10000 * 2 + $wholeCentavos, $wholeCentavos * 2) / 100;
    }

    /**
     * The footer of a report table: each named column of `$rows` added up, a
     * `money` column in whole centavos and a `count` column as an integer. A
     * row missing a figure adds nothing. In the order the columns are named.
     *
     * The server sends these so the screen never adds up a column itself.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, 'money'|'count'>  $columns
     * @return array<string, float|int>
     */
    private static function columnTotals(array $rows, array $columns): array
    {
        $totals = [];

        foreach ($columns as $key => $kind) {
            $values = array_map(fn (array $row): mixed => $row[$key] ?? 0, $rows);

            $totals[$key] = $kind === 'count'
                ? array_sum(array_map('intval', $values))
                : self::sumOfFigures($values);
        }

        return $totals;
    }

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
                // The statement's two-column form: a payment credits the loan.
                'debit' => null,
                'credit' => (float) $r->amount_paid,
                'principal_applied' => (float) $r->principal_applied,
                'interest_applied' => (float) $r->interest_applied,
                'penalty_applied' => (float) $r->penalty_applied,
                'running_balance' => round(max(0, $runningBalance), 2),
            ];
        })->values()->toArray();

        // The demand letter's arrears, worked out here so the letter computes
        // nothing: what each period still owes on each part, never below 0,
        // in whole centavos; how many days late it is today; and whether it
        // is in arrears (due before today with something still owed). A
        // period's `status` is not trusted for that, because it only turns
        // `overdue` when the nightly run reaches it.
        $today = Carbon::today();
        $centavos = fn (mixed $pesos): int => (int) round((float) $pesos * 100);
        $owed = fn (mixed $due, mixed $paid): int => max(0, $centavos($due) - $centavos($paid));

        $schedule = $schedules->map(function ($s) use ($today, $owed) {
            $remaining = [
                'principal' => $owed($s->principal_due, $s->principal_paid),
                'interest' => $owed($s->interest_due, $s->interest_paid),
                'penalty' => $owed($s->penalty_amount, $s->penalty_paid),
            ];
            $amountDue = array_sum($remaining);
            $daysOverdue = $s->due_date->lt($today) ? (int) $s->due_date->diffInDays($today) : 0;

            return [
                'period_number' => $s->period_number,
                'due_date' => $s->due_date->toDateString(),
                'principal_due' => (float) $s->principal_due,
                'interest_due' => (float) $s->interest_due,
                'total_due' => (float) $s->total_due,
                'principal_paid' => (float) $s->principal_paid,
                'interest_paid' => (float) $s->interest_paid,
                // The row's Paid: principal and interest, the two parts
                // `total_due` is made of, as on the Due/Past Due list.
                'amount_paid' => round((float) $s->principal_paid + (float) $s->interest_paid, 2),
                'penalty_amount' => (float) $s->penalty_amount,
                'penalty_paid' => (float) $s->penalty_paid,
                'status' => $s->status,
                'remaining' => array_map(fn (int $part): float|int => $part / 100, $remaining),
                'amount_due' => $amountDue / 100,
                'days_overdue' => $daysOverdue,
                'is_overdue' => $daysOverdue > 0 && $amountDue > 0,
            ];
        })->values()->toArray();

        $arrears = array_values(array_filter($schedule, fn (array $row): bool => $row['is_overdue']));
        $demandTotals = [
            'principal' => self::sumOfFigures(array_column(array_column($arrears, 'remaining'), 'principal')),
            'interest' => self::sumOfFigures(array_column(array_column($arrears, 'remaining'), 'interest')),
            'penalty' => self::sumOfFigures(array_column(array_column($arrears, 'remaining'), 'penalty')),
            'amount_due' => self::sumOfFigures(array_column($arrears, 'amount_due')),
        ];

        return [
            'loan' => [
                'loan_account_number' => $loan->loan_account_number,
                'application_number' => $loan->application_number,
                'principal_amount' => (float) $loan->principal_amount,
                'interest_rate' => (float) $loan->interest_rate,
                'interest_rate_frequency' => $loan->interest_rate_frequency->value,
                'interest_method' => $loan->interest_method,
                'term' => $loan->term,
                'term_unit' => $loan->term_unit->value,
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
            'transaction_totals' => self::columnTotals($transactions, ['debit' => 'money', 'credit' => 'money']),
            'amortization_schedule' => $schedule,
            'schedule_totals' => self::columnTotals($schedule, [
                'principal_due' => 'money',
                'interest_due' => 'money',
                'penalty_amount' => 'money',
                'total_due' => 'money',
                'amount_paid' => 'money',
            ]),
            // Everything in arrears today, the demand letter's total, and its
            // table's column totals over the rows with `is_overdue`.
            'total_demanded' => $demandTotals['amount_due'],
            'demand_totals' => $demandTotals,
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
            // The member ledger card's loans table, each column added up in
            // whole centavos, under the row keys of `loans`.
            'loans_totals' => self::columnTotals($loanSummaries, [
                'principal_amount' => 'money',
                'total_paid' => 'money',
                'payments_count' => 'count',
                'outstanding_balance' => 'money',
            ]),
            'totals' => [
                'total_loans' => $loans->count(),
                'total_portfolio' => round($totalPortfolio, 2),
                // The same figure under the name the ledger's Total Released
                // reads.
                'total_principal' => round($totalPortfolio, 2),
                'total_paid' => self::columnTotals($loanSummaries, ['total_paid' => 'money'])['total_paid'],
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
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('released_at', '>=', self::dayStart($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('released_at', '<', self::nextDayStart($d)))
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
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('payment_date', '>=', self::filterDay($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('payment_date', '<=', self::filterDay($d)))
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
            ->with('loan.borrower', 'loan.branch', 'receivedByUser', 'shareCapitalLedgerEntries')
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
     *
     * This asks what is DUE. It deliberately does NOT apply
     * AmortizationSchedule::pastGraceSql(), and that is not an oversight —
     * grace was reviewed here and rejected.
     *
     * Grace governs when a borrower is penalised and when they are called late;
     * it does not change whether the money is owed. Every loan product carries
     * some grace, so filtering this list on `due_date + grace < today` would
     * empty the entire "Due" half of a report named "Due / Past Due" and hide
     * the current period's receivables from collections. The report already
     * draws that line itself: a row due today is listed with `days_overdue` 0,
     * which is what test_schedule_due_today_is_due_but_not_overdue() pins.
     *
     * Loan::scopePastDue() asks the other question — what is LATE — and does
     * apply grace. The two are meant to differ, and the loans screen's Past Due
     * tab being the smaller set is the intended result. If lateness ever needs
     * to be visible HERE, it belongs in the row payload as a grace-aware
     * `days_overdue` in ReportController, not as a membership filter that
     * deletes rows.
     *
     * All three date bounds are plain comparisons rather than whereDate().
     * `due_date` is a DATE column with no time component to strip, so DATE() is
     * a no-op that only wraps the column and makes it useless as an index range
     * bound — the same reasoning already written into LoanController::index()
     * for `date_from`/`date_to`. This query has no `loan_id` equality to lead
     * with, so the due_date range is the only leg an index can serve.
     */
    public function duePastDueQuery(array $filters): Builder
    {
        return AmortizationSchedule::query()
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::COLLECTIBLE_STATUSES))
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->where('due_date', '<=', Carbon::today()->toDateString())
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d))
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
     * The totals block under the Due/Past Due list.
     *
     * Membership is duePastDueQuery()'s — what is OWED, grace ignored, see the
     * docblock there. `overdue_count` is the one figure in here that asserts
     * LATENESS rather than debt, so it is the one that honours grace.
     *
     * The contract it owes the row payload: `overdue_count` is exactly the
     * number of rows in the same result set whose `days_overdue` is greater
     * than zero. Both sides decide "is this row late?" with
     * AmortizationSchedule::pastGraceSql() / pastGraceCutoff(), so a report
     * cannot show a page of rows all reading 0 days overdue above a totals
     * block claiming several are late, or the reverse.
     *
     * Nothing else in here implies lateness. The principal/interest/due/balance
     * sums are amounts owed, which grace does not move. `total_penalty` sums
     * the stored `penalty_amount`, which RepaymentService::applyPenalties() now
     * leaves at 0 through the grace window — so it follows this definition
     * automatically, through the data, and must not be filtered here as well or
     * the same rule would be applied twice.
     *
     * There is no PAR figure or aging bucket in this block; those live in
     * agingReport() and loanBalanceSummary(), and both deliberately still
     * measure from the bare due date. See the comments there.
     *
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
            COALESCE(SUM(principal_paid + interest_paid), 0) as total_paid,
            COALESCE(SUM('.AmortizationSchedule::remainingTotalSql().'), 0) as total_balance
        ')->first();

        // Late, not merely due: past the due date AND past the loan's grace
        // period. The comparison needs `loans` in scope, which inside a
        // whereHas('loan') it has — `loans` is the subquery's own table and
        // `amortization_schedules` is the correlated outer reference.
        $overdueCount = (clone $base)
            ->whereHas('loan', fn ($q) => $q->whereRaw(
                AmortizationSchedule::pastGraceSql(),
                [Carbon::today()->toDateString()],
            ))
            ->count();

        return [
            'count' => (int) ($agg->row_count ?? 0),
            'overdue_count' => $overdueCount,
            'total_principal_due' => round((float) ($agg->total_principal_due ?? 0), 2),
            'total_interest_due' => round((float) ($agg->total_interest_due ?? 0), 2),
            'total_penalty' => round((float) ($agg->total_penalty ?? 0), 2),
            'total_due' => round((float) ($agg->total_due ?? 0), 2),
            // The rows' Paid column: principal and interest paid, the two parts
            // `total_due` is made of. Penalty paid is not in it, as it is not
            // in any row's figure.
            'total_paid' => round((float) ($agg->total_paid ?? 0), 2),
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
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('released_at', '>=', self::dayStart($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('released_at', '<', self::nextDayStart($d)));

        $loanIds = (clone $query)->select('loans.id');

        $scheduleAgg = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->selectRaw('
                COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) as outstanding_principal,
                COALESCE(SUM('.AmortizationSchedule::remainingInterestSql().'), 0) as outstanding_interest,
                COALESCE(SUM('.AmortizationSchedule::remainingPenaltySql().'), 0) as outstanding_penalty
            ')
            ->first();

        // Also from the bare due date. This block feeds the balance summary's
        // `overdue` figures alongside the PAR ratio below and is read with
        // them, so the two must be struck the same way. See the note on
        // overdueScheduleQuery() for why that is the due date and not grace.
        $overdueAgg = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->where('due_date', '<', $today)
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
        // From the due date, grace deliberately ignored — PAR30 is a prudential
        // ratio struck the same way everywhere it is reported. Same reasoning
        // as the aging buckets in overdueScheduleQuery(); see the comment
        // there, and do not make one grace-aware without the other.
        $atRiskLoanIds = DB::table('amortization_schedules')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->where('due_date', '<', $parCutoff)
            ->select('loan_id');

        $atRiskAmount = (float) DB::table('amortization_schedules')
            ->whereIn('loan_id', $atRiskLoanIds)
            ->selectRaw('COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) as balance')
            ->value('balance');

        $outstandingPrincipal = round((float) ($scheduleAgg->outstanding_principal ?? 0), 2);
        $outstandingBalance = round($outstandingPrincipal + (float) ($portfolio->insurance_remaining ?? 0), 2);
        $outstanding = [
            'principal' => $outstandingPrincipal,
            'interest' => round((float) ($scheduleAgg->outstanding_interest ?? 0), 2),
            'penalty' => round((float) ($scheduleAgg->outstanding_penalty ?? 0), 2),
        ];
        $overdue = [
            'principal' => round((float) ($overdueAgg->overdue_principal ?? 0), 2),
            'interest' => round((float) ($overdueAgg->overdue_interest ?? 0), 2),
            'penalty' => round((float) ($overdueAgg->overdue_penalty ?? 0), 2),
        ];
        $byBranch = $this->balanceSummaryByBranch($filters);

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
            // `total` is principal + interest + penalty, the composition the
            // three lines above it make up; `balance` is the headline figure,
            // principal + insurance.
            'outstanding' => [
                ...$outstanding,
                'insurance' => round((float) ($portfolio->insurance_remaining ?? 0), 2),
                'balance' => $outstandingBalance,
                'total' => self::sumOfFigures($outstanding),
            ],
            'overdue' => [
                ...$overdue,
                'loan_count' => (int) ($overdueAgg->overdue_loan_count ?? 0),
                'total' => self::sumOfFigures($overdue),
            ],
            'by_branch' => $byBranch,
            'by_branch_totals' => self::columnTotals($byBranch, [
                'loan_count' => 'count',
                'total_released' => 'money',
                'outstanding_balance' => 'money',
            ]),
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
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('loans.released_at', '>=', self::dayStart($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('loans.released_at', '<', self::nextDayStart($d)))
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

        // Both sides of the ratio must be scoped identically — the guard, and
        // why it exists, now live in releasedLoanScope() so collectionEfficiency()
        // shares it rather than copying it.
        $loanScope = $this->releasedLoanScope($branchId);

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

    /**
     * Interest and penalty collected in the period, plus processing fees on
     * the loans released in it. `branch_id` and `loan_id` narrow every figure:
     * the repayments through incomeRepaymentsQuery(), the fees by the loan
     * they were charged on. See processingFeesOfReleasedLoans() for which fee
     * figure each loan contributes.
     */
    public function incomeReport(array $filters): array
    {
        $query = $this->incomeRepaymentsQuery($filters);

        $interestIncome = (float) (clone $query)->sum('interest_applied');
        $penaltyIncome = (float) (clone $query)->sum('penalty_applied');

        $processingFees = $this->processingFeesOfReleasedLoans($filters);

        $interestIncome = round($interestIncome, 2);
        $processingFees = round($processingFees, 2);
        $penaltyIncome = round($penaltyIncome, 2);

        return [
            'interest_income' => $interestIncome,
            'processing_fees' => $processingFees,
            'penalty_income' => $penaltyIncome,
            // The three figures above, already rounded, added up: Total Income
            // is what a reader adds up from the report, never a centavo off it
            // because the unrounded fees were rounded only once, at the end.
            'total' => round($interestIncome + $processingFees + $penaltyIncome, 2),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * The processing fees withheld on the loans released in the period, as
     * they were actually deducted, unrounded.
     *
     * A loan's `deductions` is the record of what was withheld at release, so
     * its fee is the peso `amount` of every item whose name normalises to
     * "processing fee" (FeeOverlapDetector's rule, so "PROCESSING-FEE." counts
     * and "Processing Fee Waiver" does not). That covers the item
     * LoanService::createLoan() writes from the product, a catalog fee
     * LoanReleaseFeeService appends at release under that name, and a CSV
     * import's. `amount` is pesos in all three; `original_value` is the rate
     * for a percentage item and is never read here. A loan that recorded its
     * deductions without such an item, `[]` included, withheld no processing
     * fee and contributes nothing.
     *
     * A loan with no record falls back to the product's rate × principal, as
     * this report always computed it: `deductions` NULL (SQL or JSON), or not
     * a list of items, or an empty list beside a non-zero `total_deductions`,
     * which contradicts itself. The rate is not rounded; the caller rounds the
     * total once, as before.
     *
     * Two reads with the same filters. The fallback stays one SQL SUM, so its
     * decimal arithmetic is exactly what the report produced before. The
     * recorded items are streamed in pages and matched in PHP, so the name test
     * is FeeOverlapDetector's own and not a second copy of it in SQL; their
     * amounts are already whole centavos and are summed as integers.
     */
    private function processingFeesOfReleasedLoans(array $filters): float
    {
        $releasedLoans = DB::table('loans')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->when($filters['loan_id'] ?? null, fn ($q, $l) => $q->where('loans.id', $l))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('loans.released_at', '>=', self::dayStart($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('loans.released_at', '<', self::nextDayStart($d)));

        $hasItems = "JSON_TYPE(loans.deductions) IN ('ARRAY', 'OBJECT')";

        $unrecorded = (float) ((clone $releasedLoans)
            ->join('loan_products', 'loans.loan_product_id', '=', 'loan_products.id')
            ->where(fn ($q) => $q
                ->whereNull('loans.deductions')
                ->orWhereRaw("NOT ({$hasItems})")
                ->orWhereRaw('(JSON_LENGTH(loans.deductions) = 0 AND loans.total_deductions > 0)'))
            ->selectRaw('SUM(loan_products.processing_fee / 100 * loans.principal_amount) as total')
            ->value('total') ?? 0);

        $detector = app(FeeOverlapDetector::class);
        $recordedCentavos = 0;

        (clone $releasedLoans)
            ->whereRaw($hasItems)
            ->whereRaw('JSON_LENGTH(loans.deductions) > 0')
            ->select(['loans.id', 'loans.deductions'])
            ->lazyById(1000, 'loans.id', 'id')
            ->each(function (object $loan) use ($detector, &$recordedCentavos): void {
                foreach (json_decode($loan->deductions, true) as $item) {
                    if (is_array($item)
                        && is_string($item['name'] ?? null)
                        && $detector->fieldFor($item['name']) === 'processing_fee'
                    ) {
                        $recordedCentavos += (int) round((float) ($item['amount'] ?? 0) * 100);
                    }
                }
            });

        return $unrecorded + $recordedCentavos / 100;
    }

    // ── Income by Loan Account ───────────────────────────────────────────

    /**
     * The repayments incomeReport() sums: posted, paid inside the period, on a
     * loan of the branch, and on the one loan when `loan_id` is given.
     *
     * Built on repaymentsQuery() so the Income report, Income by Loan and the
     * List of Repayments totals can never filter the same request differently.
     * `status` is pinned to posted because income is never a voided receipt.
     */
    private function incomeRepaymentsQuery(array $filters): Builder
    {
        return $this->repaymentsQuery(array_merge($filters, ['status' => 'posted']))
            ->reorder();
    }

    /**
     * One page of loans, each with the interest and penalty its posted
     * repayments brought in during the period.
     *
     * The repayments are grouped in a derived table and joined to `loans`, so
     * the outer query is ungrouped and the paginator counts LOANS, not
     * repayments. Ordered by `total_income` desc, then loan id, a total order
     * so walking the pages never repeats or skips a loan. Each Loan carries
     * `payments`, `interest_income`, `penalty_income` and `total_income` as
     * the database summed them, plus its borrower.
     */
    public function incomeByLoan(array $filters): LengthAwarePaginator
    {
        $perLoan = $this->incomeRepaymentsQuery($filters)
            ->select('repayments.loan_id')
            ->selectRaw('COUNT(*) as payments')
            ->selectRaw('COALESCE(SUM(repayments.interest_applied), 0) as interest_income')
            ->selectRaw('COALESCE(SUM(repayments.penalty_applied), 0) as penalty_income')
            ->selectRaw('COALESCE(SUM(repayments.interest_applied), 0) + COALESCE(SUM(repayments.penalty_applied), 0) as total_income')
            ->groupBy('repayments.loan_id');

        return Loan::query()
            ->joinSub($perLoan, 'per_loan', 'per_loan.loan_id', '=', 'loans.id')
            ->select([
                'loans.id',
                'loans.loan_account_number',
                'loans.borrower_id',
                'per_loan.payments',
                'per_loan.interest_income',
                'per_loan.penalty_income',
                'per_loan.total_income',
            ])
            ->with('borrower:id,first_name,middle_name,last_name,suffix')
            ->orderByDesc('per_loan.total_income')
            ->orderBy('loans.id')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Totals over the WHOLE filtered set. `interest_income` and
     * `penalty_income` are the same sums incomeReport() returns, and
     * `payments` is the List of Repayments `count`, for the same filters.
     *
     * @return array{count: int, payments: int, interest_income: float, penalty_income: float, total_income: float}
     */
    public function incomeByLoanTotals(array $filters): array
    {
        $agg = $this->incomeRepaymentsQuery($filters)->toBase()->selectRaw('
            COUNT(DISTINCT repayments.loan_id) as loan_count,
            COUNT(*) as payment_count,
            COALESCE(SUM(repayments.interest_applied), 0) as interest_income,
            COALESCE(SUM(repayments.penalty_applied), 0) as penalty_income,
            COALESCE(SUM(repayments.interest_applied), 0) + COALESCE(SUM(repayments.penalty_applied), 0) as total_income
        ')->first();

        return [
            'count' => (int) ($agg->loan_count ?? 0),
            'payments' => (int) ($agg->payment_count ?? 0),
            'interest_income' => round((float) ($agg->interest_income ?? 0), 2),
            'penalty_income' => round((float) ($agg->penalty_income ?? 0), 2),
            'total_income' => round((float) ($agg->total_income ?? 0), 2),
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
                ->where('due_date', '<=', $asOf->copy()->subDays($minDays)->toDateString());

            if ($maxDays !== null) {
                $query->where('due_date', '>=', $asOf->copy()->subDays($maxDays)->toDateString());
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
            ->where('due_date', '<=', $asOf->copy()->subDay()->toDateString());

        $total = round((float) (clone $overall)->sum(DB::raw(AmortizationSchedule::remainingTotalSql())), 2);

        // Each bucket's part of the total, so the screen never divides one
        // figure by another.
        foreach ($result as $key => $bucket) {
            $result[$key]['share_percent'] = self::sharePercent($bucket['amount'], $total);
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'buckets' => $result,
            'total' => [
                'amount' => $total,
                'count' => (clone $overall)->distinct()->count('loan_id'),
                'share_percent' => $total > 0 ? 100 : null,
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
            //
            // Measured from the bare due date, and deliberately NOT through
            // AmortizationSchedule::pastGraceSql(). Aging buckets are a
            // prudential portfolio-quality measure and are conventionally
            // struck from the due date, exactly like the 90-day threshold in
            // CheckDefaultedLoans; honouring grace here would shift every
            // bucket boundary by the product's grace period and move reported
            // portfolio quality. That is a provisioning decision, not an
            // engineering one, so it is recorded rather than made. The
            // Due/Past Due list's `overdue_count` DOES honour grace, because it
            // is a collections figure rather than a prudential one — if these
            // two are ever unified, that is the difference to resolve first.
            //
            // Plain comparison, not whereDate(): `due_date` is a DATE column,
            // so DATE() strips nothing and only stops the column serving as an
            // index range bound. This is the root of every aging bucket and of
            // the PAR numerator, which are the heaviest scans in this file and
            // exactly the (loan_id, status, due_date) shape the composite index
            // on amortization_schedules exists to serve.
            ->where('due_date', '<', $asOf->toDateString());
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
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', self::dayStart($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', self::nextDayStart($d)))
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

    // ── Borrowers with Loans Released ────────────────────────────────────

    /**
     * One page of borrowers, each with the loans released to them in the
     * period.
     *
     * The loans releasesQuery() selects are grouped in a derived table and
     * joined to `borrowers`, so the outer query is ungrouped and the paginator
     * counts BORROWERS, not loans. Ordered by the borrower's name as
     * displayed, then borrower id, a total order across pages. Each Borrower
     * carries `loan_count`, `total_principal` and `last_released_at` as the
     * database computed them, and `releasedLoans`: those same loans, oldest
     * release first, loaded for the whole page in one query.
     */
    public function borrowersReleased(array $filters): LengthAwarePaginator
    {
        $perBorrower = $this->releasedLoansQuery($filters)
            ->select('loans.borrower_id')
            ->selectRaw('COUNT(*) as loan_count')
            ->selectRaw('COALESCE(SUM(loans.principal_amount), 0) as total_principal')
            ->selectRaw('MAX(loans.released_at) as last_released_at')
            ->groupBy('loans.borrower_id');

        $page = Borrower::query()
            ->joinSub($perBorrower, 'per_borrower', 'per_borrower.borrower_id', '=', 'borrowers.id')
            ->select([
                'borrowers.id',
                'borrowers.first_name',
                'borrowers.middle_name',
                'borrowers.last_name',
                'borrowers.suffix',
                'per_borrower.loan_count',
                'per_borrower.total_principal',
                'per_borrower.last_released_at',
            ])
            ->orderByRaw(self::BORROWER_NAME_SQL)
            ->orderBy('borrowers.id')
            ->paginate($filters['per_page'] ?? 15);

        $borrowerIds = $page->getCollection()->modelKeys();

        $loans = $borrowerIds === []
            ? collect()
            : $this->releasedLoansQuery($filters)
                ->whereIn('loans.borrower_id', $borrowerIds)
                ->select(['loans.id', 'loans.borrower_id', 'loans.loan_account_number', 'loans.released_at', 'loans.status'])
                ->orderBy('loans.released_at')
                ->orderBy('loans.id')
                ->get()
                ->groupBy('borrower_id');

        $page->getCollection()->each(
            fn (Borrower $borrower) => $borrower->setRelation('releasedLoans', $loans->get($borrower->id, collect())->values()),
        );

        return $page;
    }

    /**
     * Totals over the WHOLE filtered set. `loan_count` and `total_principal`
     * are the List of Releases `count` and `total_principal` for the same
     * filters.
     *
     * @return array{count: int, loan_count: int, total_principal: float}
     */
    public function borrowersReleasedTotals(array $filters): array
    {
        $agg = $this->releasedLoansQuery($filters)->toBase()->selectRaw('
            COUNT(DISTINCT loans.borrower_id) as borrower_count,
            COUNT(*) as loan_count,
            COALESCE(SUM(loans.principal_amount), 0) as total_principal
        ')->first();

        return [
            'count' => (int) ($agg->borrower_count ?? 0),
            'loan_count' => (int) ($agg->loan_count ?? 0),
            'total_principal' => round((float) ($agg->total_principal ?? 0), 2),
        ];
    }

    /**
     * The loans the List of Releases selects for the same filters, unordered
     * so it can be grouped or re-ordered.
     */
    private function releasedLoansQuery(array $filters): Builder
    {
        return $this->releasesQuery($filters)->reorder();
    }

    public function disbursementReport(array $filters): array
    {
        $query = Loan::whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('released_at', '>=', self::dayStart($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('released_at', '<', self::nextDayStart($d)))
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

    // ── Cash Flow / Cash Position ────────────────────────────────────────

    /**
     * Money in vs money out for a period.
     *
     * Cash IN is what actually crossed the counter: posted repayments — scoped
     * through releasedLoanScope() so the figure reconciles exactly with
     * dailyCollection()'s `total_collected` for the same period — plus share
     * capital credits that were paid in.
     *
     * Share capital withheld from a loan at release (a ledger row carrying a
     * `loan_id`, written by ShareCapitalReleaseCredit) is NOT cash in: the
     * money was kept out of the loan, never received. Those rows are left out
     * of `inflows`, `outflows` and the `share_capital` block, and reported
     * under `non_cash.share_capital_at_release` instead. The Share Capital
     * report counts them, because the member's equity did grow: add
     * `non_cash.share_capital_at_release` to `share_capital.credit` to reach
     * that report's credits for the same period and branch.
     *
     * Cash OUT is `net_proceeds`, NOT `principal_amount`. Deductions are
     * withheld at release and never leave the till, so booking the gross
     * principal would overstate the outflow by exactly `total_deductions`;
     * that figure is reported separately under `non_cash` and is excluded from
     * every total.
     *
     * `share_capital_ledger` carries no branch column, so `branch_id` is
     * honoured through the MEMBER's branch (`borrowers.branch_id`) — the exact
     * scoping shareCapital() applies, under the same `borrower_branch` name,
     * so a branch-filtered Cash Flow and the Share Capital report select the
     * same ledger rows for the same period and branch. `share_capital.branch_scope`
     * reports which of the two applied: `borrower_branch` when `branch_id` is
     * set, `organisation` when it is not.
     *
     * Share capital is still deliberately absent from `by_branch`: unfiltered,
     * it cannot be attributed to a branch row at all without double-counting
     * members whose branch is unset. `by_branch` therefore reconciles to the
     * loan cash only; add the `share_capital` block back to reach the headline
     * `inflows.total` / `outflows.total`.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function cashFlow(array $filters): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $branchId = $filters['branch_id'] ?? null;

        $inflow = Repayment::query()
            ->where('status', 'posted')
            ->whereBetween('payment_date', [$fromDate, $toDate])
            ->whereHas('loan', $this->releasedLoanScope($branchId))
            ->selectRaw('
                COUNT(*) as entry_count,
                COALESCE(SUM(principal_applied), 0) as principal_applied,
                COALESCE(SUM(interest_applied), 0) as interest_applied,
                COALESCE(SUM(penalty_applied), 0) as penalty_applied,
                COALESCE(SUM(overpayment), 0) as overpayment
            ')
            ->first();

        $release = Loan::query()
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->where('released_at', '>=', self::dayStart($fromDate))
            ->where('released_at', '<', self::nextDayStart($toDate))
            ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->selectRaw('
                COUNT(*) as loan_count,
                COALESCE(SUM(principal_amount), 0) as principal_amount,
                COALESCE(SUM(total_deductions), 0) as total_deductions,
                COALESCE(SUM(net_proceeds), 0) as net_proceeds
            ')
            ->first();

        // Scoped through the member's branch, identically to shareCapital().
        // A row with a `loan_id` was withheld at release, not paid in, so it
        // is summed apart and kept out of every cash figure.
        $share = DB::table('share_capital_ledger')
            ->when($branchId, fn ($q, $b) => $q->whereIn(
                'share_capital_ledger.borrower_id',
                DB::table('borrowers')->where('branch_id', $b)->select('id'),
            ))
            ->whereBetween('date', [$fromDate, $toDate])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN loan_id IS NULL THEN 1 ELSE 0 END), 0) as entry_count,
                COALESCE(SUM(CASE WHEN loan_id IS NULL THEN credit ELSE 0 END), 0) as credit,
                COALESCE(SUM(CASE WHEN loan_id IS NULL THEN debit ELSE 0 END), 0) as debit,
                COALESCE(SUM(CASE WHEN loan_id IS NOT NULL THEN credit - debit ELSE 0 END), 0) as at_release
            ')
            ->first();

        $principalIn = round((float) ($inflow->principal_applied ?? 0), 2);
        $interestIn = round((float) ($inflow->interest_applied ?? 0), 2);
        $penaltyIn = round((float) ($inflow->penalty_applied ?? 0), 2);
        $overpaymentIn = round((float) ($inflow->overpayment ?? 0), 2);
        // Sum the ALREADY-ROUNDED components so "the parts add up to the whole"
        // holds exactly at two decimals instead of to within a rounding error.
        $repaymentsIn = round($principalIn + $interestIn + $penaltyIn + $overpaymentIn, 2);

        $shareScope = $branchId ? 'borrower_branch' : 'organisation';
        $shareCredit = round((float) ($share->credit ?? 0), 2);
        $shareDebit = round((float) ($share->debit ?? 0), 2);
        $shareAtRelease = round((float) ($share->at_release ?? 0), 2);
        $netProceedsOut = round((float) ($release->net_proceeds ?? 0), 2);

        $totalIn = round($repaymentsIn + $shareCredit, 2);
        $totalOut = round($netProceedsOut + $shareDebit, 2);
        $byBranch = $this->cashFlowByBranch($fromDate, $toDate, $branchId);

        return [
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'inflows' => [
                'repayments' => [
                    'principal' => $principalIn,
                    'interest' => $interestIn,
                    'penalty' => $penaltyIn,
                    'overpayment' => $overpaymentIn,
                    'total' => $repaymentsIn,
                    'count' => (int) ($inflow->entry_count ?? 0),
                ],
                'share_capital_credit' => $shareCredit,
                'total' => $totalIn,
            ],
            'outflows' => [
                'releases' => [
                    'net_proceeds' => $netProceedsOut,
                    'total' => $netProceedsOut,
                    'count' => (int) ($release->loan_count ?? 0),
                ],
                'share_capital_debit' => $shareDebit,
                'total' => $totalOut,
            ],
            'net_movement' => round($totalIn - $totalOut, 2),
            // Booked against the loan but never disbursed, so it is NOT cash
            // out and is excluded from `outflows`.
            'non_cash' => [
                'principal_released' => round((float) ($release->principal_amount ?? 0), 2),
                'total_deductions' => round((float) ($release->total_deductions ?? 0), 2),
                // Part of the deductions withheld at release, credited to the
                // members' share capital ledgers: their equity, never cash in.
                'share_capital_at_release' => $shareAtRelease,
                'note' => 'Deductions are withheld at release and never leave the till; principal_released = net_proceeds + total_deductions. share_capital_at_release is the share capital withheld from loans at release and credited to the members\' share capital: it is in their Share Capital balance but was never received as cash, so it is excluded from inflows and from share_capital.',
            ],
            // Same scope vocabulary as the Share Capital report. Cash only:
            // with non_cash.share_capital_at_release added back, the credits
            // equal that report's for the same period and branch.
            'share_capital' => [
                'branch_scope' => $shareScope,
                'credit' => $shareCredit,
                'debit' => $shareDebit,
                'net_movement' => round($shareCredit - $shareDebit, 2),
                'count' => (int) ($share->entry_count ?? 0),
                'note' => ($branchId
                    ? 'share_capital_ledger has no branch column, so branch_id is honoured through the member\'s branch, matching the Share Capital report. Excluded from by_branch.'
                    : 'No branch filter applied, so these figures are organisation-wide. Share capital is always excluded from by_branch.')
                    .' Cash paid in and out only: share capital withheld at release is under non_cash.share_capital_at_release.',
            ],
            'by_branch' => $byBranch,
            // Loan cash only, like the rows: the repayment and release totals
            // above, never the share capital lines.
            'by_branch_totals' => self::columnTotals($byBranch, [
                'inflow_total' => 'money',
                'outflow_total' => 'money',
                'net_movement' => 'money',
            ]),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Per-branch loan cash, merged from one inflow and one outflow aggregate.
     *
     * `loans.branch_id` is NOT NULL, so every peso of loan cash lands in
     * exactly one branch and these rows sum to the headline repayment and
     * net-proceeds figures. Share capital is absent by design.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cashFlowByBranch(string $fromDate, string $toDate, int|string|null $branchId): array
    {
        $inflows = DB::table('repayments')
            ->join('loans', 'loans.id', '=', 'repayments.loan_id')
            ->join('branches', 'branches.id', '=', 'loans.branch_id')
            ->where('repayments.status', 'posted')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->whereBetween('repayments.payment_date', [$fromDate, $toDate])
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('
                branches.id as branch_id,
                branches.name as branch_name,
                COUNT(*) as entry_count,
                COALESCE(SUM(repayments.principal_applied), 0) as principal_applied,
                COALESCE(SUM(repayments.interest_applied), 0) as interest_applied,
                COALESCE(SUM(repayments.penalty_applied), 0) as penalty_applied,
                COALESCE(SUM(repayments.overpayment), 0) as overpayment
            ')
            ->get()
            ->keyBy(fn ($row) => (int) $row->branch_id);

        $outflows = DB::table('loans')
            ->join('branches', 'branches.id', '=', 'loans.branch_id')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->where('loans.released_at', '>=', self::dayStart($fromDate))
            ->where('loans.released_at', '<', self::nextDayStart($toDate))
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('
                branches.id as branch_id,
                branches.name as branch_name,
                COUNT(*) as loan_count,
                COALESCE(SUM(loans.total_deductions), 0) as total_deductions,
                COALESCE(SUM(loans.net_proceeds), 0) as net_proceeds
            ')
            ->get()
            ->keyBy(fn ($row) => (int) $row->branch_id);

        return $inflows->keys()
            ->merge($outflows->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (int $id) use ($inflows, $outflows) {
                $in = $inflows->get($id);
                $out = $outflows->get($id);

                $principal = round((float) ($in->principal_applied ?? 0), 2);
                $interest = round((float) ($in->interest_applied ?? 0), 2);
                $penalty = round((float) ($in->penalty_applied ?? 0), 2);
                $overpayment = round((float) ($in->overpayment ?? 0), 2);
                $inflowTotal = round($principal + $interest + $penalty + $overpayment, 2);
                $outflowTotal = round((float) ($out->net_proceeds ?? 0), 2);

                return [
                    'branch_id' => $id,
                    'branch_name' => $in->branch_name ?? $out->branch_name,
                    'inflow_principal' => $principal,
                    'inflow_interest' => $interest,
                    'inflow_penalty' => $penalty,
                    'inflow_overpayment' => $overpayment,
                    'inflow_total' => $inflowTotal,
                    'repayment_count' => (int) ($in->entry_count ?? 0),
                    'outflow_net_proceeds' => $outflowTotal,
                    'outflow_total' => $outflowTotal,
                    'release_count' => (int) ($out->loan_count ?? 0),
                    'total_deductions' => round((float) ($out->total_deductions ?? 0), 2),
                    'net_movement' => round($inflowTotal - $outflowTotal, 2),
                ];
            })->all();
    }

    // ── Collection Efficiency ────────────────────────────────────────────

    /**
     * Amount due vs amount collected, overall and segmented by branch and month.
     *
     * The overall figures are computed the same way dailyCollection() computes
     * them — same range, same releasedLoanScope() on BOTH sides of the ratio —
     * so the two reports can never disagree for the same filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function collectionEfficiency(array $filters): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $branchId = $filters['branch_id'] ?? null;
        $loanScope = $this->releasedLoanScope($branchId);

        $totalDue = (float) AmortizationSchedule::query()
            ->whereBetween('due_date', [$fromDate, $toDate])
            ->whereHas('loan', $loanScope)
            ->sum('total_due');

        $totalCollected = (float) Repayment::query()
            ->where('status', 'posted')
            ->whereBetween('payment_date', [$fromDate, $toDate])
            ->whereHas('loan', $loanScope)
            ->sum('amount_paid');

        return array_merge(
            $this->collectionEfficiencyFigures($totalDue, $totalCollected),
            [
                'date_from' => $fromDate,
                'date_to' => $toDate,
                'by_branch' => $this->collectionEfficiencyByBranch($fromDate, $toDate, $branchId),
                'by_period' => $this->collectionEfficiencyByPeriod($from, $to, $branchId),
                // A by_period bucket CAN exceed 100%. That is a timing effect,
                // not the scoping bug releasedLoanScope() guards against: a
                // payment made in August can settle arrears that fell due in
                // June, so August collects more than August billed.
                'note' => 'Both sides of every ratio are scoped identically (loan status + branch). A by_period rate above 100% is a timing effect — arrears from an earlier bucket settled in a later one — not double counting.',
                'generated_at' => now()->toDateTimeString(),
            ],
        );
    }

    /**
     * The four figures every collection-efficiency row carries.
     *
     * @return array{total_due: float, total_collected: float, collection_rate: float, uncollected: float}
     */
    private function collectionEfficiencyFigures(float $due, float $collected): array
    {
        $due = round($due, 2);
        $collected = round($collected, 2);

        return [
            'total_due' => $due,
            'total_collected' => $collected,
            // Whole percent, e.g. 12.5 means 12.5%.
            'collection_rate' => $due > 0 ? round($collected / $due * 100, 2) : 0.0,
            'uncollected' => round(max(0, $due - $collected), 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectionEfficiencyByBranch(string $fromDate, string $toDate, int|string|null $branchId): array
    {
        $due = DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->join('branches', 'branches.id', '=', 'loans.branch_id')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->whereBetween('amortization_schedules.due_date', [$fromDate, $toDate])
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('
                branches.id as branch_id,
                branches.name as branch_name,
                COALESCE(SUM(amortization_schedules.total_due), 0) as total_due
            ')
            ->get()
            ->keyBy(fn ($row) => (int) $row->branch_id);

        // Identical loan scoping to the due side — that is the whole point.
        $collected = DB::table('repayments')
            ->join('loans', 'loans.id', '=', 'repayments.loan_id')
            ->join('branches', 'branches.id', '=', 'loans.branch_id')
            ->where('repayments.status', 'posted')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->whereBetween('repayments.payment_date', [$fromDate, $toDate])
            ->groupBy('branches.id', 'branches.name')
            ->selectRaw('
                branches.id as branch_id,
                branches.name as branch_name,
                COALESCE(SUM(repayments.amount_paid), 0) as total_collected
            ')
            ->get()
            ->keyBy(fn ($row) => (int) $row->branch_id);

        return $due->keys()
            ->merge($collected->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(fn (int $id) => array_merge(
                [
                    'branch_id' => $id,
                    'branch_name' => $due->get($id)->branch_name ?? $collected->get($id)->branch_name,
                ],
                $this->collectionEfficiencyFigures(
                    (float) ($due->get($id)->total_due ?? 0),
                    (float) ($collected->get($id)->total_collected ?? 0),
                ),
            ))->all();
    }

    /**
     * One row per calendar month touched by the range, gaps included as zeros
     * so a chart never silently skips a month with no activity.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectionEfficiencyByPeriod(Carbon $from, Carbon $to, int|string|null $branchId): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $due = DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->whereBetween('amortization_schedules.due_date', [$fromDate, $toDate])
            ->groupByRaw("DATE_FORMAT(amortization_schedules.due_date, '%Y-%m')")
            ->selectRaw("
                DATE_FORMAT(amortization_schedules.due_date, '%Y-%m') as period,
                COALESCE(SUM(amortization_schedules.total_due), 0) as total_due
            ")
            ->get()
            ->keyBy('period');

        $collected = DB::table('repayments')
            ->join('loans', 'loans.id', '=', 'repayments.loan_id')
            ->where('repayments.status', 'posted')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->whereBetween('repayments.payment_date', [$fromDate, $toDate])
            ->groupByRaw("DATE_FORMAT(repayments.payment_date, '%Y-%m')")
            ->selectRaw("
                DATE_FORMAT(repayments.payment_date, '%Y-%m') as period,
                COALESCE(SUM(repayments.amount_paid), 0) as total_collected
            ")
            ->get()
            ->keyBy('period');

        return array_map(fn (array $bucket) => array_merge(
            $bucket,
            $this->collectionEfficiencyFigures(
                (float) ($due->get($bucket['period'])->total_due ?? 0),
                (float) ($collected->get($bucket['period'])->total_collected ?? 0),
            ),
        ), $this->monthBuckets($from, $to));
    }

    // ── Loan Portfolio by Product ────────────────────────────────────────

    /**
     * Portfolio, risk and pricing per loan product.
     *
     * Scoped exactly like loanBalanceSummary() — same statuses, same
     * `released_at` date filters, same branch filter — so the summed
     * `total_released` here equals that report's `portfolio.total_released`
     * for identical filters. The schedule aggregate is pre-grouped per loan in
     * a derived table (see loanScheduleTotals()) so the join cannot multiply
     * `principal_amount` by the number of schedules.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function portfolioByProduct(array $filters): array
    {
        $today = Carbon::today();
        $parCutoff = $today->copy()->subDays(self::PAR_THRESHOLD_DAYS);

        $rows = DB::table('loans')
            ->join('loan_products', 'loan_products.id', '=', 'loans.loan_product_id')
            ->leftJoinSub(
                $this->loanScheduleTotals($today, $parCutoff),
                'schedule_totals',
                'schedule_totals.loan_id',
                '=',
                'loans.id',
            )
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('loans.branch_id', $b))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('loans.released_at', '>=', self::dayStart($d)))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('loans.released_at', '<', self::nextDayStart($d)))
            ->groupBy('loan_products.id', 'loan_products.name')
            ->selectRaw('
                loan_products.id as product_id,
                loan_products.name as product_name,
                COUNT(loans.id) as loan_count,
                COALESCE(SUM(loans.principal_amount), 0) as total_released,
                COALESCE(AVG(loans.interest_rate), 0) as avg_interest_rate,
                COALESCE(SUM(schedule_totals.remaining_principal), 0) as outstanding_principal,
                COALESCE(SUM(loans.insurance_remaining_balance), 0) as insurance_remaining,
                COALESCE(SUM(schedule_totals.overdue_amount), 0) as overdue_amount,
                COALESCE(SUM(CASE WHEN schedule_totals.is_at_risk = 1 THEN schedule_totals.remaining_principal ELSE 0 END), 0) as at_risk_amount
            ')
            ->orderByRaw('total_released DESC')
            ->get();

        $grandReleased = (float) $rows->sum(fn ($r) => (float) $r->total_released);
        $grandOutstandingPrincipal = (float) $rows->sum(fn ($r) => (float) $r->outstanding_principal);
        $grandInsurance = (float) $rows->sum(fn ($r) => (float) $r->insurance_remaining);
        $grandOverdue = (float) $rows->sum(fn ($r) => (float) $r->overdue_amount);
        $grandAtRisk = (float) $rows->sum(fn ($r) => (float) $r->at_risk_amount);
        $grandLoanCount = (int) $rows->sum(fn ($r) => (int) $r->loan_count);
        $weightedRate = (float) $rows->sum(fn ($r) => (float) $r->avg_interest_rate * (int) $r->loan_count);

        $products = $rows->map(function ($row) use ($grandReleased) {
            $outstandingPrincipal = round((float) $row->outstanding_principal, 2);
            $released = round((float) $row->total_released, 2);
            $atRisk = round((float) $row->at_risk_amount, 2);

            return [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'loan_count' => (int) $row->loan_count,
                'total_released' => $released,
                'outstanding' => round($outstandingPrincipal + (float) $row->insurance_remaining, 2),
                'outstanding_principal' => $outstandingPrincipal,
                'avg_interest_rate' => round((float) $row->avg_interest_rate, self::RATE_SCALE),
                'overdue_amount' => round((float) $row->overdue_amount, 2),
                'at_risk_amount' => $atRisk,
                // Whole percent, e.g. 12.5 means 12.5%.
                'par_ratio' => $outstandingPrincipal > 0 ? round($atRisk / $outstandingPrincipal * 100, 2) : 0.0,
                'portfolio_share' => $grandReleased > 0 ? round($released / $grandReleased * 100, 2) : 0.0,
            ];
        })->values()->all();

        return [
            'as_of_date' => $today->toDateString(),
            'par_threshold_days' => self::PAR_THRESHOLD_DAYS,
            'products' => $products,
            'totals' => [
                'product_count' => count($products),
                'loan_count' => $grandLoanCount,
                'total_released' => round($grandReleased, 2),
                'outstanding' => round($grandOutstandingPrincipal + $grandInsurance, 2),
                'outstanding_principal' => round($grandOutstandingPrincipal, 2),
                'avg_interest_rate' => $grandLoanCount > 0 ? round($weightedRate / $grandLoanCount, self::RATE_SCALE) : 0.0,
                'overdue_amount' => round($grandOverdue, 2),
                'at_risk_amount' => round($grandAtRisk, 2),
                'par_ratio' => $grandOutstandingPrincipal > 0
                    ? round($grandAtRisk / $grandOutstandingPrincipal * 100, 2)
                    : 0.0,
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ── Officer / Branch Performance ─────────────────────────────────────

    /**
     * Production and portfolio quality per account officer, mirrored by branch.
     *
     * Two different clocks, deliberately:
     *  - `released_*` and `collected` are PERIOD figures (`date_from`/`date_to`,
     *    unbounded when omitted, as in incomeReport()/disbursementReport()).
     *  - `outstanding*`, `overdue_amount`, `at_risk_amount`, `par_ratio` and
     *    `active_borrowers` are POINT-IN-TIME over the officer's WHOLE book.
     *    Date-scoping the portfolio would hide the book an officer actually
     *    carries and make the report useless for a monthly review.
     *
     * Loans with no `account_officer_id` are reported as a single "Unassigned"
     * row rather than dropped, so the rows still reconcile to the portfolio.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function performance(array $filters): array
    {
        $byOfficer = $this->performanceRows($filters, 'officer');
        $byBranch = $this->performanceRows($filters, 'branch');

        // The money and release counts sum exactly: every loan is in one officer
        // row ("Unassigned" included) and one branch row. PAR and
        // active_borrowers have no total; a ratio and a distinct count do not
        // add up.
        $columns = [
            'released_count' => 'count',
            'released_amount' => 'money',
            'collected' => 'money',
            'outstanding' => 'money',
            'overdue_amount' => 'money',
        ];
        $officerTotals = self::columnTotals($byOfficer, $columns);

        return [
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'as_of_date' => Carbon::today()->toDateString(),
            'par_threshold_days' => self::PAR_THRESHOLD_DAYS,
            'totals' => $officerTotals,
            'by_officer' => $byOfficer,
            'by_officer_totals' => $officerTotals,
            'by_branch' => $byBranch,
            'by_branch_totals' => self::columnTotals($byBranch, $columns),
            'note' => 'released_* and collected cover date_from..date_to; outstanding, overdue, at_risk and active_borrowers are as_of_date figures over the whole book.',
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * One grouped aggregate, run once per dimension.
     *
     * @param  array<string, mixed>  $filters
     * @param  'officer'|'branch'  $dimension
     * @return array<int, array<string, mixed>>
     */
    private function performanceRows(array $filters, string $dimension): array
    {
        $today = Carbon::today();
        $parCutoff = $today->copy()->subDays(self::PAR_THRESHOLD_DAYS);
        $branchId = $filters['branch_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        // `1 = 1` when no period was given: every released loan counts as
        // production, matching incomeReport()'s unbounded behaviour.
        $releasedInPeriod = '1 = 1';
        $releasedBindings = [];
        if ($dateFrom !== null) {
            $releasedInPeriod .= ' AND DATE(loans.released_at) >= ?';
            $releasedBindings[] = $dateFrom;
        }
        if ($dateTo !== null) {
            $releasedInPeriod .= ' AND DATE(loans.released_at) <= ?';
            $releasedBindings[] = $dateTo;
        }

        $collectedInPeriod = DB::table('repayments')
            ->where('status', 'posted')
            ->when($dateFrom, fn ($q, $d) => $q->where('payment_date', '>=', self::filterDay($d)))
            ->when($dateTo, fn ($q, $d) => $q->where('payment_date', '<=', self::filterDay($d)))
            ->groupBy('loan_id')
            ->selectRaw('loan_id, COALESCE(SUM(amount_paid), 0) as collected, COUNT(*) as payment_count');

        $collectible = implode(', ', array_fill(0, count(Loan::COLLECTIBLE_STATUSES), '?'));

        $dimensionSelect = $dimension === 'officer'
            ? "loans.account_officer_id as dimension_id,
               CONCAT(COALESCE(users.first_name, ''), ' ', COALESCE(users.last_name, '')) as dimension_name"
            : 'branches.id as dimension_id, branches.name as dimension_name';

        $query = DB::table('loans')
            ->leftJoinSub(
                $this->loanScheduleTotals($today, $parCutoff),
                'schedule_totals',
                'schedule_totals.loan_id',
                '=',
                'loans.id',
            )
            ->leftJoinSub($collectedInPeriod, 'repayment_totals', 'repayment_totals.loan_id', '=', 'loans.id')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($q, $b) => $q->where('loans.branch_id', $b));

        if ($dimension === 'officer') {
            $query->leftJoin('users', 'users.id', '=', 'loans.account_officer_id')
                ->groupBy('loans.account_officer_id', 'users.first_name', 'users.last_name');
        } else {
            $query->join('branches', 'branches.id', '=', 'loans.branch_id')
                ->groupBy('branches.id', 'branches.name');
        }

        $rows = $query->selectRaw(
            $dimensionSelect.',
            COUNT(loans.id) as loan_count,
            SUM(CASE WHEN '.$releasedInPeriod.' THEN 1 ELSE 0 END) as released_count,
            COALESCE(SUM(CASE WHEN '.$releasedInPeriod.' THEN loans.principal_amount ELSE 0 END), 0) as released_amount,
            COALESCE(SUM(repayment_totals.collected), 0) as collected,
            COALESCE(SUM(repayment_totals.payment_count), 0) as payment_count,
            COALESCE(SUM(schedule_totals.remaining_principal), 0) as outstanding_principal,
            COALESCE(SUM(loans.insurance_remaining_balance), 0) as insurance_remaining,
            COALESCE(SUM(schedule_totals.overdue_amount), 0) as overdue_amount,
            COALESCE(SUM(CASE WHEN schedule_totals.is_at_risk = 1 THEN schedule_totals.remaining_principal ELSE 0 END), 0) as at_risk_amount,
            COUNT(DISTINCT CASE WHEN loans.status IN ('.$collectible.') THEN loans.borrower_id END) as active_borrowers',
            [...$releasedBindings, ...$releasedBindings, ...Loan::COLLECTIBLE_STATUSES],
        )
            ->orderByRaw('released_amount DESC')
            ->get();

        return $rows->map(function ($row) use ($dimension) {
            $outstandingPrincipal = round((float) $row->outstanding_principal, 2);
            $atRisk = round((float) $row->at_risk_amount, 2);
            $name = trim((string) $row->dimension_name);

            $identity = $dimension === 'officer'
                ? [
                    'account_officer_id' => $row->dimension_id !== null ? (int) $row->dimension_id : null,
                    // Never dropped: a loan with no officer is still portfolio.
                    'account_officer_name' => $row->dimension_id !== null && $name !== '' ? $name : 'Unassigned',
                ]
                : [
                    'branch_id' => (int) $row->dimension_id,
                    'branch_name' => $name,
                ];

            return array_merge($identity, [
                'loan_count' => (int) $row->loan_count,
                'released_count' => (int) $row->released_count,
                'released_amount' => round((float) $row->released_amount, 2),
                'collected' => round((float) $row->collected, 2),
                'payment_count' => (int) $row->payment_count,
                'outstanding' => round($outstandingPrincipal + (float) $row->insurance_remaining, 2),
                'outstanding_principal' => $outstandingPrincipal,
                'overdue_amount' => round((float) $row->overdue_amount, 2),
                'at_risk_amount' => $atRisk,
                // Whole percent, e.g. 12.5 means 12.5%.
                'par_ratio' => $outstandingPrincipal > 0 ? round($atRisk / $outstandingPrincipal * 100, 2) : 0.0,
                'active_borrowers' => (int) $row->active_borrowers,
            ]);
        })->values()->all();
    }

    // ── Loan Loss Provisioning ───────────────────────────────────────────

    /**
     * Required allowance for probable losses, by aging bucket.
     *
     * The bucket amounts come from agingReport() rather than a second set of
     * boundary arithmetic, so the disjoint 1–30 / 31–60 / 61–90 / 90+
     * definitions documented there can never drift out from under this report.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function provisioning(array $filters): array
    {
        $aging = $this->agingReport($filters);

        $buckets = [];
        $requiredAllowance = 0.0;

        foreach (self::PROVISION_RATES as $key => $rate) {
            $amount = round((float) ($aging['buckets'][$key]['amount'] ?? 0), 2);
            $allowance = round($amount * $rate, 2);
            $requiredAllowance = round($requiredAllowance + $allowance, 2);

            $buckets[$key] = [
                'amount' => $amount,
                'count' => (int) ($aging['buckets'][$key]['count'] ?? 0),
                'rate' => $rate,
                // Whole percent, e.g. 5.0 means 5%.
                'rate_percent' => round($rate * 100, 2),
                'required_allowance' => $allowance,
            ];
        }

        $totalOverdue = round((float) ($aging['total']['amount'] ?? 0), 2);

        return [
            'as_of_date' => $aging['as_of_date'],
            'buckets' => $buckets,
            'totals' => [
                'amount' => $totalOverdue,
                // Distinct delinquent LOANS, taken from agingReport(): a loan
                // late in two buckets is one delinquent loan, so this is not
                // the sum of the bucket counts.
                'count' => (int) ($aging['total']['count'] ?? 0),
                'required_allowance' => $requiredAllowance,
                // Whole percent, e.g. 12.5 means 12.5%.
                'effective_rate' => $totalOverdue > 0
                    ? round($requiredAllowance / $totalOverdue * 100, 2)
                    : 0.0,
            ],
            'rates' => self::PROVISION_RATES,
            'policy_note' => 'Provision rates are POLICY, not arithmetic. They are a class constant only because there is no settings table for them yet; each cooperative board may set its own ladder.',
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ── Share Capital ────────────────────────────────────────────────────

    /**
     * Share capital movement for a period, with opening and closing balances.
     *
     * `closing_balance` is computed as `opening + credits - debits` from the
     * already-rounded components, so the identity holds exactly at two
     * decimals and is a genuine invariant a test can assert.
     *
     * `date_from` is optional: omit it and the period runs from inception, in
     * which case `opening_balance` is 0. `share_capital_ledger` has no branch
     * column, so `branch_id` is honoured through `borrowers.branch_id` and is
     * applied identically to every figure below.
     *
     * `by_member` names every member and their balance, so one call to this
     * report would otherwise hand the complete membership roster to any role
     * holding `reports:view` — which is all of them, down to `collector` and
     * `viewer`. It is therefore surfaced only to callers the controller has
     * confirmed hold `reports:export`; everyone else gets the identical
     * aggregate figures plus a `by_member_omitted` block saying why the roster
     * is missing, so the UI can explain itself instead of rendering an empty
     * table. The member list is still computed either way — `member_count` is
     * an aggregate and must not change with the caller's permissions.
     *
     * $includeMembers defaults to FALSE so a future caller that forgets to
     * pass it fails closed.
     *
     * @param  array<string, mixed>  $filters
     * @param  bool  $includeMembers  Caller holds `reports:export`; authorised by the controller, never read from the request.
     * @return array<string, mixed>
     */
    public function shareCapital(array $filters, bool $includeMembers = false): array
    {
        [$from, $to] = $this->resolveOpenEndedRange($filters);
        $fromDate = $from?->toDateString();
        $toDate = $to->toDateString();
        $branchId = $filters['branch_id'] ?? null;

        $ledger = fn () => DB::table('share_capital_ledger')
            ->when($branchId, fn ($q, $b) => $q->whereIn(
                'share_capital_ledger.borrower_id',
                DB::table('borrowers')->where('branch_id', $b)->select('id'),
            ));

        $opening = $fromDate === null ? 0.0 : round((float) $ledger()
            ->where('date', '<', $fromDate)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as balance')
            ->value('balance'), 2);

        $period = $ledger()
            ->when($fromDate, fn ($q, $d) => $q->where('date', '>=', self::filterDay($d)))
            ->where('date', '<=', $toDate)
            ->selectRaw('
                COUNT(*) as entry_count,
                COUNT(DISTINCT borrower_id) as members_with_activity,
                COALESCE(SUM(credit), 0) as credits,
                COALESCE(SUM(debit), 0) as debits
            ')
            ->first();

        $credits = round((float) ($period->credits ?? 0), 2);
        $debits = round((float) ($period->debits ?? 0), 2);
        $closing = round($opening + $credits - $debits, 2);

        $members = $this->shareCapitalByMember($fromDate, $toDate, $branchId);
        $byMonth = $this->shareCapitalByMonth($fromDate, $toDate, $branchId, $opening);

        return [
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'branch_scope' => $branchId ? 'borrower_branch' : 'organisation',
            'opening_balance' => $opening,
            'credits' => $credits,
            'debits' => $debits,
            'net_movement' => round($credits - $debits, 2),
            'closing_balance' => $closing,
            'entry_count' => (int) ($period->entry_count ?? 0),
            // Members still holding capital at date_to, not members who moved
            // during the period — those are `members_with_activity`.
            'member_count' => count(array_filter($members, fn ($m) => $m['closing_balance'] != 0.0)),
            'members_with_activity' => (int) ($period->members_with_activity ?? 0),
            'subscription' => $this->shareCapitalSubscription($branchId, $closing),
            'by_month' => $byMonth,
            'by_month_totals' => self::columnTotals($byMonth, [
                'credits' => 'money',
                'debits' => 'money',
                'net_movement' => 'money',
            ]),
            // Null, not [] — an empty array would assert there are no members,
            // which is a different and false statement.
            'by_member' => $includeMembers ? $members : null,
            // Withheld with the rows it totals.
            'by_member_totals' => $includeMembers ? self::columnTotals($members, [
                'opening_balance' => 'money',
                'credits' => 'money',
                'debits' => 'money',
                'closing_balance' => 'money',
            ]) : null,
            'by_member_omitted' => $includeMembers ? null : [
                'reason' => 'permission_required',
                'required_permission' => 'reports:export',
                'message' => 'Per-member share capital holdings are limited to roles that can export reports. Every aggregate figure in this report is complete and unaffected.',
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Subscribed vs paid-in.
     *
     * `share_capital_pledges.amount` is a PER-SCHEDULE commitment (the 15th,
     * the 30th, or both) — not a lump-sum subscription — so it must not be
     * netted against paid-in capital. Borrower::booted() creates a pledge row
     * for every borrower, so only rows with a non-zero amount count as an
     * actual subscription.
     *
     * Member-scoped: an applicant who typed a pledge amount on the public
     * registration form has subscribed to nothing until the co-op approves the
     * registration, so `pending` and `rejected` borrowers are excluded. The
     * member and branch filters share one subquery.
     *
     * @return array<string, mixed>
     */
    private function shareCapitalSubscription(int|string|null $branchId, float $paidIn): array
    {
        $pledges = fn () => DB::table('share_capital_pledges')
            ->whereIn(
                'share_capital_pledges.borrower_id',
                DB::table('borrowers')
                    ->whereNotIn('status', Borrower::NON_MEMBER_STATUSES)
                    ->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
                    ->select('id'),
            );

        $totals = $pledges()
            ->where('amount', '>', 0)
            ->selectRaw('
                COUNT(*) as pledged_member_count,
                COALESCE(SUM(amount), 0) as total_subscribed_per_period,
                COALESCE(SUM(CASE WHEN auto_credit = 1 THEN 1 ELSE 0 END), 0) as auto_credit_member_count
            ')
            ->first();

        $bySchedule = $pledges()
            ->where('amount', '>', 0)
            ->groupBy('schedule')
            ->selectRaw('schedule, COUNT(*) as member_count, COALESCE(SUM(amount), 0) as amount')
            ->get()
            ->map(fn ($row) => [
                'schedule' => $row->schedule,
                'member_count' => (int) $row->member_count,
                'amount' => round((float) $row->amount, 2),
            ])->values()->all();

        return [
            'pledged_member_count' => (int) ($totals->pledged_member_count ?? 0),
            'auto_credit_member_count' => (int) ($totals->auto_credit_member_count ?? 0),
            'total_subscribed_per_period' => round((float) ($totals->total_subscribed_per_period ?? 0), 2),
            'total_paid_in' => $paidIn,
            'by_schedule' => $bySchedule,
            'note' => 'total_subscribed_per_period is the sum of PER-SCHEDULE pledges (15th / 30th / both), not a lump-sum subscription, so it is not directly comparable to total_paid_in.',
        ];
    }

    /**
     * Per-member holdings, deliberately NOT member-scoped.
     *
     * Unlike the pledge-driven blocks, this aggregates `share_capital_ledger`,
     * so a `pending` or `rejected` borrower can only appear here if real money
     * was actually posted against them — a genuine state in this data model,
     * not a phantom row.
     *
     * Do not "complete" the member filter here. `opening_balance`, `credits`,
     * `debits`, `closing_balance` and `by_month` all count that same money;
     * dropping the rows from `by_member` alone would leave the detail table no
     * longer summing to the report's own total and break reconciliation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shareCapitalByMember(?string $fromDate, string $toDate, int|string|null $branchId): array
    {
        $beforePeriod = $fromDate === null ? '1 = 0' : 'share_capital_ledger.date < ?';
        $inPeriod = $fromDate === null ? '1 = 1' : 'share_capital_ledger.date >= ?';
        $bindings = $fromDate === null ? [] : [$fromDate, $fromDate, $fromDate];

        return DB::table('share_capital_ledger')
            ->join('borrowers', 'borrowers.id', '=', 'share_capital_ledger.borrower_id')
            ->when($branchId, fn ($q, $b) => $q->where('borrowers.branch_id', $b))
            ->where('share_capital_ledger.date', '<=', $toDate)
            ->groupBy(
                'borrowers.id',
                'borrowers.borrower_code',
                'borrowers.first_name',
                'borrowers.middle_name',
                'borrowers.last_name',
                'borrowers.suffix',
            )
            ->selectRaw(
                'borrowers.id as borrower_id,
                borrowers.borrower_code,
                borrowers.first_name,
                borrowers.middle_name,
                borrowers.last_name,
                borrowers.suffix,
                COALESCE(SUM(CASE WHEN '.$beforePeriod.' THEN share_capital_ledger.credit - share_capital_ledger.debit ELSE 0 END), 0) as opening_balance,
                COALESCE(SUM(CASE WHEN '.$inPeriod.' THEN share_capital_ledger.credit ELSE 0 END), 0) as credits,
                COALESCE(SUM(CASE WHEN '.$inPeriod.' THEN share_capital_ledger.debit ELSE 0 END), 0) as debits',
                $bindings,
            )
            ->orderByRaw('opening_balance + credits - debits DESC')
            ->orderBy('borrowers.id')
            ->get()
            ->map(function ($row) {
                $opening = round((float) $row->opening_balance, 2);
                $credits = round((float) $row->credits, 2);
                $debits = round((float) $row->debits, 2);

                return [
                    'borrower_id' => (int) $row->borrower_id,
                    'borrower_code' => $row->borrower_code,
                    'full_name' => collect([$row->first_name, $row->middle_name, $row->last_name, $row->suffix])
                        ->filter()->implode(' '),
                    'opening_balance' => $opening,
                    'credits' => $credits,
                    'debits' => $debits,
                    'net_movement' => round($credits - $debits, 2),
                    'closing_balance' => round($opening + $credits - $debits, 2),
                ];
            })->values()->all();
    }

    /**
     * Month-by-month movement with a running closing balance seeded from the
     * opening balance, so the last bucket's closing equals the report's.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shareCapitalByMonth(?string $fromDate, string $toDate, int|string|null $branchId, float $opening): array
    {
        $monthly = DB::table('share_capital_ledger')
            ->when($branchId, fn ($q, $b) => $q->whereIn(
                'share_capital_ledger.borrower_id',
                DB::table('borrowers')->where('branch_id', $b)->select('id'),
            ))
            ->when($fromDate, fn ($q, $d) => $q->where('date', '>=', self::filterDay($d)))
            ->where('date', '<=', $toDate)
            ->groupByRaw("DATE_FORMAT(date, '%Y-%m')")
            ->selectRaw("
                DATE_FORMAT(date, '%Y-%m') as period,
                COALESCE(SUM(credit), 0) as credits,
                COALESCE(SUM(debit), 0) as debits
            ")
            ->get()
            ->keyBy('period');

        // With no `date_from` the period runs from inception, so the span is
        // anchored on the earliest entry actually in scope.
        $earliest = $monthly->keys()->sort()->first();
        $from = $fromDate !== null
            ? Carbon::parse($fromDate)
            : ($earliest !== null ? Carbon::parse($earliest.'-01') : Carbon::parse($toDate));

        $running = $opening;

        return array_map(function (array $bucket) use ($monthly, &$running) {
            $credits = round((float) ($monthly->get($bucket['period'])->credits ?? 0), 2);
            $debits = round((float) ($monthly->get($bucket['period'])->debits ?? 0), 2);
            $running = round($running + $credits - $debits, 2);

            return array_merge($bucket, [
                'credits' => $credits,
                'debits' => $debits,
                'net_movement' => round($credits - $debits, 2),
                'closing_balance' => $running,
            ]);
        }, $this->monthBuckets($from, Carbon::parse($toDate)));
    }

    /**
     * A member's complete share capital statement — the printable behind the
     * Share Capital Certificate.
     *
     * Unpaginated on purpose: `GET /api/share-capital/ledger` caps `per_page`
     * at 100, and a certificate that stops at the hundredth entry is wrong.
     * Shaped on subsidiaryLedger(), with a running balance seeded from the
     * opening balance exactly as statementOfAccount() does.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function shareCapitalStatement(Borrower $borrower, array $filters = []): array
    {
        [$from, $to] = $this->resolveOpenEndedRange($filters);
        $fromDate = $from?->toDateString();
        $toDate = $to->toDateString();

        $opening = $fromDate === null ? 0.0 : round((float) DB::table('share_capital_ledger')
            ->where('borrower_id', $borrower->getKey())
            ->where('date', '<', $fromDate)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as balance')
            ->value('balance'), 2);

        $rows = ShareCapitalLedger::query()
            ->with('createdByUser')
            ->where('borrower_id', $borrower->getKey())
            ->when($fromDate, fn ($q, $d) => $q->where('date', '>=', self::filterDay($d)))
            ->where('date', '<=', $toDate)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $running = $opening;
        $credits = 0.0;
        $debits = 0.0;

        $entries = $rows->map(function (ShareCapitalLedger $entry) use (&$running, &$credits, &$debits) {
            $credit = round((float) $entry->credit, 2);
            $debit = round((float) $entry->debit, 2);
            $credits = round($credits + $credit, 2);
            $debits = round($debits + $debit, 2);
            $running = round($running + $credit - $debit, 2);

            return [
                'id' => $entry->id,
                'date' => $entry->date->toDateString(),
                'reference' => $entry->reference,
                'description' => $entry->description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
                'posted_by' => $entry->createdByUser?->full_name,
            ];
        })->values()->all();

        $borrower->loadMissing('branch', 'shareCapitalPledge');
        $pledge = $borrower->shareCapitalPledge;

        return [
            'borrower' => [
                'id' => $borrower->getKey(),
                'borrower_code' => $borrower->borrower_code,
                'full_name' => $borrower->full_name,
                'address' => $borrower->address,
                'contact_number' => $borrower->contact_number,
                'branch_name' => $borrower->branch?->name,
            ],
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'opening_balance' => $opening,
            'entries' => $entries,
            'totals' => [
                'entry_count' => count($entries),
                'credits' => $credits,
                'debits' => $debits,
                'net_movement' => round($credits - $debits, 2),
            ],
            'closing_balance' => round($opening + $credits - $debits, 2),
            // `totals.credits` and `totals.debits` under the names the
            // certificate reads beside `closing_balance`.
            'total_credit' => $credits,
            'total_debit' => $debits,
            'pledge' => $pledge === null ? null : [
                'amount' => round((float) $pledge->amount, 2),
                'schedule' => $pledge->schedule,
                'auto_credit' => (bool) $pledge->auto_credit,
            ],
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ── Shared query fragments ───────────────────────────────────────────

    /**
     * Constraint for loans that have ever been released, honouring `branch_id`.
     *
     * Extracted so BOTH sides of a collection ratio can be scoped through the
     * SAME closure. Filtering only the denominator by loan status or branch is
     * how the collection rate ends up above 100%: payments counted against
     * amounts that were never in the total due.
     */
    private function releasedLoanScope(int|string|null $branchId): Closure
    {
        return fn ($q) => $q
            ->whereIn('status', Loan::EVER_RELEASED_STATUSES)
            ->when($branchId, fn ($lq, $b) => $lq->where('branch_id', $b));
    }

    /**
     * Per-loan schedule aggregate — remaining principal, overdue amount and an
     * at-risk flag — as ONE ROW PER LOAN.
     *
     * Always joined as a derived table (`leftJoinSub`), never directly:
     * joining `amortization_schedules` straight onto `loans` multiplies every
     * loan-level column by the schedule count, which is the bug
     * balanceSummaryByBranch() already had to fix once.
     *
     * Both lateness tests below — `overdue_amount` against $today and
     * `is_at_risk` against $parCutoff — are struck from the bare `due_date`,
     * and deliberately NOT through AmortizationSchedule::pastGraceSql(). This
     * is the third site under the same decision as the aging buckets in
     * overdueScheduleQuery() and the PAR numerator in loanBalanceSummary():
     * prudential portfolio-quality figures are conventionally measured from the
     * due date, so honouring grace here would move reported portfolio quality.
     * That is a provisioning decision, recorded rather than made — do not
     * "finish the job" by making this one grace-aware on its own.
     *
     * Note this is the easiest of the three to change by accident, because the
     * comparison is buried in raw SQL as two bare `due_date < ?` fragments
     * rather than in a named query method.
     */
    private function loanScheduleTotals(Carbon $today, Carbon $parCutoff): QueryBuilder
    {
        $unpaid = AmortizationSchedule::UNPAID_STATUSES;
        $placeholders = implode(', ', array_fill(0, count($unpaid), '?'));

        return DB::table('amortization_schedules')
            ->groupBy('loan_id')
            ->selectRaw(
                'loan_id,
                SUM('.AmortizationSchedule::remainingPrincipalSql().') as remaining_principal,
                SUM(CASE WHEN status IN ('.$placeholders.') AND due_date < ? THEN '.AmortizationSchedule::remainingTotalSql().' ELSE 0 END) as overdue_amount,
                MAX(CASE WHEN status IN ('.$placeholders.') AND due_date < ? THEN 1 ELSE 0 END) as is_at_risk',
                [...$unpaid, $today->toDateString(), ...$unpaid, $parCutoff->toDateString()],
            );
    }

    /**
     * Every calendar month a range touches, clamped to the range at both ends
     * so the first and last buckets never claim days outside the period.
     *
     * @return array<int, array{period: string, label: string, date_from: string, date_to: string}>
     */
    private function monthBuckets(Carbon $from, Carbon $to): array
    {
        $buckets = [];
        $cursor = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $start = $cursor->copy()->startOfMonth();
            $end = $cursor->copy()->endOfMonth();

            $buckets[] = [
                'period' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'date_from' => ($start->lt($from) ? $from : $start)->toDateString(),
                'date_to' => ($end->gt($to) ? $to : $end)->toDateString(),
            ];

            $cursor->addMonth();
        }

        return $buckets;
    }

    /**
     * A period whose START may legitimately be open.
     *
     * Unlike resolveDateRange(), which defaults both ends to today, the share
     * capital reports treat a missing `date_from` as "since inception" — that
     * is what makes the opening balance zero and the closing balance the
     * member's whole holding.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?Carbon, 1: Carbon}
     */
    private function resolveOpenEndedRange(array $filters): array
    {
        $to = $this->resolveAsOfDate($filters);
        $from = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : null;

        // Same swap guard as resolveDateRange(): `as_of_date` can be older than
        // `date_from`, which validation does not cross-check.
        return $from !== null && $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    // ── Filter resolution ────────────────────────────────────────────────

    /**
     * A report date filter's day as `Y-m-d`, the form whereDate() compares a
     * date against. ReportController::reportFilters() has already validated
     * it as a date.
     *
     * The date filters below compare columns to this directly instead of
     * wrapping them in DATE(), which stops an index serving the range. On a
     * DATE column (`payment_date`, `share_capital_ledger.date`) the plain
     * comparison is the same comparison. On a DATETIME/TIMESTAMP column
     * (`released_at`, `created_at`) dayStart() and nextDayStart() turn it into
     * the same whole-day range.
     */
    private static function filterDay(string|DateTimeInterface $date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    /**
     * Midnight at the start of the day, for a DATETIME/TIMESTAMP column:
     * `col >= dayStart(D)` selects what whereDate(col, '>=', D) did, and
     * `col < dayStart(D)` what whereDate(col, '<', D) did.
     */
    private static function dayStart(string|DateTimeInterface $date): string
    {
        return self::filterDay($date).' 00:00:00';
    }

    /**
     * Midnight at the start of the NEXT day, for a DATETIME/TIMESTAMP column:
     * `col < nextDayStart(D)` selects what whereDate(col, '<=', D) did, every
     * moment of D up to and including 23:59:59.
     */
    private static function nextDayStart(string|DateTimeInterface $date): string
    {
        return Carbon::parse(self::filterDay($date))->addDay()->toDateString().' 00:00:00';
    }

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
