<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Auto-pay charges the premium owed on top of the selected periods, so the
 * premium is collected first and the periods are still settled. The preview
 * shows it per loan and in the summary, and its totals are what a run posts.
 */
class InsuranceOwedAutoPayTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function autoPayLoanOwingPremium(): Loan
    {
        $loan = $this->loanOwingPremium();
        $loan->update([
            'auto_pay' => true,
            'cbs_reference' => 'CBS-2026-00001',
            'auto_pay_enabled_at' => now(),
            'auto_pay_enabled_by' => $this->admin->id,
        ]);

        return $loan->fresh('amortizationSchedules');
    }

    /** @return array{date_from: string, date_to: string} */
    private function firstPeriodWindow(Loan $loan): array
    {
        $due = $loan->amortizationSchedules->sortBy('period_number')->first()->due_date->toDateString();

        return ['date_from' => $due, 'date_to' => $due];
    }

    public function test_the_preview_adds_the_owed_premium_to_what_a_run_charges(): void
    {
        $loan = $this->autoPayLoanOwingPremium();

        $data = $this->getJson('/api/auto-pay/preview?'.http_build_query($this->firstPeriodWindow($loan)))
            ->assertOk()
            ->json('data');

        $row = collect($data['loans'])->firstWhere('loan_id', $loan->id);
        $this->assertNotNull($row, 'setup: the loan should be in the preview');

        $this->assertEqualsWithDelta(self::OWED, (float) ($row['total_insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(self::PERIOD_DUE + self::OWED, (float) $row['total_amount'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) ($data['summary']['total_insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(self::PERIOD_DUE + self::OWED, (float) $data['summary']['grand_total'], 0.001);
    }

    public function test_a_run_collects_the_premium_first_and_still_settles_the_selected_period(): void
    {
        $loan = $this->autoPayLoanOwingPremium();

        $this->postJson('/api/auto-pay/process', $this->firstPeriodWindow($loan))
            ->assertCreated()
            ->assertJsonPath('data.processed', 1);

        $repayment = Repayment::where('loan_id', $loan->id)->firstOrFail();

        $this->assertEqualsWithDelta(self::PERIOD_DUE + self::OWED, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        $first = AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')->first();
        $this->assertSame('paid', $first->status);
    }
}
