<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Services\PortfolioOutstanding;
use App\Services\Savings\Reports\GlControl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The accounting dashboard's figures, as `AccountingDashboard` in
 * `src/types/accounting.ts`.
 *
 * ## Where each figure comes from
 *
 * - The positions at `as_of` (cash, receivables, the statement totals,
 *   `is_balanced`) come from {@see TrialBalanceBuilder}, and the receivable
 *   figures from {@see ReceivablePosition} over the same rows, so the cards
 *   can't disagree with the trial balance or the balance sheet built from it.
 * - `total_equity` is {@see BalanceSheetBuilder::totalEquity()} over the same
 *   rows (posted equity plus earnings not yet closed), the balance sheet's own
 *   figure, so assets equal liabilities plus equity on the dashboard too.
 * - The period, month to date and each month's profit are
 *   {@see IncomeStatementBuilder::build()} for that range, the income
 *   statement's own figures, so whatever the statement leaves out (the
 *   year-end closing entries) the dashboard leaves out too. Month-end cash is
 *   one query grouped by month.
 * - `past_due` reads the LOAN records by the grace rule
 *   ({@see PortfolioOutstanding}, the Past Due Loans page's figure). Loan
 *   records keep no history, so it is always as of today, and says so.
 * - `unposted_journals` counts draft HEADERS: drafts are not in the books, so
 *   there is no balance to read them from.
 * - `period` and each month of `months` also carry `interest_income`,
 *   `penalty_income` and `fee_income` (Phase 6 EB-D2, ask 4): the income
 *   statement's own lines for the accounts mapped to the `interest_income`
 *   and `penalty_income` roles and the three loan fee roles (processing,
 *   service and other fees), so they cover exactly the range and branches of
 *   `net_income`. They are cash basis (`income_basis`), and differ from the
 *   Income report's collected interest by payments that posted no journal and
 *   by deferred interest recognised from 1210: shown beside it, never added
 *   to it. An account mapped to two roles counts once, under the first.
 * - `cash_total` is every money account (cash, bank and e-wallets), the
 *   three cash cards added once here so the browser never adds them.
 * - `books_state` is {@see BooksState}: "Requires configuration" until the
 *   opening balances are finalised, "Books not set up" without a chart.
 *
 * Money is integer centavos throughout.
 */
final class AccountingDashboardBuilder
{
    /** How many journals `recent_journals` lists. */
    public const RECENT_JOURNALS = 10;

    /** How long a past day's savings GL control is cached, in minutes. */
    public const SAVINGS_GL_CACHE_MINUTES = 5;

    /** How many months `months` covers, ending with as_of's month. */
    public const TREND_MONTHS = 12;

    /**
     * The income figures read by posting role, in the order an account mapped
     * to two of them is counted (once, under the first).
     *
     * @var array<string, list<string>>
     */
    public const INCOME_ROLES = [
        'interest_income' => ['interest_income'],
        'penalty_income' => ['penalty_income'],
        'fee_income' => ['processing_fee_income', 'service_fee_income', 'other_fee_income'],
    ];

    public const INTEREST_INCOME_LABEL = 'Interest income (cash basis)';

    /** @var array<int, string>|null account id => the income figure it counts in, for one build */
    private ?array $incomeAccounts = null;

    public function __construct(
        private TrialBalanceBuilder $trialBalance,
        private IncomeStatementBuilder $incomeStatement,
        private PortfolioOutstanding $portfolio,
        private GlControl $savingsControl,
    ) {}

    /**
     * @param  int|list<int>|null  $branchId
     * @return array<string, mixed>
     */
    public function build(string $asOf, int|array|null $branchId = null, ?string $from = null): array
    {
        $trialBalance = $this->trialBalance->build($asOf, $branchId);
        $asOfDate = CarbonImmutable::parse($asOf)->startOfDay();
        $monthStart = $asOfDate->startOfMonth()->toDateString();
        $from ??= $monthStart;

        $chart = AccountingAccount::query()->get()->keyBy('id');
        $this->incomeAccounts = self::incomeAccounts();
        $signed = ReceivablePosition::signedByAccount($trialBalance['rows'], $chart);
        $receivables = ReceivablePosition::fromTrialBalance($trialBalance['rows'], $chart);

        $months = $this->months($asOfDate, $asOf, $branchId);

        // Month to date is the last month's statement: the first of as_of's
        // month to as_of. The period reuses it when it covers the same range.
        $monthToDate = $months[array_key_last($months)];
        $period = $from === $monthStart
            ? ['from' => $from, 'to' => $monthToDate['to'], ...array_intersect_key($monthToDate, array_flip(['income', 'expenses', 'net_income', ...array_keys(self::INCOME_ROLES)]))]
            : $this->period($from, $asOf, $branchId);
        $period = [...$period, 'income_basis' => 'cash', 'interest_income_label' => self::INTEREST_INCOME_LABEL];

        $currentPeriod = $this->currentPeriod($asOfDate->toDateString());
        $cashOnHand = $this->sumByCashKind($signed, $chart, ['cash']);
        $cashInBank = $this->sumByCashKind($signed, $chart, ['bank']);
        $eWallets = $this->sumByCashKind($signed, $chart, ['gcash', 'maya', 'wallet']);

        return [
            'as_of' => $asOf,
            'books_state' => BooksState::current(),
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => $cashInBank,
            'e_wallets' => $eWallets,
            'cash_total' => $cashOnHand + $cashInBank + $eWallets,
            'loans_receivable_net' => $receivables['net'],
            'loans_receivable_gross' => $receivables['gross'],
            'allowance_for_credit_losses' => $receivables['allowance'],
            'unearned_restructure_income' => $receivables['unearned_income'],
            'adjustments_clearing' => $receivables['adjustments_clearing'],
            'adjustments_clearing_needs_review' => $receivables['adjustments_clearing'] !== 0,
            'total_assets' => $this->totalFor($trialBalance['rows'], 'asset'),
            'total_liabilities' => $this->totalFor($trialBalance['rows'], 'liability'),
            'total_equity' => BalanceSheetBuilder::totalEquity($trialBalance['rows']),
            'income_mtd' => $monthToDate['income'],
            'expenses_mtd' => $monthToDate['expenses'],
            'net_income_mtd' => $monthToDate['net_income'],
            'unposted_journals' => $this->draftCount($branchId),
            'is_balanced' => $trialBalance['is_balanced'],
            'open_period' => $currentPeriod !== null && $currentPeriod['status'] === 'open' ? $currentPeriod['name'] : null,
            'current_period' => $currentPeriod,
            'period' => $period,
            'cash_accounts' => $this->cashAccounts($signed, $chart),
            'past_due' => $this->pastDue($branchId),
            'months' => array_map(
                static fn (array $month): array => array_diff_key($month, ['to' => true]),
                $months,
            ),
            'recent_journals' => $this->recentJournals($asOf, $branchId),
            ...$this->savingsGl($asOf, $branchId),
        ];
    }

    /**
     * The savings GL control (the Savings task's TASK 62) as of `as_of`, in
     * the dashboard's branch scope: `savings_gl_differs` is true when any
     * branch's savings or time deposit subledger differs from GL 2050 or 2060,
     * and `savings_gl_control` carries the day and the total difference in ₱
     * (a two-decimal string). Both are null while savings is not in use.
     *
     * Today reads the savings accounts' own balances instead of every savings
     * row; another day sums the whole history, so it is cached for
     * SAVINGS_GL_CACHE_MINUTES per day and scope.
     *
     * @param  int|list<int>|null  $branchId
     * @return array{savings_gl_differs: bool|null, savings_gl_control: array{as_of: string, difference: string, differences: int}|null}
     */
    private function savingsGl(string $asOf, int|array|null $branchId): array
    {
        if (! $this->savingsControl->hasSavings()) {
            return ['savings_gl_differs' => null, 'savings_gl_control' => null];
        }

        $day = CarbonImmutable::parse($asOf)->toDateString();
        $scope = is_int($branchId) ? [$branchId] : $branchId;

        $control = $day === now()->toDateString()
            ? $this->savingsControl->build($day, $scope, null, true)
            : Cache::remember(
                'accounting_dashboard.savings_gl.'.$day.'.'.($scope === null ? 'all' : implode('-', $scope)),
                now()->addMinutes(self::SAVINGS_GL_CACHE_MINUTES),
                fn () => $this->savingsControl->build($day, $scope),
            );

        return [
            'savings_gl_differs' => ! $control['is_balanced'],
            'savings_gl_control' => [
                'as_of' => $control['as_of'],
                'difference' => $control['totals']['difference'],
                'differences' => $control['differences'],
            ],
        ];
    }

    /**
     * A statement section's total, in the direction that section reads.
     *
     * Trial-balance rows are already placed in the column they landed on, so
     * one of `debit`/`credit` is always zero and the subtraction below is just
     * "signed, in the type's normal direction". Contra accounts need no special
     * case and must not get one: an allowance is an asset sitting in the credit
     * column, so it SUBTRACTS from total assets on its own, which is exactly
     * what "Net Loans Receivable is gross minus allowance" means at the
     * statement level.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function totalFor(array $rows, string $type): int
    {
        $total = 0;

        foreach ($rows as $row) {
            if ($row['type'] === $type) {
                $total += BalanceSheetBuilder::netFor($row);
            }
        }

        return $total;
    }

    /**
     * Money physically held, by where it sits.
     *
     * `cash_kind` is the only thing that puts an account on this figure — not
     * its code and not its name — so an organisation that renames "GCash" or
     * adds a second bank account gets the right total without anyone editing
     * this file.
     *
     * @param  array<int, int>  $signed
     * @param  Collection<int, AccountingAccount>  $chart
     * @param  list<string>  $kinds
     */
    private function sumByCashKind(array $signed, Collection $chart, array $kinds): int
    {
        $total = 0;

        foreach ($signed as $accountId => $balance) {
            $account = $chart->get($accountId);

            if ($account !== null && in_array($account->cash_kind, $kinds, true)) {
                $total += $balance;
            }
        }

        return $total;
    }

    /**
     * Each money account and its balance at as_of, in code order.
     *
     * The same accounts as the Cash & Bank screen: every non-heading account
     * carrying a `cash_kind`, inactive ones included (a deactivated wallet that
     * still holds money is still money), with 0 for one that never moved.
     *
     * @param  array<int, int>  $signed
     * @param  Collection<int, AccountingAccount>  $chart
     * @return list<array{account_id: int, account_code: string, account_name: string, cash_kind: string, balance: int}>
     */
    private function cashAccounts(array $signed, Collection $chart): array
    {
        return $chart
            ->filter(static fn (AccountingAccount $account): bool => $account->cash_kind !== null && ! $account->is_group)
            ->sortBy([['code', 'asc'], ['id', 'asc']])
            ->map(static fn (AccountingAccount $account): array => [
                'account_id' => (int) $account->id,
                'account_code' => (string) $account->code,
                'account_name' => (string) $account->name,
                'cash_kind' => (string) $account->cash_kind,
                'balance' => $signed[(int) $account->id] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Income, expenses and net income from `$from` to `$to`, inclusive: the
     * income statement's totals for the same range, with its interest,
     * penalty and fee income lines by posting role.
     *
     * @param  int|list<int>|null  $branchId
     * @return array{from: string, to: string, income: int, expenses: int, net_income: int, interest_income: int, penalty_income: int, fee_income: int}
     */
    private function period(string $from, string $to, int|array|null $branchId): array
    {
        $statement = $this->incomeStatement->build($from, $to, $branchId);

        return [
            'from' => $from,
            'to' => CarbonImmutable::parse($to)->toDateString(),
            'income' => (int) $statement['total_income'],
            'expenses' => (int) $statement['total_expenses'],
            'net_income' => (int) $statement['net_income'],
            ...$this->incomeByRole($statement['income']),
        ];
    }

    /**
     * The income statement's income lines added up by posting role.
     *
     * @param  list<array{account_id: int, amount: int}>  $lines
     * @return array{interest_income: int, penalty_income: int, fee_income: int}
     */
    private function incomeByRole(array $lines): array
    {
        $figures = array_fill_keys(array_keys(self::INCOME_ROLES), 0);
        $accounts = $this->incomeAccounts ??= self::incomeAccounts();

        foreach ($lines as $line) {
            $figure = $accounts[(int) $line['account_id']] ?? null;

            if ($figure !== null) {
                $figures[$figure] += (int) $line['amount'];
            }
        }

        return $figures;
    }

    /**
     * Each account mapped to an income role, with the figure it counts in.
     * An account mapped to two roles counts under the first (INCOME_ROLES
     * order), so it is never counted twice.
     *
     * @return array<int, string>
     */
    private static function incomeAccounts(): array
    {
        $mapping = AccountingAccountMapping::resolved();
        $accounts = [];

        foreach (self::INCOME_ROLES as $figure => $roles) {
            foreach ($roles as $role) {
                if (isset($mapping[$role]) && ! isset($accounts[$mapping[$role]])) {
                    $accounts[$mapping[$role]] = $figure;
                }
            }
        }

        return $accounts;
    }

    /**
     * The 12 months ending with as_of's month, oldest first.
     *
     * Each month's income and expenses are the income statement for that
     * month (one small grouped query each, on the dated index), so a month
     * never counts what the statement leaves out. Month-end cash is one query
     * grouped by month over the money accounts, accumulated here, so the months
     * before the window carry in as the opening cash. The last month stops at
     * as_of, the same cut-off as the cash cards.
     *
     * @param  int|list<int>|null  $branchId
     * @return list<array{month: string, income: int, expenses: int, net_income: int, interest_income: int, penalty_income: int, fee_income: int, cash: int, to: string}>
     */
    private function months(CarbonImmutable $asOfDate, string $asOf, int|array|null $branchId): array
    {
        $firstMonth = $asOfDate->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1);

        // The two columns are summed apart and signed here: they're unsigned,
        // so MySQL refuses a subtraction inside the aggregate that goes below 0.
        $cashByMonth = [];
        $this->lines($branchId)
            ->whereNotNull('a.cash_kind')
            ->where('j.date', '<=', $asOf)
            ->groupByRaw("DATE_FORMAT(j.date, '%Y-%m'), a.normal_balance")
            ->selectRaw("DATE_FORMAT(j.date, '%Y-%m') as month, a.normal_balance, sum(l.debit) as debit, sum(l.credit) as credit")
            ->get()
            ->each(function (object $row) use (&$cashByMonth): void {
                $cashByMonth[$row->month] = ($cashByMonth[$row->month] ?? 0)
                    + AccountRules::signedBalance((string) $row->normal_balance, (int) $row->debit, (int) $row->credit);
            });

        $firstKey = $firstMonth->format('Y-m');
        $cash = 0;
        foreach ($cashByMonth as $month => $movement) {
            if ($month < $firstKey) {
                $cash += $movement;
            }
        }

        $months = [];

        for ($offset = 0; $offset < self::TREND_MONTHS; $offset++) {
            $month = $firstMonth->addMonthsNoOverflow($offset);
            $key = $month->format('Y-m');
            $to = $offset === self::TREND_MONTHS - 1 ? $asOfDate->toDateString() : $month->endOfMonth()->toDateString();
            $profit = $this->period($month->toDateString(), $to, $branchId);
            $cash += $cashByMonth[$key] ?? 0;

            $months[] = [
                'month' => $key,
                'income' => $profit['income'],
                'expenses' => $profit['expenses'],
                'net_income' => $profit['net_income'],
                'interest_income' => $profit['interest_income'],
                'penalty_income' => $profit['penalty_income'],
                'fee_income' => $profit['fee_income'],
                'cash' => $cash,
                'to' => $to,
            ];
        }

        return $months;
    }

    /**
     * Past due by the grace rule, from the loan records: the Past Due Loans
     * page's figure, for the same branches. Pesos there, converted once here.
     *
     * Always as of TODAY, whatever as_of says: a loan record keeps no history,
     * so "past due on 31 January" can't be read back from it.
     *
     * @param  int|list<int>|null  $branchId
     * @return array{as_of: string, amount: int, count: int}
     */
    private function pastDue(int|array|null $branchId): array
    {
        $totals = $this->portfolio->totals($branchId === null ? null : array_values((array) $branchId));

        return [
            'as_of' => now()->toDateString(),
            'amount' => Money::toCentavos((float) $totals['overdue_amount']) ?? 0,
            'count' => (int) $totals['overdue_count'],
        ];
    }

    /**
     * The accounting period covering as_of, open or closed, or null.
     *
     * @return array{id: int, code: string, name: string, start_date: string, end_date: string, status: string}|null
     */
    private function currentPeriod(string $date): ?array
    {
        $period = AccountingPeriod::query()->covering($date)->chronological()->first();

        if ($period === null) {
            return null;
        }

        return [
            'id' => (int) $period->id,
            'code' => (string) $period->code,
            'name' => (string) $period->name,
            'start_date' => CarbonImmutable::parse($period->start_date)->toDateString(),
            'end_date' => CarbonImmutable::parse($period->end_date)->toDateString(),
            'status' => (string) $period->status,
        ];
    }

    /**
     * The latest posted or reversed journals dated on or before as_of, in the
     * caller's scope (the journal register's rule), newest first.
     *
     * @param  int|list<int>|null  $branchId
     * @return list<array{id: int, journal_no: string, date: string, source: string, description: string, branch_name: string|null, amount: int}>
     */
    private function recentJournals(string $asOf, int|array|null $branchId): array
    {
        return DB::table('accounting_journals as j')
            ->leftJoin('branches as b', 'b.id', '=', 'j.branch_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('j.date', '<=', $asOf)
            ->when($branchId !== null, fn ($q) => $q->whereIn('j.branch_id', (array) $branchId))
            ->orderByDesc('j.date')
            ->orderByDesc('j.journal_no')
            ->orderByDesc('j.id')
            ->limit(self::RECENT_JOURNALS)
            ->get(['j.id', 'j.journal_no', 'j.date', 'j.source', 'j.description', 'b.name as branch_name', 'j.total_debit'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'journal_no' => (string) ($row->journal_no ?? ''),
                'date' => CarbonImmutable::parse($row->date)->toDateString(),
                'source' => (string) $row->source,
                'description' => (string) $row->description,
                'branch_name' => $row->branch_name === null ? null : (string) $row->branch_name,
                'amount' => (int) $row->total_debit,
            ])
            ->all();
    }

    /**
     * Journal lines that count, the trial balance's rule: posted and reversed
     * journals, postable accounts, in the caller's branch scope.
     *
     * @param  int|list<int>|null  $branchId
     */
    private function lines(int|array|null $branchId): Builder
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('a.is_group', false)
            ->when($branchId !== null, fn ($q) => $q->whereIn('j.branch_id', (array) $branchId));
    }

    /**
     * Drafts waiting for someone to post them.
     *
     * Deliberately NOT filtered by `as_of`, unlike every other figure here.
     * This is a work queue, not a position: a draft dated next month is still
     * sitting on someone's desk, and it is precisely the one an as-of filter
     * would hide.
     */
    private function draftCount(int|array|null $branchId): int
    {
        return AccountingJournal::query()
            ->where('status', 'draft')
            ->when($branchId !== null, fn ($q) => $q->whereIn('branch_id', (array) $branchId))
            ->count();
    }
}
