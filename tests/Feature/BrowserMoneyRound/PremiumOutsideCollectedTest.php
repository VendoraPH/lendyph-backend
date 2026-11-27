<?php

namespace Tests\Feature\BrowserMoneyRound;

use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 14 (recommended option, Decision 6 in the plan). The insurance premium a
 * payment collects is a liability payable to the insurer, not loan
 * collection: like Daily Collection and Collection Efficiency already, the
 * dashboard's `total_collected` and the performance report's `collected`
 * leave it out and state it as `insurance_collected`. The member loan
 * summary keeps it inside `total_paid` (the member did pay it) and states it
 * as `insurance_paid`.
 *
 * The loan owes a ₱400 premium; a ₱1,000 payment takes ₱400 premium and ₱600
 * interest. Today all three count ₱1,000 as collected with no split.
 */
class PremiumOutsideCollectedTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_dashboard_total_collected_leaves_the_premium_out(): void
    {
        $loan = $this->loanOwingPremium();
        $before = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');

        $this->pay($loan, 1000);

        $after = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
        $this->assertEqualsWithDelta(600.00, (float) $after['total_collected'] - (float) $before['total_collected'], 0.001);
        $this->assertEqualsWithDelta(
            self::OWED,
            (float) ($after['insurance_collected'] ?? 0) - (float) ($before['insurance_collected'] ?? 0),
            0.001,
            'insurance_collected',
        );
    }

    public function test_the_performance_report_collected_leaves_the_premium_out(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $today = now()->toDateString();
        $row = collect($this->getJson("/api/reports/performance?date_from={$today}&date_to={$today}")->assertOk()->json('data.by_branch'))
            ->firstWhere('branch_id', $loan->branch_id);

        $this->assertNotNull($row, 'setup: the branch has a row');
        $this->assertEqualsWithDelta(600.00, (float) $row['collected'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) ($row['insurance_collected'] ?? 0), 0.001, 'insurance_collected');
    }

    public function test_the_member_loan_summary_states_the_premium_paid_inside_total_paid(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $summary = $this->getJson("/api/borrowers/{$loan->borrower_id}/loan-summary")->assertOk()->json('data');

        $this->assertEqualsWithDelta(1000.00, (float) $summary['total_paid'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) ($summary['insurance_paid'] ?? 0), 0.001, 'insurance_paid');
    }
}
