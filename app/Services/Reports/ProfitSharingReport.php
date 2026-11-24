<?php

namespace App\Services\Reports;

use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Services\ReportService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Profit Sharing report (finish run round 2, decision 17; ClickUp
 * 14zfbg0e9gg): how the year's distributable profit would be shared among the
 * members by their share capital on a cutoff date. A report only: nothing is
 * credited to a member or posted to the books.
 *
 * - **Share capital** is each member's share capital ledger balance on the
 *   cutoff date, by the ledger's own rule (ShareCapitalLedger::balancesQuery(),
 *   credits less debits, to the cutoff date). Members holding none, or less
 *   than none, are left out; those holding less are counted.
 * - **Distributable profit** runs from 1 January of the cutoff date's year to
 *   the cutoff date, on the cash basis:
 *   - plus the lending income received: interest, penalty and the processing,
 *     service and other loan fees, the Income report's own figures
 *     (ReportService::incomeReport()). Principal is never income.
 *   - less the expenses recorded in Expenses (`accounting_expenses`), dated in
 *     the period.
 *   - less the credit losses: the allowance for credit losses provided less
 *     released (`accounting_allowance_postings`, by `as_of`), and the principal
 *     written off by restructures released in the period. A write-off counts
 *     only for what it charged to expense, as its journal (J2,
 *     AutomaticPoster::restructureRelease()) posts it: the part the allowance
 *     covered was counted when the allowance was provided, and the part
 *     unearned interest covered was never income. A restructure with no write-
 *     off journal (no books when it was released) loses all of it.
 *   A journal reversed on or before the cutoff date counts as never posted, so
 *   its expense, allowance posting or write-off is left out; one reversed later
 *   still stood on the cutoff date.
 * - **Earnings** are (share capital ÷ all members' share capital) × the
 *   profit, in exact maths, half up to the centavo
 *   (ProfitSharingDistribution); what the rounding leaves over is reported.
 *   Nothing is shared from a profit at or below ₱0.00.
 *
 * Branches follow the reports' rule. In one branch, or a branch user's own
 * branches, the figures are those branches' own: income on their loans, their
 * expenses, allowance postings and write-offs, shared among the members now in
 * them, each with their whole share capital (the share capital member list's
 * rule). Head office's expenses with no branch count only for the whole
 * cooperative.
 *
 * Money is worked out in integer centavos and sent in pesos.
 */
final class ProfitSharingReport
{
    /** Read with every figure: the definition is the owner's to confirm. */
    public const BASIS_NOTE = 'To confirm with the owner: distributable profit is the lending income received from 1 January to the '
        .'cutoff date (interest, penalty, and processing, service and other loan fees, as the Income report counts them), less the '
        .'expenses recorded in Expenses, less credit losses: the allowance for credit losses provided less released, and principal '
        .'written off by restructures beyond what the allowance or interest never received already covered. Principal repayments '
        .'are never income, and savings interest, GCash income and journal entries made by hand are not counted. Each member\'s '
        .'share capital is their balance on the cutoff date. This report only calculates: nothing is credited to members or '
        .'posted to the books.';

    public const ROWS_WITHHELD = 'Each member\'s share capital and earnings are limited to roles that can export reports. '
        .'The profit and the totals are complete.';

    public function __construct(private readonly ReportService $reports) {}

    /**
     * @param  string  $cutoff  `Y-m-d`, no later than today
     * @param  int|null  $branchId  the one branch asked for, already allowed for the caller
     * @param  list<int>|null  $callerBranches  the caller's branches (BranchAccess::for()); null for every branch
     * @param  bool  $withRows  whether the caller sees each member's row (`reports:export`)
     * @return array<string, mixed>
     */
    public function build(string $cutoff, ?int $branchId, ?array $callerBranches, bool $withRows): array
    {
        $from = CarbonImmutable::parse($cutoff)->startOfYear()->toDateString();
        $scope = $branchId !== null ? [$branchId] : $callerBranches;

        $income = $this->lendingIncome($from, $cutoff, $branchId, $callerBranches);
        $expenses = $this->expenses($from, $cutoff, $scope);
        $allowance = $this->allowancePostings($from, $cutoff, $scope);
        $writeOffs = $this->writeOffs($from, $cutoff, $scope);
        $losses = $allowance + $writeOffs['written_off'] - $writeOffs['covered'];
        $profit = $income['lending_income'] - $expenses - $losses;

        $members = $this->members($cutoff, $scope);
        $holders = array_values(array_filter($members, fn (array $member): bool => $member['centavos'] > 0));
        $shares = ProfitSharingDistribution::of(array_column($holders, 'centavos', 'id'), $profit);

        $rows = array_map(fn (array $member): array => [
            'borrower_id' => $member['id'],
            'member_code' => $member['code'],
            'member_name' => $member['name'],
            'share_capital' => self::pesos($member['centavos']),
            'share_percent' => (float) $shares->sharePercent[$member['id']],
            'earnings' => self::pesos($shares->earnings[$member['id']]),
            'capital_plus_earnings' => self::pesos($member['centavos'] + $shares->earnings[$member['id']]),
        ], $holders);

        return [
            'cutoff_date' => $cutoff,
            'period_from' => $from,
            'period_to' => $cutoff,
            'branch_scope' => $scope === null ? 'organisation' : 'branches',
            'profit' => array_map(self::pesos(...), [
                ...$income,
                'expenses' => $expenses,
                'allowance_for_credit_losses' => $allowance,
                'principal_written_off' => $writeOffs['written_off'],
                'written_off_covered' => $writeOffs['covered'],
                'losses' => $losses,
                'distributable_profit' => $profit,
            ]),
            'distribution' => match (true) {
                $profit <= 0 => 'no_profit',
                $holders === [] => 'no_share_capital',
                default => 'shared',
            },
            // Null, never []: an empty list would say no member holds share
            // capital, which `totals.member_count` would contradict.
            'rows' => $withRows ? $rows : null,
            'rows_omitted' => $withRows ? null : [
                'reason' => 'permission_required',
                'required_permission' => 'reports:export',
                'message' => self::ROWS_WITHHELD,
            ],
            'totals' => [
                'member_count' => count($holders),
                'share_capital' => self::pesos($shares->totalShareCapital),
                'share_percent' => $holders === [] ? null : 100.0,
                'earnings' => self::pesos($shares->totalEarnings),
                'capital_plus_earnings' => self::pesos($shares->totalShareCapital + $shares->totalEarnings),
                'rounding_remainder' => self::pesos($shares->remainder),
                'members_with_negative_balance' => count(array_filter($members, fn (array $member): bool => $member['centavos'] < 0)),
            ],
            'basis_note' => self::BASIS_NOTE,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * The Income report's figures for the period, in centavos, and their sum.
     *
     * @param  list<int>|null  $callerBranches
     * @return array{interest_income: int, penalty_income: int, processing_fees: int, service_fee: int, other_fees: int, lending_fees: int, lending_income: int}
     */
    private function lendingIncome(string $from, string $to, ?int $branchId, ?array $callerBranches): array
    {
        $report = $this->reports->incomeReport([
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => $branchId,
            'branch_ids' => $callerBranches,
        ]);

        $parts = [];

        foreach (['interest_income', 'penalty_income', 'processing_fees', 'service_fee', 'other_fees'] as $part) {
            // Each is pesos rounded to two places, so this is exact for any
            // amount below about ₱90 trillion.
            $parts[$part] = (int) round((float) $report[$part] * 100);
        }

        $fees = $parts['processing_fees'] + $parts['service_fee'] + $parts['other_fees'];

        return [
            ...$parts,
            'lending_fees' => $fees,
            'lending_income' => $parts['interest_income'] + $parts['penalty_income'] + $fees,
        ];
    }

    /**
     * The expenses recorded in Expenses and dated in the period, in centavos.
     *
     * @param  list<int>|null  $scope
     */
    private function expenses(string $from, string $to, ?array $scope): int
    {
        return (int) DB::table('accounting_expenses')
            ->join('accounting_journals as recorded', 'recorded.id', '=', 'accounting_expenses.journal_id')
            ->whereIn('recorded.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('accounting_expenses.date', '>=', $from)
            ->where('accounting_expenses.date', '<=', $to)
            ->tap(self::standingOn($to, 'recorded'))
            ->when($scope !== null, fn (Builder $query) => $query->whereIn('accounting_expenses.branch_id', $scope))
            ->sum('accounting_expenses.amount');
    }

    /**
     * The allowance for credit losses provided less released in the period, in
     * centavos: each allowance posting's `adjustment` (Dr credit loss expense
     * when positive).
     *
     * @param  list<int>|null  $scope
     */
    private function allowancePostings(string $from, string $to, ?array $scope): int
    {
        return (int) DB::table('accounting_allowance_postings')
            ->join('accounting_journals as posted', 'posted.id', '=', 'accounting_allowance_postings.accounting_journal_id')
            ->whereIn('posted.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('accounting_allowance_postings.as_of', '>=', $from)
            ->where('accounting_allowance_postings.as_of', '<=', $to)
            ->tap(self::standingOn($to, 'posted'))
            ->when($scope !== null, fn (Builder $query) => $query->whereIn('accounting_allowance_postings.branch_id', $scope))
            ->sum('accounting_allowance_postings.adjustment');
    }

    /**
     * The principal written off by restructures released in the period, and
     * how much of it the allowance or unearned interest covered, in centavos.
     *
     * A restructure's write-off journal (J2) is keyed on the loan it
     * restructures, and its lines on expense accounts are what it charged to
     * expense; the rest it took from the allowance and the unearned interest.
     *
     * @param  list<int>|null  $scope
     * @return array{written_off: int, covered: int}
     */
    private function writeOffs(string $from, string $to, ?array $scope): array
    {
        $restructures = DB::table('loans')
            ->whereNotNull('loans.source_loan_id')
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->where('loans.restructure_written_off_principal', '>', 0)
            ->where('loans.released_at', '>=', $from.' 00:00:00')
            ->where('loans.released_at', '<', CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00')
            ->when($scope !== null, fn (Builder $query) => $query->whereIn('loans.branch_id', $scope))
            ->get(['loans.source_loan_id', 'loans.restructure_written_off_principal']);

        if ($restructures->isEmpty()) {
            return ['written_off' => 0, 'covered' => 0];
        }

        $charged = DB::table('accounting_journals as write_off')
            ->join('accounting_journal_lines as line', 'line.accounting_journal_id', '=', 'write_off.id')
            ->join('accounting_accounts as account', 'account.id', '=', 'line.accounting_account_id')
            ->where('write_off.source', 'credit_loss')
            ->where('write_off.postable_type', (new Loan)->getMorphClass())
            ->whereIn('write_off.postable_id', $restructures->pluck('source_loan_id'))
            ->whereIn('write_off.status', AccountingJournal::HISTORICAL_STATUSES)
            ->tap(self::standingOn($to, 'write_off'))
            ->groupBy('write_off.postable_id')
            // Summed apart: the columns are unsigned, so a line's own debit
            // less credit can't go below zero in SQL.
            ->selectRaw("write_off.postable_id, COALESCE(SUM(CASE WHEN account.type = 'expense' THEN line.debit ELSE 0 END), 0)"
                ." - COALESCE(SUM(CASE WHEN account.type = 'expense' THEN line.credit ELSE 0 END), 0) as charged")
            ->pluck('charged', 'postable_id');

        $writtenOff = 0;
        $covered = 0;

        foreach ($restructures as $restructure) {
            $principal = self::centavos((string) $restructure->restructure_written_off_principal);
            $writtenOff += $principal;

            if ($charged->has($restructure->source_loan_id)) {
                $covered += $principal - (int) $charged->get($restructure->source_loan_id);
            }
        }

        return ['written_off' => $writtenOff, 'covered' => $covered];
    }

    /**
     * Each member's share capital on the cutoff date, by the ledger's own
     * balance rule, for the members now in `$scope` (every member when null),
     * by name.
     *
     * @param  list<int>|null  $scope
     * @return list<array{id: int, code: string|null, name: string, centavos: int}>
     */
    private function members(string $cutoff, ?array $scope): array
    {
        $balances = ShareCapitalLedger::balancesQuery()->where('date', '<=', $cutoff);

        return DB::query()
            ->fromSub($balances, 'balances')
            ->join('borrowers', 'borrowers.id', '=', 'balances.borrower_id')
            ->when($scope !== null, fn (Builder $query) => $query->whereIn('borrowers.branch_id', $scope))
            ->orderBy('borrowers.last_name')
            ->orderBy('borrowers.first_name')
            ->orderBy('borrowers.id')
            ->get([
                'borrowers.id',
                'borrowers.borrower_code',
                'borrowers.first_name',
                'borrowers.middle_name',
                'borrowers.last_name',
                'borrowers.suffix',
                'balances.balance',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'code' => $row->borrower_code,
                'name' => collect([$row->first_name, $row->middle_name, $row->last_name, $row->suffix])->filter()->implode(' '),
                'centavos' => self::centavos((string) $row->balance),
            ])
            ->all();
    }

    /**
     * A journal that still stood on the cutoff date: never reversed, or
     * reversed by an entry dated after it.
     */
    private static function standingOn(string $cutoff, string $journal): Closure
    {
        return fn (Builder $query) => $query->where(fn (Builder $standing) => $standing
            ->whereNull("{$journal}.reversed_by_journal_id")
            ->orWhereExists(fn (Builder $reversal) => $reversal
                ->from('accounting_journals as reversal')
                ->whereColumn('reversal.id', "{$journal}.reversed_by_journal_id")
                ->where('reversal.date', '>', $cutoff)));
    }

    /** A decimal peso amount as the database sends it ("1500.00"), in centavos, exactly. */
    private static function centavos(string $pesos): int
    {
        return BigDecimal::of($pesos)->withPointMovedRight(2)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private static function pesos(int $centavos): float
    {
        return $centavos / 100;
    }
}
