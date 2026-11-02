<?php

namespace Tests\Feature\InsuranceOwed;

use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reports count the premium a payment collected as its own figure: cash in on
 * Cash Flow (not interest, overall and per branch), never income, outside
 * Daily Collection's and Collection Efficiency's money taken in, totalled on
 * the repayments report, and run through the Statement of Account's balance.
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

    public function test_cash_flow_by_branch_shows_the_premium_inside_the_branch_inflow(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $row = collect($this->getJson('/api/reports/cash-flow?'.$this->today())->assertOk()->json('data.by_branch'))
            ->firstWhere('branch_id', $loan->branch_id);

        $this->assertNotNull($row, 'setup: the branch has a row');
        $this->assertEqualsWithDelta(self::OWED, (float) ($row['inflow_insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(600.00, (float) $row['inflow_interest'], 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) $row['inflow_total'], 0.001);
    }

    public function test_daily_collection_and_collection_efficiency_leave_the_premium_out_of_the_money_taken_in(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        foreach (['daily-collection', 'collection-efficiency'] as $report) {
            $data = $this->getJson("/api/reports/{$report}?".$this->today())->assertOk()->json('data');

            $this->assertEqualsWithDelta(600.00, (float) $data['total_received'], 0.001, $report);
            $this->assertEqualsWithDelta(0.00, (float) $data['penalty_collected'], 0.001, $report);
        }

        // Reconciles with Cash Flow less its insurance line.
        $repayments = $this->getJson('/api/reports/cash-flow?'.$this->today())->assertOk()->json('data.inflows.repayments');
        $daily = $this->getJson('/api/reports/daily-collection?'.$this->today())->assertOk()->json('data');
        $this->assertSame(
            (int) round(((float) $repayments['total'] - (float) ($repayments['insurance'] ?? 0)) * 100),
            (int) round((float) $daily['total_received'] * 100),
        );
    }

    public function test_the_repayments_report_totals_the_premium_collected(): void
    {
        $this->pay($this->loanOwingPremium(), 1000);
        $this->pay($this->loanOwingPremium(), 150);

        $this->getJson('/api/reports/repayments?'.$this->today())
            ->assertOk()
            ->assertJsonPath('totals.total_insurance_applied', fn ($v) => abs((float) $v - 550.00) < 0.001);
    }

    public function test_the_statement_of_account_runs_the_premium_through_its_balance(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 150);
        $this->pay($loan, 1000);

        $data = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');

        // Principal plus the premium the release left owed.
        $this->assertEqualsWithDelta(60400.00, (float) $data['summary']['opening_balance'], 0.001);

        [$first, $second] = $data['transactions'];
        $this->assertEqualsWithDelta(150.00, (float) ($first['insurance_applied'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(60250.00, (float) $first['running_balance'], 0.001);
        $this->assertEqualsWithDelta(250.00, (float) ($second['insurance_applied'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(60000.00, (float) $second['running_balance'], 0.001);

        $this->assertEqualsWithDelta((float) $loan->fresh()->outstanding_balance, (float) $second['running_balance'], 0.001);
        $this->assertEqualsWithDelta((float) $data['summary']['principal_balance'], (float) $second['running_balance'], 0.001);
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
