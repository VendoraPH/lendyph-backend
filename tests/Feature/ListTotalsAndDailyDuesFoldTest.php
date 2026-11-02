<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\ShareCapitalPledge;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The parts of the list-totals, premium-split and daily-dues fold work that
 * the BrowserMoneyRound contract tests leave out: the overdue figures and
 * filters of GET /loans `meta.totals`, the pledge total under a filter, the
 * collections trend's premium split, and the folded daily due's amounts.
 */
class ListTotalsAndDailyDuesFoldTest extends TestCase
{
    use BuildsReleasedLoans, OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_loan_list_overdue_totals_add_up_the_rows_over_every_page_and_follow_the_filters(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00'));
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        // Two periods past grace (07-01, 08-01), ₱11,800 each.
        $this->releasedLoanFor($borrower, 60000, '2026-06-01');
        // Nothing due yet.
        $current = $this->releasedLoanFor($borrower, 30000, '2026-08-20');

        $all = $this->getJson("/api/loans?borrower_id={$borrower->id}&per_page=100")->assertOk();
        $rows = collect($all->json('data'));
        $totals = $all->json('meta.totals');

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(23600.00, (float) $totals['overdue_amount'], 0.001);
        $this->assertEqualsWithDelta((float) $rows->sum('overdue_amount'), (float) $totals['overdue_amount'], 0.001);
        $this->assertSame(1, $totals['overdue_count']);
        $this->assertEqualsWithDelta((float) $rows->sum('total_payable'), (float) $totals['total_outstanding'], 0.001);

        // Every page carries the totals of the whole filtered set.
        $this->assertSame($totals, $this->getJson("/api/loans?borrower_id={$borrower->id}&per_page=1&page=2")->assertOk()->json('meta.totals'));

        // A narrower filter narrows the totals with the page.
        $narrowed = $this->getJson("/api/loans?borrower_id={$borrower->id}&loan_product_id={$current->loan_product_id}")
            ->assertOk()
            ->json('meta.totals');
        $this->assertEqualsWithDelta(0.0, (float) $narrowed['overdue_amount'], 0.001);
        $this->assertSame(0, $narrowed['overdue_count']);
        $this->assertEqualsWithDelta(
            (float) $this->summaryOf($current)['outstanding_balance'],
            (float) $narrowed['total_outstanding'],
            0.001,
        );
    }

    public function test_loan_list_totals_are_zero_when_nothing_matches(): void
    {
        $totals = $this->getJson('/api/loans?search=no-such-loan-anywhere')->assertOk()->json('meta.totals');

        $this->assertEquals(['total_outstanding' => 0, 'overdue_amount' => 0, 'overdue_count' => 0], $totals);
    }

    public function test_the_loan_list_total_outstanding_includes_the_premium_owed(): void
    {
        $loan = $this->loanOwingPremium();

        $totals = $this->getJson("/api/loans?borrower_id={$loan->borrower_id}")->assertOk()->json('meta.totals');

        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL + self::OWED, (float) $totals['total_outstanding'], 0.001);
        $this->assertEqualsWithDelta((float) $this->summaryOf($loan)['outstanding_balance'], (float) $totals['total_outstanding'], 0.001);
    }

    public function test_the_pledge_total_follows_the_filters(): void
    {
        ShareCapitalPledge::factory()->count(2)->create(['amount' => 100.25, 'schedule' => '15']);
        ShareCapitalPledge::factory()->create(['amount' => 900.10, 'schedule' => '30']);

        $expected = (float) ShareCapitalPledge::query()->forMembers()->where('schedule', '30')->sum('amount');
        $this->assertGreaterThan(0, $expected, 'setup: a member pledge on the 30th');

        $total = $this->getJson('/api/pledges?schedule=30&per_page=1')->assertOk()->json('meta.totals.amount');

        $this->assertEqualsWithDelta($expected, (float) $total, 0.001);
    }

    public function test_the_collections_trend_leaves_the_premium_out_and_states_it(): void
    {
        $loan = $this->loanOwingPremium();
        $before = collect($this->getJson('/api/dashboard/collections-trend?period=month')->assertOk()->json('data'))->last();

        $this->pay($loan, 1000);

        $after = collect($this->getJson('/api/dashboard/collections-trend?period=month')->assertOk()->json('data'))->last();
        $this->assertEqualsWithDelta(600.00, (float) $after['value'] - (float) $before['value'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $after['insurance_collected'] - (float) $before['insurance_collected'], 0.001);
    }

    public function test_the_folded_daily_due_counts_the_closed_part_in_due_and_collected(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        foreach (['2026-02-10' => 11800, '2026-03-10' => 11800, '2026-04-10' => 5000] as $date => $amount) {
            $this->travelTo(Carbon::parse("{$date} 09:00"));
            $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => $amount, 'payment_date' => $date])->assertCreated();
        }

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 6],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $rows = $loan->amortizationSchedules()->whereDate('due_date', '2026-04-15')->get();
        $closed = $rows->firstWhere('closed_by_adjustment_id', '!=', null);
        $remainder = $rows->firstWhere('closed_by_adjustment_id', null);
        $this->assertNotNull($closed, 'setup: a closed period on 2026-04-15');
        $this->assertNotNull($remainder, 'setup: its remainder on 2026-04-15');

        $response = $this->getJson('/api/dashboard/daily-dues?date=2026-04-15')->assertOk();
        $item = collect($response->json('data'))->firstWhere('loan_id', $loan->id);

        $this->assertEqualsWithDelta((float) $closed->total_due + (float) $remainder->total_due, (float) $item['amount_due'], 0.001);
        $this->assertEqualsWithDelta(
            (float) $closed->principal_paid + (float) $closed->interest_paid + (float) $remainder->principal_paid + (float) $remainder->interest_paid,
            (float) $item['amount_collected'],
            0.001,
        );
        $this->assertGreaterThanOrEqual(5000.00, (float) $item['amount_collected']);
        // The remainder is unpaid, so the one due is part paid, not collected.
        $this->assertSame('partial', $item['status']);
        $this->assertSame(1, $response->json('summary.counts.partial') + $response->json('summary.counts.collected') + $response->json('summary.counts.pending'));
    }
}
