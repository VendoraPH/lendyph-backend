<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AmortizationSchedule;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The less common paths: voiding the payment that completed a loan owing
 * premium, and an auto-pay run that charges a loan only through a selected
 * part-paid period.
 */
class InsuranceOwedEdgeCasesTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_voiding_the_payment_that_completed_the_loan_reopens_it_with_the_premium_owed(): void
    {
        $loan = $this->loanOwingPremium();

        $repayment = $this->pay($loan, self::SCHEDULE_TOTAL + self::OWED);
        $this->assertSame('completed', $loan->fresh()->status);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        app(RepaymentService::class)->voidRepayment($repayment->fresh(), 'Wrong loan', $this->admin);

        $this->assertSame('released', $loan->fresh()->status);
        $this->assertEqualsWithDelta(self::OWED, $this->owed($loan), 0.001);
    }

    public function test_a_premium_only_payment_completes_a_loan_whose_periods_are_settled(): void
    {
        $loan = $this->loanOwingPremium();

        // Every period settled before the change (fixture); premium still owed.
        $loan->amortizationSchedules()->update([
            'principal_paid' => DB::raw('principal_due'),
            'interest_paid' => DB::raw('interest_due'),
            'status' => 'paid',
        ]);
        $loan->update(['status' => 'ongoing']);

        $data = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => self::OWED,
            'payment_date' => now()->toDateString(),
        ])->assertOk()->json('data');

        $this->assertEqualsWithDelta(self::OWED, (float) ($data['allocated_to_insurance'] ?? 0), 0.001);
        $this->assertSame('exact', $data['payment_badge']['type']);

        $repayment = $this->pay($loan, self::OWED);

        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->fresh()->insurance_applied, 0.001);
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_auto_pay_charges_the_premium_on_a_loan_charged_only_through_a_selected_part_paid_period(): void
    {
        $loan = $this->loanOwingPremium();
        $loan->update([
            'auto_pay' => true,
            'cbs_reference' => 'CBS-2026-00002',
            'auto_pay_enabled_at' => now(),
            'auto_pay_enabled_by' => $this->admin->id,
        ]);

        $first = AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')->firstOrFail();
        $first->update(['principal_paid' => 100.00, 'status' => 'partial']);
        $window = [
            'date_from' => $first->due_date->toDateString(),
            'date_to' => $first->due_date->toDateString(),
            'include_schedule_ids' => [$first->id],
        ];

        $summary = $this->getJson('/api/auto-pay/preview?'.http_build_query($window))
            ->assertOk()
            ->json('data.summary');

        // ₱11,700 left on the period plus the ₱400 premium.
        $this->assertEqualsWithDelta(self::OWED, (float) ($summary['total_insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(11700.00 + self::OWED, (float) $summary['partial_total'], 0.001);
        $this->assertEqualsWithDelta(11700.00 + self::OWED, (float) $summary['grand_total'], 0.001);

        $this->postJson('/api/auto-pay/process', $window)->assertCreated()->assertJsonPath('data.processed', 1);

        $repayment = Repayment::where('loan_id', $loan->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $summary['grand_total'], (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->insurance_applied, 0.001);
        $this->assertSame('paid', $first->fresh()->status);
    }
}
