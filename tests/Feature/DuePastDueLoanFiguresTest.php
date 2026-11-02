<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Branch;
use App\Models\Loan;
use App\Services\LoanAdjustmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Past Due Loans page shows only server figures (decision 3, 2026-11-02):
 * each GET /reports/due-past-due row carries its loan's everything-owed
 * figure, its late-and-past-grace amount and its last posted payment date,
 * and the totals add each loan's figures once, however many of its periods
 * are listed.
 */
class DuePastDueLoanFiguresTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_each_row_carries_its_loan_s_summary_figures(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 3);
        $this->moveSchedule($loan, 1, 40);
        $this->moveSchedule($loan, 2, 10);

        // A part payment, smaller than period 1's total, so both periods stay
        // unpaid and late.
        $this->pay($loan, 4000, Carbon::today()->subDays(5));

        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');
        $listed = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $loan->id);

        $rows = collect($this->getJson('/api/reports/due-past-due?per_page=100')->assertOk()->json('data'))
            ->where('loan_id', $loan->id)
            ->values();

        $this->assertCount(2, $rows, 'Both late periods are listed.');
        $this->assertGreaterThan(0, $summary['overdue_amount']);

        foreach ($rows as $row) {
            $this->assertEquals($summary['overdue_amount'], $row['loan_past_due_amount']);
            $this->assertEquals($summary['total_outstanding'], $row['loan_total_outstanding']);
            $this->assertEquals($listed['total_outstanding'], $row['loan_total_outstanding']);
            $this->assertSame(Carbon::today()->subDays(5)->toDateString(), $row['loan_last_payment_date']);
        }
    }

    public function test_the_totals_count_each_loan_once(): void
    {
        $first = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($first, 1, 40);
        $this->moveSchedule($first, 2, 20);
        $this->moveSchedule($first, 3, 5);

        $second = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($second, 1, 15);

        $response = $this->getJson('/api/reports/due-past-due?per_page=1')->assertOk();

        $this->assertSame(4, $response->json('totals.count'), 'Four periods are listed.');

        $firstSummary = $this->getJson("/api/loans/{$first->id}/summary")->json('data');
        $secondSummary = $this->getJson("/api/loans/{$second->id}/summary")->json('data');

        $this->assertEqualsWithDelta(
            $firstSummary['total_outstanding'] + $secondSummary['total_outstanding'],
            $response->json('totals.total_outstanding'),
            0.001,
        );
        $this->assertEqualsWithDelta(
            $firstSummary['overdue_amount'] + $secondSummary['overdue_amount'],
            $response->json('totals.past_due_amount'),
            0.001,
        );
    }

    public function test_the_totals_follow_the_report_filters(): void
    {
        $inRange = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($inRange, 1, 10);

        $outOfRange = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($outOfRange, 1, 40);

        $from = Carbon::today()->subDays(20)->toDateString();
        $response = $this->getJson("/api/reports/due-past-due?date_from={$from}")->assertOk();

        $summary = $this->getJson("/api/loans/{$inRange->id}/summary")->json('data');

        $this->assertSame(1, $response->json('totals.count'));
        $this->assertEquals($summary['total_outstanding'], $response->json('totals.total_outstanding'));
        $this->assertEquals($summary['overdue_amount'], $response->json('totals.past_due_amount'));
    }

    public function test_the_last_payment_date_ignores_voided_payments(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($loan, 1, 40);
        $this->moveSchedule($loan, 2, 10);

        $this->pay($loan, 1000, Carbon::today()->subDays(20));
        $later = $this->pay($loan, 1000, Carbon::today()->subDays(3));

        $this->patchJson("/api/repayments/{$later}/void", ['void_reason' => 'Entered twice'])->assertOk();

        $rows = $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(Carbon::today()->subDays(20)->toDateString(), $row['loan_last_payment_date']);
        }
    }

    public function test_a_loan_with_no_payment_has_no_last_payment_date(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($loan, 1, 10);

        $this->getJson('/api/reports/due-past-due')
            ->assertOk()
            ->assertJsonPath('data.0.loan_last_payment_date', null);
    }

    public function test_a_loan_due_today_within_grace_has_no_past_due_amount(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 3);
        $this->moveSchedule($loan, 1, 0);

        $response = $this->getJson('/api/reports/due-past-due')->assertOk();
        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->json('data');

        $this->assertSame(0, $response->json('data.0.days_overdue'));
        // A real ₱0.00, not a missing figure.
        $this->assertNotNull($response->json('data.0.loan_past_due_amount'));
        $this->assertEquals(0, $response->json('data.0.loan_past_due_amount'));
        $this->assertGreaterThan(0, $response->json('data.0.loan_total_outstanding'));
        $this->assertEquals($summary['total_outstanding'], $response->json('data.0.loan_total_outstanding'));
        $this->assertSame(0.0, (float) $response->json('totals.past_due_amount'));
    }

    public function test_the_loan_figures_cost_no_query_per_row(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($loan, 1, 30);
        $this->pay($loan, 500, Carbon::today()->subDays(2));

        $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();
        $oneLoan = $this->countQueries(expectedRows: 1);

        for ($i = 0; $i < 3; $i++) {
            $other = $this->releasedDaysAgo(60, grace: 0);
            $this->moveSchedule($other, 1, 25);
            $this->moveSchedule($other, 2, 12);
            $this->pay($other, 500, Carbon::today()->subDays(1));
        }

        $this->assertSame($oneLoan, $this->countQueries(expectedRows: 7));
    }

    public function test_a_loan_owing_insurance_premium_counts_it_in_the_total_but_not_in_the_past_due_amount(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($loan, 1, 20);
        $loan->forceFill(['insurance_remaining_balance' => 500])->save();

        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');
        $row = $this->getJson('/api/reports/due-past-due')->assertOk()->json('data.0');

        $this->assertEquals(500, $summary['insurance_owed']);
        $this->assertEquals($summary['total_outstanding'], $row['loan_total_outstanding'], 'The ₱500.00 premium owed is part of everything owed.');
        $this->assertEquals($summary['overdue_amount'], $row['loan_past_due_amount'], 'The premium is never past due.');
        $this->assertEqualsWithDelta(
            $summary['outstanding_principal'] + $summary['outstanding_interest'] + $summary['outstanding_penalty'] + 500,
            $row['loan_total_outstanding'],
            0.001,
        );
    }

    public function test_a_penalty_partly_paid_and_the_rest_waived_matches_the_summary(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $first = $this->moveSchedule($loan, 1, 40);
        $this->moveSchedule($loan, 2, 10);

        // The payment stamps the late penalty first, then pays part of it.
        $this->pay($loan, 50, Carbon::today());

        $first->refresh();
        $this->assertGreaterThan(50, (float) $first->penalty_amount, 'The penalty must exceed what was paid for this to test anything.');
        $this->assertEquals(50, (float) $first->penalty_paid);

        $adjustments = app(LoanAdjustmentService::class);
        $waiver = $adjustments->createAdjustment($loan->fresh(), [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['schedule_ids' => [$first->id]],
        ], $this->admin);
        $adjustments->approveAdjustment($waiver, $this->admin, 'Waive the rest');
        $adjustments->applyAdjustment($waiver->fresh());

        $first->refresh();
        $this->assertNotNull($first->penalty_waiver_id, 'The waiver did not land.');
        $this->assertEquals(50, (float) $first->penalty_amount, 'The waiver brings the charge down to what was paid.');

        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');
        $rows = collect($this->getJson('/api/reports/due-past-due?per_page=100')->assertOk()->json('data'))
            ->where('loan_id', $loan->id);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertEquals($summary['overdue_amount'], $row['loan_past_due_amount']);
            $this->assertEquals($summary['total_outstanding'], $row['loan_total_outstanding']);
        }
    }

    public function test_the_totals_follow_the_branch_filter(): void
    {
        $other = Branch::factory()->create();

        $here = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($here, 1, 20);

        $there = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($there, 1, 20);
        $this->moveSchedule($there, 2, 5);
        $there->forceFill(['branch_id' => $other->id])->save();

        foreach ([[$this->branch->id, $here, 1], [$other->id, $there, 2]] as [$branchId, $loan, $rows]) {
            $response = $this->getJson("/api/reports/due-past-due?branch_id={$branchId}")->assertOk();
            $summary = $this->getJson("/api/loans/{$loan->id}/summary")->json('data');

            $this->assertSame($rows, $response->json('totals.count'));
            $this->assertEquals($summary['total_outstanding'], $response->json('totals.total_outstanding'));
            $this->assertEquals($summary['overdue_amount'], $response->json('totals.past_due_amount'));
        }
    }

    public function test_the_totals_equal_the_distinct_loans_row_figures(): void
    {
        $threeRows = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($threeRows, 1, 45);
        $this->moveSchedule($threeRows, 2, 30);
        $this->moveSchedule($threeRows, 3, 15);
        $this->pay($threeRows, 2500, Carbon::today()->subDays(10));

        $oneRow = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($oneRow, 1, 8);

        $response = $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();
        $perLoan = collect($response->json('data'))->groupBy('loan_id');

        $this->assertCount(3, $perLoan[$threeRows->id]);
        $this->assertCount(1, $perLoan[$oneRow->id]);

        $distinct = $perLoan->map(fn ($rows) => $rows->first());

        $this->assertEqualsWithDelta($distinct->sum('loan_total_outstanding'), $response->json('totals.total_outstanding'), 0.001);
        $this->assertEqualsWithDelta($distinct->sum('loan_past_due_amount'), $response->json('totals.past_due_amount'), 0.001);
    }

    public function test_another_loan_s_later_payment_does_not_leak_into_the_last_payment_date(): void
    {
        $earlier = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($earlier, 1, 30);
        $this->pay($earlier, 1000, Carbon::today()->subDays(20));

        $later = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($later, 1, 30);
        $this->pay($later, 1000, Carbon::today()->subDays(2));

        $rows = collect($this->getJson('/api/reports/due-past-due?per_page=100')->assertOk()->json('data'))->keyBy('loan_id');

        $this->assertSame(Carbon::today()->subDays(20)->toDateString(), $rows[$earlier->id]['loan_last_payment_date']);
        $this->assertSame(Carbon::today()->subDays(2)->toDateString(), $rows[$later->id]['loan_last_payment_date']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function countQueries(int $expectedRows): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/reports/due-past-due?per_page=100')
            ->assertOk()
            ->assertJsonCount($expectedRows, 'data');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /**
     * A released loan whose release is dated `$daysAgo` days back, so
     * payments can be dated after it.
     */
    private function releasedDaysAgo(int $daysAgo, int $grace): Loan
    {
        $loan = $this->createReleasedLoan(['product' => ['grace_period_days' => $grace]]);
        $loan->forceFill(['released_at' => Carbon::today()->subDays($daysAgo)])->save();

        return $loan->fresh();
    }

    private function moveSchedule(Loan $loan, int $periodNumber, int $daysAgo): AmortizationSchedule
    {
        $schedule = $loan->amortizationSchedules()->where('period_number', $periodNumber)->firstOrFail();
        $schedule->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);

        return $schedule->fresh();
    }

    private function pay(Loan $loan, float $amount, Carbon $date): int
    {
        return $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => $date->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');
    }
}
