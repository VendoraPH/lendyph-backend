<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AmortizationSchedule;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The completion guard: a loan never becomes `completed` while premium is
 * still owed on it, whichever path settles its last period, and it completes
 * once the premium is paid.
 */
class InsuranceOwedCompletionTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_paying_the_schedule_total_but_not_the_premium_keeps_the_loan_open(): void
    {
        $loan = $this->loanOwingPremium();

        $this->pay($loan, self::SCHEDULE_TOTAL);

        // The premium went first, so the last period is ₱400 short.
        $this->assertSame('ongoing', $loan->fresh()->status);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        $last = AmortizationSchedule::where('loan_id', $loan->id)->orderByDesc('period_number')->first();
        $this->assertSame('partial', $last->status);
    }

    public function test_the_payment_that_clears_premium_and_schedule_completes_the_loan(): void
    {
        $loan = $this->loanOwingPremium();

        $repayment = $this->pay($loan, self::SCHEDULE_TOTAL + self::OWED);

        $this->assertSame('completed', $loan->fresh()->status);
        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->fresh()->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->overpayment, 0.001);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);
    }

    public function test_a_balance_adjustment_that_settles_the_last_period_does_not_complete_a_loan_still_owing_premium(): void
    {
        $loan = $this->loanOwingPremium();

        // Periods 1-5 settled and period 6's interest paid (fixture), leaving
        // ₱10,000 of principal and the ₱400 premium.
        $schedules = $loan->amortizationSchedules()->reorder('period_number')->get();
        foreach ($schedules as $schedule) {
            $last = $schedule->period_number === $schedules->max('period_number');
            $schedule->update([
                'principal_paid' => $last ? 0 : $schedule->principal_due,
                'interest_paid' => $schedule->interest_due,
                'status' => $last ? 'partial' : 'paid',
            ]);
        }
        $loan->update(['status' => 'ongoing']);

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['target_principal_balance' => 0, 'reason' => 'Agreed settlement'],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $this->assertSame(0, AmortizationSchedule::where('loan_id', $loan->id)->whereIn('status', ['pending', 'partial', 'overdue'])->count(),
            'setup: the adjustment should settle the last period');

        $this->assertNotSame('completed', $loan->fresh()->status);
        $this->assertEqualsWithDelta(self::OWED, $this->owed($loan), 0.001);

        // Paying the premium is what completes it.
        $repayment = $this->pay($loan, self::OWED);

        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->fresh()->insurance_applied, 0.001);
        $this->assertSame('completed', $loan->fresh()->status);
    }
}
