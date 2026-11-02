<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Borrower;
use App\Models\ShareCapitalPledge;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 4. Totals the browser adds up today come from the server, over the
 * whole filtered set rather than the page in hand:
 *  - GET /loans `meta.totals` (`total_outstanding`, `overdue_amount`) for the
 *    /payments "Total Outstanding" card;
 *  - GET /borrowers/{id}/loan-summary `overdue_amount` for the member's
 *    Payments tab (which today shows every overdue loan's whole outstanding
 *    balance as "Overdue");
 *  - GET /pledges `meta.totals.amount` for the pledges page total.
 */
class ListTotalsTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_loan_list_totals_cover_every_matching_loan_not_just_the_page(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $first = $this->releasedLoanFor($borrower, 60000);
        $second = $this->releasedLoanFor($borrower, 30000);
        $this->payLoan($first, 11800);

        $expected = (float) $this->summaryOf($first)['outstanding_balance'] + (float) $this->summaryOf($second)['outstanding_balance'];

        $totals = $this->getJson("/api/loans?status=active&borrower_id={$borrower->id}&per_page=1")
            ->assertOk()
            ->json('meta.totals');

        $this->assertIsArray($totals, 'meta.totals is missing');
        $this->assertEqualsWithDelta($expected, (float) $totals['total_outstanding'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $totals['overdue_amount'], 0.001);
    }

    public function test_the_member_loan_summary_states_the_amount_overdue(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00'));
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        // Started 2026-06-01: periods due 07-01, 08-01 and 09-01; the first two
        // are past grace, ₱11,800 each, and the third is due today.
        $this->releasedLoanFor($borrower, 60000, '2026-06-01');

        $summary = $this->getJson("/api/borrowers/{$borrower->id}/loan-summary")->assertOk()->json('data');

        $this->assertEqualsWithDelta(23600.00, (float) ($summary['overdue_amount'] ?? -1), 0.001, 'loan-summary.overdue_amount');
    }

    public function test_the_pledge_list_totals_cover_every_pledge_not_just_the_page(): void
    {
        ShareCapitalPledge::factory()->count(3)->create(['amount' => 250.50]);

        $expected = (float) ShareCapitalPledge::query()->sum('amount');

        $total = $this->getJson('/api/pledges?per_page=1')->assertOk()->json('meta.totals.amount');

        $this->assertNotNull($total, 'meta.totals.amount is missing');
        $this->assertEqualsWithDelta($expected, (float) $total, 0.001);
    }
}
