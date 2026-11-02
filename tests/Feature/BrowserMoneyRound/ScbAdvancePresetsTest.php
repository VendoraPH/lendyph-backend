<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 1, decision 2(a). On a loan with a Share Capital Build-Up the payment
 * allocation stops after the current period and turns the rest into share
 * capital, so "n periods" would not prepay. GET /loans/{id}/summary sends only
 * the 1-period preset there, plus `payoff_amount`.
 *
 * The loan: ₱60,000 over 6 monthly periods at 3% straight (₱11,800 a period,
 * ₱70,800 over the schedule), ₱100 SCB a period.
 */
class ScbAdvancePresetsTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function releasedLoanWithBuildUp(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'scb_amount' => 100,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin);

        $loan = $loan->fresh();
        $this->assertContains($loan->status, Loan::ACTIVE_STATUSES, 'setup: the loan is released');

        return $loan;
    }

    public function test_a_build_up_loan_gets_only_the_one_period_preset(): void
    {
        $loan = $this->releasedLoanWithBuildUp();

        $summary = $this->summaryOf($loan);

        $this->assertCount(1, $summary['advance_presets']);
        $this->assertSame(1, $summary['advance_presets'][0]['periods']);
        $this->assertEqualsWithDelta(11800.00, (float) $summary['advance_presets'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(70800.00, (float) $summary['payoff_amount'], 0.001);
        $this->assertEqualsWithDelta((float) $summary['total_outstanding'], (float) $summary['payoff_amount'], 0.001);

        // Paying it leaves period 1 paid.
        $this->payLoan($loan, (float) $summary['advance_presets'][0]['amount']);
        $this->assertSame('paid', AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)->sole()->status);
    }

    public function test_a_loan_without_build_up_gets_one_preset_per_open_period_and_the_last_is_the_payoff(): void
    {
        $loan = $this->releasedLoanFor();

        $summary = $this->summaryOf($loan);

        $this->assertCount(6, $summary['advance_presets']);
        $this->assertSame(range(1, 6), array_column($summary['advance_presets'], 'periods'));
        $this->assertEqualsWithDelta((float) $summary['payoff_amount'], (float) $summary['advance_presets'][5]['amount'], 0.001);
    }
}
