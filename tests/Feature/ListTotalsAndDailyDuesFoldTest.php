<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\ShareCapitalPledge;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The parts of the list-totals, premium-split and daily-dues fold work that
 * the BrowserMoneyRound contract tests leave out: the overdue figures and
 * filters of GET /loans `meta.totals`, the pledge total under a filter, the
 * collections trend's premium split, the folded daily due's amounts, and
 * voided payments.
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
        [$loan, $closed, $remainder] = $this->loanWithAFoldedDueOn20260415();

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

    /**
     * ₱60,000 over six at 3% straight from 2026-01-15: periods 1 and 2 paid
     * in full, period 3 (due 2026-04-15) paid ₱5,000, then an in-place
     * restructure over six, which closes period 3 at what it collected and
     * starts the remainder on the same date.
     *
     * @return array{0: Loan, 1: AmortizationSchedule, 2: AmortizationSchedule}
     */
    private function loanWithAFoldedDueOn20260415(): array
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

        return [$loan, $closed, $remainder];
    }

    public function test_the_folded_daily_due_turns_collected_once_the_remainder_is_paid(): void
    {
        [$loan, $closed, $remainder] = $this->loanWithAFoldedDueOn20260415();

        $this->assertSame('partial', collect($this->getJson('/api/dashboard/daily-dues?date=2026-04-15')->assertOk()->json('data'))
            ->firstWhere('loan_id', $loan->id)['status'], 'setup: part paid before the remainder is paid');

        // The remainder is the earliest open period, and it is not yet late,
        // so its own total_due pays it exactly.
        $this->travelTo(Carbon::parse('2026-04-14 09:00'));
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => (float) $remainder->total_due,
            'payment_date' => '2026-04-14',
        ])->assertCreated();
        $this->assertSame('paid', $remainder->fresh()->status, 'setup: the remainder is paid');

        $response = $this->getJson('/api/dashboard/daily-dues?date=2026-04-15')->assertOk();
        $items = collect($response->json('data'))->where('loan_id', $loan->id);
        $item = $items->first();

        $this->assertCount(1, $items);
        $this->assertSame('collected', $item['status']);
        $this->assertEqualsWithDelta((float) $closed->total_due + (float) $remainder->total_due, (float) $item['amount_due'], 0.001);
        $this->assertEqualsWithDelta((float) $item['amount_due'], (float) $item['amount_collected'], 0.001);
        $this->assertSame(1, $response->json('summary.counts.collected'));
        $this->assertEquals(100, $response->json('summary.collection_rate'));
    }

    public function test_a_voided_payment_drops_out_of_every_collected_and_paid_figure(): void
    {
        $voidedLoan = $this->loanOwingPremium();
        $keptLoan = $this->loanOwingPremium();
        $today = now()->toDateString();

        $stats = fn (): array => $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
        $trend = fn (): array => collect($this->getJson('/api/dashboard/collections-trend?period=month')->assertOk()->json('data'))->last();
        $performance = fn (): array => collect($this->getJson("/api/reports/performance?date_from={$today}&date_to={$today}")->assertOk()->json('data.by_branch'))
            ->firstWhere('branch_id', $keptLoan->branch_id);
        $statsBefore = $stats();
        $trendBefore = $trend();
        $performanceBefore = $performance();
        $this->assertNotNull($performanceBefore, 'setup: the branch has a row');

        // Each ₱1,000 takes the ₱400 premium owed, then ₱600 interest.
        $voided = $this->pay($voidedLoan, 1000);
        $this->pay($keptLoan, 1000);
        app(RepaymentService::class)->voidRepayment($voided->fresh(), 'Entered on the wrong loan', $this->admin);
        $this->assertSame('voided', $voided->fresh()->status, 'setup: the payment is voided');

        // Only the kept payment counts.
        $statsAfter = $stats();
        $this->assertEqualsWithDelta(600.00, (float) $statsAfter['total_collected'] - (float) $statsBefore['total_collected'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $statsAfter['insurance_collected'] - (float) $statsBefore['insurance_collected'], 0.001);

        $trendAfter = $trend();
        $this->assertEqualsWithDelta(600.00, (float) $trendAfter['value'] - (float) $trendBefore['value'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $trendAfter['insurance_collected'] - (float) $trendBefore['insurance_collected'], 0.001);

        $performanceAfter = $performance();
        $this->assertEqualsWithDelta(600.00, (float) $performanceAfter['collected'] - (float) $performanceBefore['collected'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $performanceAfter['insurance_collected'] - (float) $performanceBefore['insurance_collected'], 0.001);

        $voidedMember = $this->getJson("/api/borrowers/{$voidedLoan->borrower_id}/loan-summary")->assertOk()->json('data');
        $this->assertEquals(0, $voidedMember['total_paid']);
        $this->assertEquals(0, $voidedMember['insurance_paid']);

        $keptMember = $this->getJson("/api/borrowers/{$keptLoan->borrower_id}/loan-summary")->assertOk()->json('data');
        $this->assertEqualsWithDelta(1000.00, (float) $keptMember['total_paid'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $keptMember['insurance_paid'], 0.001);
    }

    /**
     * `search` and `status=past_due` are the filters whose subqueries carry
     * their own placeholders (LIKE patterns, the as-of date), so the totals
     * query must take them with their bindings in the right order.
     */
    public function test_loan_list_totals_are_right_under_search_and_past_due(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00'));
        // Two loans with two periods past grace (07-01, 08-01, ₱11,800 each),
        // one with nothing due yet.
        $searched = $this->releasedLoanFor(Borrower::factory()->create(['branch_id' => $this->branch->id, 'last_name' => 'Quillfeather']), 60000, '2026-06-01');
        $otherLate = $this->releasedLoanFor(null, 60000, '2026-06-01');
        $current = $this->releasedLoanFor(null, 30000, '2026-08-20');
        $owed = fn (Loan ...$loans): float => array_sum(array_map(fn (Loan $loan): float => (float) $this->summaryOf($loan)['outstanding_balance'], $loans));

        $bySearch = $this->getJson('/api/loans?search=quillfeather')->assertOk();
        $this->assertSame([$searched->id], array_column($bySearch->json('data'), 'id'), 'setup: search finds one loan');
        $this->assertEqualsWithDelta($owed($searched), (float) $bySearch->json('meta.totals.total_outstanding'), 0.001);
        $this->assertEqualsWithDelta(23600.00, (float) $bySearch->json('meta.totals.overdue_amount'), 0.001);
        $this->assertSame(1, $bySearch->json('meta.totals.overdue_count'));

        $pastDue = $this->getJson('/api/loans?status=past_due&per_page=1')->assertOk();
        $this->assertSame(2, $pastDue->json('meta.total'), 'setup: two loans are past due');
        $this->assertEqualsWithDelta($owed($searched, $otherLate), (float) $pastDue->json('meta.totals.total_outstanding'), 0.001);
        $this->assertEqualsWithDelta(47200.00, (float) $pastDue->json('meta.totals.overdue_amount'), 0.001);
        // Both read lateness through AmortizationSchedule::pastGraceSql():
        // the Past Due tab holds the collectible loans with an unpaid period
        // past grace, and overdue_count the matched loans owing on one. With
        // no other filter they are the same loans.
        $this->assertSame(2, $pastDue->json('meta.totals.overdue_count'));
        $this->assertSame($pastDue->json('meta.stats.past_due'), $pastDue->json('meta.totals.overdue_count'));

        $both = $this->getJson('/api/loans?status=past_due&search=quillfeather')->assertOk()->json('meta.totals');
        $this->assertEqualsWithDelta($owed($searched), (float) $both['total_outstanding'], 0.001);
        $this->assertSame(1, $both['overdue_count']);

        $this->assertNotContains($current->id, array_column($this->getJson('/api/loans?status=past_due&per_page=100')->json('data'), 'id'));
    }
}
