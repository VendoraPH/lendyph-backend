<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Carbon\Carbon;

/**
 * What is still owed on each amortization period, per component.
 *
 * It reads only what RepaymentService::allocateToSchedule() already recorded
 * on each period (`principal_paid`, `interest_paid`, `penalty_paid`) and never
 * allocates anything itself, so a payment, a void, a penalty run or an
 * adjustment shows up here the moment it touches the schedule.
 *
 * A period's balance is due minus paid per component, floored at zero per
 * period through AmortizationSchedule's remaining*Sql() helpers, the rule every
 * outstanding figure goes through. Late uses AmortizationSchedule::lateUnpaid(),
 * the same grace-aware test as the loan summary's `overdue_amount`. The totals
 * are therefore the summary's outstanding, paid and overdue figures
 * (RepaymentService::getLoanSummary()), which the loan screen shows beside them.
 */
class AmortizationBalanceService
{
    /**
     * @return array{
     *     periods: list<array{
     *         id: int,
     *         period_number: int,
     *         due_date: string,
     *         status: string,
     *         is_late: bool,
     *         principal: array{due: float, paid: float, balance: float},
     *         interest: array{due: float, paid: float, balance: float},
     *         penalty: array{charged: float, paid: float, balance: float},
     *         balance: float,
     *     }>,
     *     totals: array{
     *         principal: array{due: float, paid: float, balance: float},
     *         interest: array{due: float, paid: float, balance: float},
     *         penalty: array{charged: float, paid: float, balance: float},
     *         paid: float,
     *         balance: float,
     *         overdue: float,
     *     },
     * }
     */
    public function forLoan(Loan $loan): array
    {
        $schedules = $loan->amortizationSchedules()
            ->select('amortization_schedules.*')
            ->selectRaw(AmortizationSchedule::remainingPrincipalSql().' as principal_balance')
            ->selectRaw(AmortizationSchedule::remainingInterestSql().' as interest_balance')
            ->selectRaw(AmortizationSchedule::remainingPenaltySql().' as penalty_balance')
            ->get();

        $late = AmortizationSchedule::lateUnpaid($schedules, $loan->grace_period_days, Carbon::today())
            ->keyBy('id');

        $periods = $schedules->map(fn (AmortizationSchedule $schedule): array => [
            'id' => $schedule->id,
            'period_number' => $schedule->period_number,
            'due_date' => $schedule->due_date->toDateString(),
            'status' => $schedule->status,
            'is_late' => $late->has($schedule->id),
            'principal' => [
                'due' => (float) $schedule->principal_due,
                'paid' => (float) $schedule->principal_paid,
                'balance' => (float) $schedule->principal_balance,
            ],
            'interest' => [
                'due' => (float) $schedule->interest_due,
                'paid' => (float) $schedule->interest_paid,
                'balance' => (float) $schedule->interest_balance,
            ],
            'penalty' => [
                'charged' => (float) $schedule->penalty_amount,
                'paid' => (float) $schedule->penalty_paid,
                'balance' => (float) $schedule->penalty_balance,
            ],
            'balance' => round($this->periodBalance($schedule), 2),
        ])->values()->all();

        $principalPaid = (float) $schedules->sum('principal_paid');
        $interestPaid = (float) $schedules->sum('interest_paid');
        $penaltyPaid = (float) $schedules->sum('penalty_paid');
        $principalBalance = (float) $schedules->sum(fn (AmortizationSchedule $s) => (float) $s->principal_balance);
        $interestBalance = (float) $schedules->sum(fn (AmortizationSchedule $s) => (float) $s->interest_balance);
        $penaltyBalance = (float) $schedules->sum(fn (AmortizationSchedule $s) => (float) $s->penalty_balance);

        return [
            'periods' => $periods,
            'totals' => [
                'principal' => [
                    'due' => round((float) $schedules->sum('principal_due'), 2),
                    'paid' => round($principalPaid, 2),
                    'balance' => round($principalBalance, 2),
                ],
                'interest' => [
                    'due' => round((float) $schedules->sum('interest_due'), 2),
                    'paid' => round($interestPaid, 2),
                    'balance' => round($interestBalance, 2),
                ],
                'penalty' => [
                    'charged' => round((float) $schedules->sum('penalty_amount'), 2),
                    'paid' => round($penaltyPaid, 2),
                    'balance' => round($penaltyBalance, 2),
                ],
                'paid' => round($principalPaid + $interestPaid + $penaltyPaid, 2),
                'balance' => round($principalBalance + $interestBalance + $penaltyBalance, 2),
                'overdue' => round($late->sum(fn (AmortizationSchedule $s) => $this->periodBalance($s)), 2),
            ],
        ];
    }

    private function periodBalance(AmortizationSchedule $schedule): float
    {
        return (float) $schedule->principal_balance
            + (float) $schedule->interest_balance
            + (float) $schedule->penalty_balance;
    }
}
