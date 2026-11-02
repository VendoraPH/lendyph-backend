<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
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
 * That preset carries the period's build-up, as `amount_due_now` does and as
 * the browser's old preset (the period's `total_payment`) did, so a member
 * keeps contributing it: paying it leaves the period paid and credits exactly
 * the build-up to share capital. Only when the period is the one the
 * allocation treats as current; an overdue period before it gets none, since
 * the allocation would carry that money on to the current period.
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

    private function releasedLoanWithBuildUp(?string $startDate = null, ?Borrower $borrower = null, float $principal = 60000): Loan
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
            'borrower_id' => ($borrower ?? Borrower::factory()->create(['branch_id' => $this->branch->id]))->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'scb_amount' => 100,
            'start_date' => $startDate ?? now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin);

        $loan = $loan->fresh();
        $this->assertContains($loan->status, Loan::ACTIVE_STATUSES, 'setup: the loan is released');

        return $loan;
    }

    public function test_a_build_up_loan_gets_only_the_one_period_preset_with_its_build_up(): void
    {
        $loan = $this->releasedLoanWithBuildUp();

        $summary = $this->summaryOf($loan);
        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertCount(1, $summary['advance_presets']);
        $this->assertSame(1, $summary['advance_presets'][0]['periods']);
        // ₱11,800 due plus the ₱100 build-up: period 1's server `total_payment`.
        $this->assertEqualsWithDelta(11900.00, (float) $summary['advance_presets'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta((float) $data['amortization_schedules'][0]['total_payment'], (float) $summary['advance_presets'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta((float) $data['amount_due_now']['amount'], (float) $summary['advance_presets'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(70800.00, (float) $summary['payoff_amount'], 0.001);
        $this->assertEqualsWithDelta((float) $summary['total_outstanding'], (float) $summary['payoff_amount'], 0.001);

        // Paying it leaves period 1 paid and credits exactly the build-up.
        $repayment = $this->payLoan($loan, (float) $summary['advance_presets'][0]['amount']);
        $this->assertSame('paid', AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)->sole()->status);
        $this->assertSame('pending', AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 2)->sole()->status);
        $this->assertEqualsWithDelta(100.00, (float) ShareCapitalLedger::where('repayment_id', $repayment->id)->sum('credit'), 0.001);
    }

    public function test_an_overdue_period_before_the_current_one_gets_no_build_up(): void
    {
        // Period 1 fell due 10 days ago, past the 3-day grace: a ₱200 penalty
        // (2% of ₱10,000). Period 2 is the current one.
        $loan = $this->releasedLoanWithBuildUp(now()->subMonthNoOverflow()->subDays(10)->toDateString());

        $presets = $this->summaryOf($loan)['advance_presets'];

        $this->assertCount(1, $presets);
        $this->assertEqualsWithDelta(11800.00 + 200.00, (float) $presets[0]['amount'], 0.001);

        $repayment = $this->payLoan($loan, (float) $presets[0]['amount']);
        $this->assertSame('paid', AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)->sole()->status);
        $this->assertSame('pending', AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 2)->sole()->status);
        $this->assertSame(0, ShareCapitalLedger::where('repayment_id', $repayment->id)->count());
    }

    public function test_a_borrower_who_is_not_a_member_gets_no_build_up_in_the_preset(): void
    {
        // A pending borrower is not a member yet and holds no share capital.
        // ₱61,234.57: ₱10,205.76 principal and ₱1,837.04 interest on period 1.
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);
        $loan = $this->releasedLoanWithBuildUp(borrower: $borrower, principal: 61234.57);

        $summary = $this->summaryOf($loan);
        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertCount(1, $summary['advance_presets']);
        $this->assertEqualsWithDelta(12042.80, (float) $summary['advance_presets'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta((float) $data['amount_due_now']['amount'], (float) $summary['advance_presets'][0]['amount'], 0.001);

        $repayment = $this->payLoan($loan, (float) $summary['advance_presets'][0]['amount']);

        $this->assertSame('paid', AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)->sole()->status);
        $this->assertSame('pending', AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 2)->sole()->status);
        $this->assertSame(0, ShareCapitalLedger::where('repayment_id', $repayment->id)->count());
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
