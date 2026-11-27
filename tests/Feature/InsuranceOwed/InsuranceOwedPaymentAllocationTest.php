<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AmortizationSchedule;
use App\Services\RepaymentService;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A payment on a loan that still owes insurance premium takes the premium
 * first (before penalty, interest and principal), records what it took on
 * the repayment (`repayments.insurance_applied`) and lowers the premium owed
 * (`loans.insurance_remaining_balance`). A void puts back exactly that.
 */
class InsuranceOwedPaymentAllocationTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_payment_collects_the_owed_premium_before_anything_else(): void
    {
        $loan = $this->loanOwingPremium();

        $repayment = $this->pay($loan, 1000);

        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->fresh()->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(600.00, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->principal_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        $first = $loan->amortizationSchedules()->reorder('period_number')->first();
        $this->assertEqualsWithDelta(600.00, (float) $first->interest_paid, 0.001);
    }

    public function test_the_premium_is_collected_before_a_late_penalty(): void
    {
        // Period 1 fell due ten days ago, past the 3-day grace: the payment's
        // penalty step charges it, but the premium still comes first.
        $loan = $this->loanOwingPremium(startDate: now()->subMonth()->subDays(10)->toDateString());

        $repayment = $this->pay($loan, self::OWED);

        $first = $loan->amortizationSchedules()->reorder('period_number')->first();
        $this->assertGreaterThan(0, (float) $first->penalty_amount, 'setup: period 1 should be charged a penalty');

        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->fresh()->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $first->penalty_paid, 0.001);
    }

    public function test_collecting_the_premium_charges_no_interest_and_never_touches_principal(): void
    {
        $loan = $this->loanOwingPremium();

        // The premium was never folded into the schedule.
        $this->assertEqualsWithDelta(60000.00, (float) $loan->amortizationSchedules->sum('principal_due'), 0.001);
        $this->assertEqualsWithDelta(10800.00, (float) $loan->amortizationSchedules->sum('interest_due'), 0.001);

        $repayment = $this->pay($loan, self::OWED);

        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->fresh()->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->principal_applied, 0.001);
        $this->assertSame(0, $repayment->allocations()->count(), 'a premium-only payment puts nothing on any period');

        // Principal and interest owed are exactly as before; only the premium went.
        $loan->refresh();
        $this->assertEqualsWithDelta(60000.00, (float) $loan->outstanding_balance, 0.001);
        $this->assertEqualsWithDelta(10800.00, (float) $loan->amortizationSchedules()->sum('interest_due'), 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $loan->amortizationSchedules()->sum('interest_paid'), 0.001);
    }

    public function test_a_payment_smaller_than_the_premium_leaves_the_rest_owed_and_the_next_payment_takes_it_first(): void
    {
        $loan = $this->loanOwingPremium();

        $first = $this->pay($loan, 150);

        $this->assertEqualsWithDelta(150.00, (float) $first->fresh()->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(250.00, $this->owed($loan), 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $first->interest_applied, 0.001);

        $second = $this->pay($loan, 1000);

        $this->assertEqualsWithDelta(250.00, (float) $second->fresh()->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(750.00, (float) $second->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);
    }

    public function test_voiding_a_payment_puts_back_exactly_the_premium_it_collected(): void
    {
        $loan = $this->loanOwingPremium();

        $this->pay($loan, 150);
        $second = $this->pay($loan, 1000);

        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        app(RepaymentService::class)->voidRepayment($second->fresh(), 'Keyed twice', $this->admin);

        // Only the ₱250 the voided payment took; the first payment's ₱150 stands.
        $this->assertEqualsWithDelta(250.00, $this->owed($loan), 0.001);
        $this->assertSame('voided', $second->fresh()->status);

        $period = AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')->first();
        $this->assertEqualsWithDelta(0.00, (float) $period->interest_paid, 0.001);
        $this->assertSame('pending', $period->status);
    }

    public function test_the_loan_and_payment_resources_expose_the_owed_premium(): void
    {
        $loan = $this->loanOwingPremium();

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.insurance_owed', fn ($v) => abs((float) $v - self::OWED) < 0.001)
            // "Total Payable" is the amount still owed, premium included.
            ->assertJsonPath('data.total_payable', fn ($v) => abs((float) $v - (self::SCHEDULE_TOTAL + self::OWED)) < 0.001);

        $repayment = $this->pay($loan, 1000);

        $this->getJson("/api/repayments/{$repayment->id}")
            ->assertOk()
            ->assertJsonPath('data.insurance_applied', fn ($v) => abs((float) $v - self::OWED) < 0.001);

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.insurance_owed', fn ($v) => abs((float) $v) < 0.001);
    }
}
