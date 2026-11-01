<?php

namespace App\Services;

use App\Models\Loan;

/**
 * The Amortization Calculator's schedule (POST /loans/amortization-calculator):
 * a what-if loan with no product, built by the engine a release writes the
 * real schedule with (LoanService::maturityDateFor() and
 * LoanService::buildAmortizationPreview()), so the calculator can never show
 * a schedule a loan with the same terms would not get.
 *
 * Its `rows` and `totals` take the shape and the centavo arithmetic of
 * POST /loans/preview's `amortization`, and a test pins the two equal for the
 * same settings. Writes nothing.
 */
class AmortizationCalculatorService
{
    public function __construct(private readonly LoanService $loans) {}

    /**
     * @param  array{principal_amount: float|int|string, interest_rate: float|int|string, interest_rate_frequency: string, interest_method: string, term: int|string, term_unit: string, frequency: string, start_date: string, scb_amount?: float|int|string|null, compare?: bool|null}  $input
     * @return array<string, mixed>
     */
    public function calculate(array $input): array
    {
        $result = $this->schedule($input, $input['interest_method']);

        if (! empty($input['compare'])) {
            $result['comparison'] = [];

            foreach (['straight', 'diminishing', 'upon_maturity'] as $method) {
                $result['comparison'][$method] = $this->schedule($input, $method);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{maturity_date: string, interest_method: string, rows: list<array<string, mixed>>, totals: array<string, float|int>}
     */
    private function schedule(array $input, string $method): array
    {
        $principal = LoanService::toCentavos((float) $input['principal_amount']);
        $maturity = $this->loans->maturityDateFor($input['start_date'], (int) $input['term'], $input['term_unit'], $input['frequency']);

        $loan = (new Loan)->forceFill([
            'interest_method' => $method,
            'term_unit' => $input['term_unit'],
            'interest_rate_frequency' => $input['interest_rate_frequency'],
            'principal_amount' => $principal / 100,
            'interest_rate' => (float) $input['interest_rate'],
            'term' => (int) $input['term'],
            'frequency' => $input['frequency'],
            'start_date' => $input['start_date'],
            'maturity_date' => $maturity,
        ]);

        $scb = LoanService::toCentavos((float) ($input['scb_amount'] ?? 0));
        $totals = ['principal_due' => 0, 'interest_due' => 0, 'share_capital_build_up' => 0, 'total_payment' => 0];
        $rows = [];

        foreach ($this->loans->buildAmortizationPreview($loan) as $row) {
            $payment = LoanService::toCentavos((float) $row['total_due']) + $scb;

            $rows[] = [
                'period_number' => $row['period_number'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'total_due' => $row['total_due'],
                'share_capital_build_up' => $scb / 100,
                'total_payment' => $payment / 100,
                'remaining_balance' => $row['remaining_balance'],
            ];

            $totals['principal_due'] += LoanService::toCentavos((float) $row['principal_due']);
            $totals['interest_due'] += LoanService::toCentavos((float) $row['interest_due']);
            $totals['share_capital_build_up'] += $scb;
            $totals['total_payment'] += $payment;
        }

        return [
            'maturity_date' => $loan->maturity_date->toDateString(),
            'interest_method' => $method,
            'rows' => $rows,
            'totals' => array_map(fn (int $centavos): float|int => $centavos / 100, $totals),
        ];
    }
}
