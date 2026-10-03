<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Voiding a payment that leaves a late period fully unpaid puts back the
 * penalty that period owes, the figure applyPenalties() would charge it, so
 * the loan owes again exactly what it owed before the payment. It used to set
 * the charge to 0 and wait for the next payment or the nightly run, leaving
 * the period overdue with no penalty and the loan's figures understated.
 */
class VoidRestoresPenaltyTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_void_leaving_a_late_period_unpaid_restores_the_penalty_it_owed(): void
    {
        $loan = $this->loanWithALatePeriod();
        $late = $loan->amortizationSchedules->first();

        // The nightly run has charged the late period before the payment.
        app(RepaymentService::class)->applyPenalties($loan, now());
        $late->refresh();
        $chargedBefore = (float) $late->penalty_amount;
        $before = $this->loanFigures($loan);

        // round(₱10,000 principal × 2%, 2): applyPenalties()'s figure.
        $this->assertSame(200.0, $chargedBefore);

        $repayment = $this->pay($loan, (float) $late->penalty_amount + (float) $late->total_due);
        $this->assertSame('paid', $late->fresh()->status);

        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        $late->refresh();
        $this->assertSame('overdue', $late->status);
        $this->assertSame($chargedBefore, (float) $late->penalty_amount);
        $this->assertSame($before, $this->loanFigures($loan));
    }

    public function test_a_void_leaving_a_period_that_is_not_late_unpaid_charges_no_penalty(): void
    {
        $loan = $this->createReleasedLoan(['product' => ['penalty_rate' => 2.0]]);
        $first = $loan->amortizationSchedules->first();

        $repayment = $this->pay($loan, (float) $first->total_due);
        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        $first->refresh();
        $this->assertSame('pending', $first->status);
        $this->assertSame(0.0, (float) $first->penalty_amount);
    }

    public function test_a_void_charges_no_penalty_on_a_loan_with_no_penalty_rate(): void
    {
        $loan = $this->loanWithALatePeriod(penaltyRate: 0.0);
        $late = $loan->amortizationSchedules->first();

        $repayment = $this->pay($loan, (float) $late->total_due);
        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        $late->refresh();
        $this->assertSame('overdue', $late->status);
        $this->assertSame(0.0, (float) $late->penalty_amount);
    }

    public function test_a_void_leaves_a_waived_period_with_the_charge_the_waiver_kept(): void
    {
        $loan = $this->loanWithALatePeriod();
        $late = $loan->amortizationSchedules->first();
        app(RepaymentService::class)->applyPenalties($loan, now());

        $adjustments = app(LoanAdjustmentService::class);
        $waiver = $adjustments->createAdjustment($loan, [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['schedule_ids' => [$late->id]],
        ], $this->admin);
        $adjustments->approveAdjustment($waiver, $this->admin, 'ok');
        $adjustments->applyAdjustment($waiver->fresh());

        $late->refresh();
        $keptByWaiver = (float) $late->penalty_amount;

        $repayment = $this->pay($loan, (float) $late->total_due);
        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        $late->refresh();
        $this->assertNotNull($late->penalty_waiver_id);
        $this->assertSame($keptByWaiver, (float) $late->penalty_amount);
    }

    public function test_a_void_leaves_a_period_another_payment_still_part_pays_as_it_was(): void
    {
        $loan = $this->loanWithALatePeriod();
        $late = $loan->amortizationSchedules->first();
        app(RepaymentService::class)->applyPenalties($loan, now());
        $late->refresh();
        $charged = (float) $late->penalty_amount;

        // The first payment covers the penalty and part of the interest; the
        // second, the one voided, more of the interest.
        $this->pay($loan, $charged + 50);
        $second = $this->pay($loan, 50);

        app(RepaymentService::class)->voidRepayment($second, 'Keyed twice', $this->admin);

        $late->refresh();
        $this->assertSame('partial', $late->status);
        $this->assertSame($charged, (float) $late->penalty_amount);
        $this->assertSame($charged, (float) $late->penalty_paid);
    }

    /**
     * A released ₱60,000 six-month loan whose first period fell due two months
     * ago, well past its three-day grace.
     */
    private function loanWithALatePeriod(float $penaltyRate = 2.0): Loan
    {
        return $this->createReleasedLoan([
            'product' => ['penalty_rate' => $penaltyRate, 'grace_period_days' => 3],
            'start_date' => now()->subMonths(3)->toDateString(),
        ]);
    }

    private function pay(Loan $loan, float $amount): Repayment
    {
        return app(RepaymentService::class)->processRepayment(
            Loan::findOrFail($loan->id),
            round($amount, 2),
            now()->toDateString(),
            $this->admin,
        );
    }

    /**
     * What the loan screen shows the loan owes, and every period's charge.
     *
     * @return array<string, mixed>
     */
    private function loanFigures(Loan $loan): array
    {
        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        return [
            'total_payable' => $data['total_payable'],
            'penalty_amount' => $data['penalty_amount'],
            'overdue_amount' => $data['overdue_amount'],
            'outstanding_balance' => $data['outstanding_balance'],
            'periods' => AmortizationSchedule::query()
                ->where('loan_id', $loan->id)
                ->orderBy('period_number')
                ->get(['status', 'penalty_amount', 'penalty_paid', 'principal_paid', 'interest_paid'])
                ->toArray(),
        ];
    }
}
