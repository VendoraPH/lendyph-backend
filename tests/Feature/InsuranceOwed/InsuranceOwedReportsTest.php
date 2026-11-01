<?php

namespace Tests\Feature\InsuranceOwed;

use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reports count the premium a payment collected as its own figure: cash in on
 * Cash Flow (not interest), and never income.
 */
class InsuranceOwedReportsTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function today(): string
    {
        return http_build_query(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);
    }

    public function test_cash_flow_shows_the_premium_collected_as_its_own_inflow(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $repayments = $this->getJson('/api/reports/cash-flow?'.$this->today())
            ->assertOk()
            ->json('data.inflows.repayments');

        $this->assertEqualsWithDelta(self::OWED, (float) ($repayments['insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(600.00, (float) $repayments['interest'], 0.001);
        // The parts still add up to every peso received.
        $this->assertEqualsWithDelta(1000.00, (float) $repayments['total'], 0.001);
    }

    public function test_the_income_report_never_counts_the_premium_as_interest(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $this->getJson('/api/reports/income?'.$this->today())
            ->assertOk()
            ->assertJsonPath('data.interest_income', fn ($v) => abs((float) $v - 600.00) < 0.001);
    }
}
