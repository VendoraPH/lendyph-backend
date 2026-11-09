<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
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
