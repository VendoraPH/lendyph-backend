<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What a set of loans still owes, as one grouped aggregate, so a list's
 * totals cover every loan its filters match rather than the page the browser
 * holds, and no loan is loaded as a model to add it up.
 *
 * - `total_outstanding`: everything owed. The periods' principal, interest
 *   and penalty still unpaid, plus the insurance premium still owed, the same
 *   parts as GET /loans/{id}/summary `outstanding_balance` and LoanResource's
 *   `total_payable`.
 * - `outstanding_principal`: the periods' principal still unpaid, without the
 *   premium.
 * - `overdue_amount`: principal, interest and penalty still unpaid on periods
 *   that are LATE (unpaid and past the due date plus the loan's grace), the
 *   rule LoanResource's `overdue_amount` reads through
 *   AmortizationSchedule::lateUnpaid().
 * - `overdue_count`: how many of the loans have an overdue amount.
 *
 * Every per-period figure goes through AmortizationSchedule's SQL helpers,
 * floored at zero per period.
 */
class LoanListTotals
{
    /**
     * Borrower::full_name in SQL: first, middle, last and suffix, empty parts
     * dropped (ReportService's member name expression).
     */
    private const MEMBER_NAME_SQL = "CONCAT_WS(' ', NULLIF(borrowers.first_name, ''), NULLIF(borrowers.middle_name, ''), NULLIF(borrowers.last_name, ''), NULLIF(borrowers.suffix, ''))";

    /**
     * @param  Builder<Loan>  $loans  the filtered loans; its select, eager loads and order are ignored
     * @return array{total_outstanding: float, outstanding_principal: float, overdue_amount: float, overdue_count: int}
     */
    public function for(Builder $loans, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $perLoan = $this->perLoan($this->baseOf($loans)->select('loans.id'), $asOf);

        $row = $this->baseOf($loans)
            ->leftJoinSub($perLoan, 'per_loan', 'per_loan.loan_id', '=', 'loans.id')
            ->selectRaw(
                'COALESCE(SUM(per_loan.owed), 0) + COALESCE(SUM(GREATEST(loans.insurance_remaining_balance, 0)), 0) as total_outstanding,
                COALESCE(SUM(per_loan.principal), 0) as outstanding_principal,
                COALESCE(SUM(per_loan.overdue), 0) as overdue_amount,
                COUNT(CASE WHEN per_loan.overdue > 0 THEN 1 END) as overdue_count',
            )
            ->first();

        return [
            'total_outstanding' => round((float) ($row->total_outstanding ?? 0), 2),
            'outstanding_principal' => round((float) ($row->outstanding_principal ?? 0), 2),
            'overdue_amount' => round((float) ($row->overdue_amount ?? 0), 2),
            'overdue_count' => (int) ($row->overdue_count ?? 0),
        ];
    }

    /**
     * The same figures for each loan on its own, keyed by loan id, in one
     * grouped query: `total_outstanding` (GET /loans `total_outstanding`),
     * `overdue_amount` (GET /loans/{id}/summary `overdue_amount`) and
     * `outstanding_principal` (GET /loans/{id} `outstanding_principal`, the
     * per-loan figure self::for() sums). A list that shows a loan's figures
     * beside each of its rows asks once per page.
     *
     * @param  array<int, int>  $loanIds
     * @return array<int, array{total_outstanding: float, overdue_amount: float, outstanding_principal: float}>
     */
    public function byLoan(array $loanIds, ?Carbon $asOf = null): array
    {
        if ($loanIds === []) {
            return [];
        }

        $asOf ??= Carbon::today();

        return DB::table('loans')
            ->leftJoinSub($this->perLoan($loanIds, $asOf), 'per_loan', 'per_loan.loan_id', '=', 'loans.id')
            ->whereIn('loans.id', $loanIds)
            ->selectRaw(
                'loans.id,
                COALESCE(per_loan.owed, 0) + COALESCE(GREATEST(loans.insurance_remaining_balance, 0), 0) as total_outstanding,
                COALESCE(per_loan.overdue, 0) as overdue_amount,
                COALESCE(per_loan.principal, 0) as outstanding_principal',
            )
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->id => [
                'total_outstanding' => round((float) $row->total_outstanding, 2),
                'overdue_amount' => round((float) $row->overdue_amount, 2),
                'outstanding_principal' => round((float) $row->outstanding_principal, 2),
            ]])
            ->all();
    }

    /**
     * The same figures as self::for(), summed per member (`loans.borrower_id`)
     * over the filtered loans, keyed by member id, in one grouped query. A
     * member with none of the loans has no key. A list that shows each
     * member's outstanding beside its rows asks once per page.
     *
     * @param  Builder<Loan>  $loans  the filtered loans; its select, eager loads and order are ignored
     * @return array<int, array{total_outstanding: float, outstanding_principal: float, overdue_amount: float}>
     */
    public function byBorrower(Builder $loans, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $perLoan = $this->perLoan($this->baseOf($loans)->select('loans.id'), $asOf);

        return $this->baseOf($loans)
            ->leftJoinSub($perLoan, 'per_loan', 'per_loan.loan_id', '=', 'loans.id')
            ->groupBy('loans.borrower_id')
            ->selectRaw(
                'loans.borrower_id,
                COALESCE(SUM(per_loan.owed), 0) + COALESCE(SUM(GREATEST(loans.insurance_remaining_balance, 0)), 0) as total_outstanding,
                COALESCE(SUM(per_loan.principal), 0) as outstanding_principal,
                COALESCE(SUM(per_loan.overdue), 0) as overdue_amount',
            )
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->borrower_id => [
                'total_outstanding' => round((float) $row->total_outstanding, 2),
                'outstanding_principal' => round((float) $row->outstanding_principal, 2),
                'overdue_amount' => round((float) $row->overdue_amount, 2),
            ]])
            ->all();
    }

    /**
     * The same figures as self::for(), per group of `$columns` (such as
     * `loans.branch_id` and `loans.loan_product_id`), plus each group's
     * `loan_count`, in one grouped query, keyed by the group's values joined
     * with `-` in the order given. A group with no loan has no key. Each
     * group's figures are self::for()'s over that group's loans, so the
     * groups add up to self::for() over all of them.
     *
     * @param  Builder<Loan>  $loans  the filtered loans; its select, eager loads and order are ignored
     * @param  list<string>  $columns  `loans.` columns
     * @return array<string, array{total_outstanding: float, outstanding_principal: float, overdue_amount: float, overdue_count: int, loan_count: int}>
     */
    public function grouped(Builder $loans, array $columns, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $perLoan = $this->perLoan($this->baseOf($loans)->select('loans.id'), $asOf);

        return $this->baseOf($loans)
            ->leftJoinSub($perLoan, 'per_loan', 'per_loan.loan_id', '=', 'loans.id')
            ->groupBy($columns)
            ->select($columns)
            ->selectRaw(
                'COALESCE(SUM(per_loan.owed), 0) + COALESCE(SUM(GREATEST(loans.insurance_remaining_balance, 0)), 0) as total_outstanding,
                COALESCE(SUM(per_loan.principal), 0) as outstanding_principal,
                COALESCE(SUM(per_loan.overdue), 0) as overdue_amount,
                COUNT(CASE WHEN per_loan.overdue > 0 THEN 1 END) as overdue_count,
                COUNT(*) as loan_count',
            )
            ->get()
            ->mapWithKeys(fn (object $row) => [self::groupKey($row, $columns) => [
                'total_outstanding' => round((float) $row->total_outstanding, 2),
                'outstanding_principal' => round((float) $row->outstanding_principal, 2),
                'overdue_amount' => round((float) $row->overdue_amount, 2),
                'overdue_count' => (int) $row->overdue_count,
                'loan_count' => (int) $row->loan_count,
            ]])
            ->all();
    }

    /**
     * A grouped row's key: its `$columns` values, joined with `-`.
     *
     * @param  list<string>  $columns
     */
    public static function groupKey(object $row, array $columns): string
    {
        return implode('-', array_map(
            fn (string $column): string => (string) $row->{str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column},
            $columns,
        ));
    }

    /**
     * The members with a LATE period on the filtered loans (unpaid and past
     * the due date plus the loan's grace), as of $asOf: the Past Due Loans
     * page's members (GET /reports/due-past-due with `past_due_only=1`,
     * grouped by member), worked out in one grouped query.
     *
     * - `past_due_amount`: the member's loans' `overdue_amount` added up, so
     *   the total over every member is self::for()'s `overdue_amount`.
     * - `days_overdue`: the most days any late period is past its grace, the
     *   report rows' `days_overdue` rule (ReportController::daysLate()).
     * - `loan_count`: the member's loans with a late period.
     *
     * Members are sorted by amount, highest first, then by name. Amounts are
     * 2-decimal strings, added as exact decimals.
     *
     * @param  Builder<Loan>  $loans  the filtered loans, with columns named `loans.` (the query joins `borrowers`); its select, eager loads and order are ignored
     * @return array{member_count: int, loan_count: int, past_due_amount: string, members: list<array{borrower_id: int, name: string, code: string, past_due_amount: string, days_overdue: int, loan_count: int}>}
     */
    public function overdueByMember(Builder $loans, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $perLoan = $this->perLoan($this->baseOf($loans)->select('loans.id'), $asOf);

        $unpaid = implode(', ', array_fill(0, count(AmortizationSchedule::UNPAID_STATUSES), '?'));
        $lateDays = DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->whereIn('amortization_schedules.loan_id', $this->baseOf($loans)->select('loans.id'))
            ->whereRaw("`amortization_schedules`.`status` IN ({$unpaid})", AmortizationSchedule::UNPAID_STATUSES)
            ->whereRaw(AmortizationSchedule::pastGraceSql(), [$asOf->toDateString()])
            ->groupBy('amortization_schedules.loan_id')
            ->selectRaw(
                'amortization_schedules.loan_id,
                MAX(DATEDIFF(DATE_SUB(?, INTERVAL COALESCE(`loans`.`grace_period_days`, 0) DAY), `amortization_schedules`.`due_date`)) as days_overdue',
                [$asOf->toDateString()],
            );

        $name = self::MEMBER_NAME_SQL;

        $rows = $this->baseOf($loans)
            ->joinSub($lateDays, 'late', 'late.loan_id', '=', 'loans.id')
            ->leftJoinSub($perLoan, 'per_loan', 'per_loan.loan_id', '=', 'loans.id')
            ->join('borrowers', 'borrowers.id', '=', 'loans.borrower_id')
            ->groupBy('loans.borrower_id', 'borrowers.first_name', 'borrowers.middle_name', 'borrowers.last_name', 'borrowers.suffix', 'borrowers.borrower_code')
            ->selectRaw(
                "loans.borrower_id,
                {$name} as member_name,
                borrowers.borrower_code,
                COALESCE(SUM(per_loan.overdue), 0) as past_due_amount,
                MAX(late.days_overdue) as days_overdue,
                COUNT(loans.id) as loan_count",
            )
            ->orderByDesc('past_due_amount')
            ->orderBy('member_name')
            ->orderBy('loans.borrower_id')
            ->get();

        $total = BigDecimal::zero();
        $members = [];

        foreach ($rows as $row) {
            $amount = BigDecimal::of((string) $row->past_due_amount)->toScale(2, RoundingMode::HalfUp);
            $total = $total->plus($amount);

            $members[] = [
                'borrower_id' => (int) $row->borrower_id,
                'name' => (string) $row->member_name,
                'code' => (string) $row->borrower_code,
                'past_due_amount' => (string) $amount,
                'days_overdue' => (int) $row->days_overdue,
                'loan_count' => (int) $row->loan_count,
            ];
        }

        return [
            'member_count' => count($members),
            'loan_count' => array_sum(array_column($members, 'loan_count')),
            'past_due_amount' => (string) $total->toScale(2),
            'members' => $members,
        ];
    }

    /**
     * One row per loan: what its periods still owe (`owed`), their principal
     * (`principal`) and the part on LATE periods (`overdue`). The join puts
     * `loans` in scope for the grace period pastGraceSql() reads.
     *
     * @param  QueryBuilder|array<int, int>  $loanIds
     */
    private function perLoan(QueryBuilder|array $loanIds, Carbon $asOf): QueryBuilder
    {
        $remainingTotal = AmortizationSchedule::remainingTotalSql();
        $unpaid = implode(', ', array_fill(0, count(AmortizationSchedule::UNPAID_STATUSES), '?'));
        $late = "`amortization_schedules`.`status` IN ({$unpaid}) AND ".AmortizationSchedule::pastGraceSql();

        return DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->whereIn('amortization_schedules.loan_id', $loanIds)
            ->groupBy('amortization_schedules.loan_id')
            ->selectRaw(
                'amortization_schedules.loan_id,
                SUM('.$remainingTotal.') as owed,
                SUM('.AmortizationSchedule::remainingPrincipalSql().') as principal,
                SUM(CASE WHEN '.$late.' THEN '.$remainingTotal.' ELSE 0 END) as overdue',
                [...AmortizationSchedule::UNPAID_STATUSES, $asOf->toDateString()],
            );
    }

    /**
     * The filtered query without the caller's columns, eager loads or order,
     * so the same filters (and their bindings) drive both halves above.
     *
     * @param  Builder<Loan>  $loans
     */
    private function baseOf(Builder $loans): QueryBuilder
    {
        $base = (clone $loans)->toBase();
        $base->columns = null;
        $base->bindings['select'] = [];

        return $base->reorder();
    }
}
