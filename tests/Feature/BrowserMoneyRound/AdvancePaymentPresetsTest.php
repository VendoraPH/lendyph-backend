<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 1. The loan page's Record Payment presets ("N periods (₱X)", "Pay all
 * remaining") come from GET /loans/{id}/summary: `advance_presets` (one
 * `{periods, amount}` per open period, in allocation order) and
 * `payoff_amount`. Each amount is what a payment must be to clear that many
 * open periods after the premium owed and any penalty it takes first, from
 * each period's REMAINING balance, so paying it leaves those periods paid.
 *
 * Today the browser adds up each period's full `total_payment`: on a loan
 * owing a ₱400 premium, "1 period (₱11,800.00)" leaves period 1 ₱400 short.
 */
class AdvancePaymentPresetsTest extends TestCase
{
    use BuildsReleasedLoans, OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_presets_take_the_premium_owed_first_and_pay_off_equals_everything_owed(): void
    {
        $loan = $this->loanOwingPremium();

        $summary = $this->summaryOf($loan);

        $this->assertIsArray($summary['advance_presets'] ?? null, 'summary.advance_presets is missing');
        $this->assertCount(6, $summary['advance_presets']);
        $this->assertSame(1, $summary['advance_presets'][0]['periods']);
        $this->assertEqualsWithDelta(self::PERIOD_DUE + self::OWED, (float) $summary['advance_presets'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(2 * self::PERIOD_DUE + self::OWED, (float) $summary['advance_presets'][1]['amount'], 0.001);
        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL + self::OWED, (float) ($summary['payoff_amount'] ?? 0), 0.001);
    }

    public function test_a_partly_paid_period_counts_only_what_it_still_owes(): void
    {
        $loan = $this->releasedLoanFor();
        // ₱5,000: ₱1,800 interest and ₱3,200 principal on period 1.
        $this->payLoan($loan, 5000);

        $presets = $this->summaryOf($loan)['advance_presets'] ?? null;

        $this->assertIsArray($presets, 'summary.advance_presets is missing');
        $this->assertEqualsWithDelta(6800.00, (float) $presets[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(6800.00 + 11800.00, (float) $presets[1]['amount'], 0.001);
    }

    public function test_paying_the_one_period_preset_leaves_that_period_paid(): void
    {
        $loan = $this->loanOwingPremium();
        $amount = (float) ($this->summaryOf($loan)['advance_presets'][0]['amount'] ?? self::PERIOD_DUE);

        $this->payLoan($loan, $amount);

        $period1 = AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)->sole();
        $this->assertSame('paid', $period1->status);
        $this->assertEqualsWithDelta(0.0, $this->owed($loan), 0.001);
    }
}
