<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;

/**
 * A saved schedule's share capital build-up, total payment and column totals,
 * in whole centavos: the one place AmortizationScheduleResource (each row),
 * GET /loans/{id}/amortization-schedule's `summary` and LoanResource's
 * `amortization_schedule_totals` take them from, so they agree with each
 * other and with POST /loans/preview for the same loan.
 *
 * One build-up per persisted period, at the loan's `scb_amount`: one per
 * extension cycle (an extension's row collapses the cycles it carries and
 * still gets one), and one for an upon-maturity loan, which is one period.
 *
 * A period an extension or an adjustment closed (`closed_by_adjustment_id`)
 * gets none. It is restated to exactly what was collected on it, and its
 * unpaid remainder moved into a later period that carries that instalment's
 * build-up; a build-up on both would count the instalment twice and show a
 * paid period owing more than was collected.
 */
final class PersistedScheduleFigures
{
    public static function shareCapitalBuildUpCentavos(Loan $loan, AmortizationSchedule $row): int
    {
        if ($row->closed_by_adjustment_id !== null) {
            return 0;
        }

        return self::centavos($loan->scb_amount);
    }

    /**
     * The period's amount due (`total_due`, which excludes penalty everywhere)
     * plus its build-up.
     */
    public static function totalPaymentCentavos(Loan $loan, AmortizationSchedule $row): int
    {
        return self::centavos($row->total_due) + self::shareCapitalBuildUpCentavos($loan, $row);
    }

    /**
     * Each column of `$rows` added up in whole centavos, returned in pesos.
     *
     * @param  iterable<AmortizationSchedule>  $rows
     * @return array{principal: float|int, interest: float|int, penalty: float|int, total_due: float|int, amount_paid: float|int, share_capital_build_up: float|int, total_payment: float|int}
     */
    public static function totals(Loan $loan, iterable $rows): array
    {
        $totals = [
            'principal' => 0, 'interest' => 0, 'penalty' => 0, 'total_due' => 0,
            'amount_paid' => 0, 'share_capital_build_up' => 0, 'total_payment' => 0,
        ];

        foreach ($rows as $row) {
            $totals['principal'] += self::centavos($row->principal_due);
            $totals['interest'] += self::centavos($row->interest_due);
            $totals['penalty'] += self::centavos($row->penalty_amount);
            $totals['total_due'] += self::centavos($row->total_due);
            $totals['amount_paid'] += self::centavos($row->principal_paid) + self::centavos($row->interest_paid) + self::centavos($row->penalty_paid);
            $totals['share_capital_build_up'] += self::shareCapitalBuildUpCentavos($loan, $row);
            $totals['total_payment'] += self::totalPaymentCentavos($loan, $row);
        }

        return array_map(fn (int $sum): float|int => $sum / 100, $totals);
    }

    private static function centavos(mixed $pesos): int
    {
        return (int) round((float) ($pesos ?? 0) * 100);
    }
}
