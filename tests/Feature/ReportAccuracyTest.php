<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use Carbon\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Value-level regression coverage for the Reports module.
 *
 * Every assertion here is about a computed figure — an amount, a count, a
 * bucket boundary, a row that must or must not be present. The bugs these cover
 * all shipped past tests that only checked the response shape.
 *
 * Loan shape used throughout (see SetupLendyPH::createReleasedLoan): 60,000
 * principal, 6 monthly periods, 3% straight interest, so every instalment is
 * 10,000 principal + 1,800 interest = 11,800 due.
 */
class ReportAccuracyTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Repayments ───────────────────────────────────────────────────────

    public function test_voided_repayment_is_excluded_from_the_repayments_report(): void
    {
        $loan = $this->createReleasedLoan();

        $voidedId = $this->postRepayment($loan, 1000);
        $postedId = $this->postRepayment($loan, 2000);

        $this->patchJson("/api/repayments/{$voidedId}/void", [
            'void_reason' => 'Duplicate receipt captured at the counter.',
        ])->assertOk();

        $this->assertSame('voided', Repayment::findOrFail($voidedId)->status);

        $response = $this->getJson('/api/reports/repayments')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$postedId], $ids, 'A voided receipt must not appear in the collections list.');
        $this->assertNotContains($voidedId, $ids);

        $this->assertSame('completed', $response->json('data.0.status'));
        $this->assertEqualsWithDelta(2000, $response->json('data.0.amount_paid'), 0.01);

        $totals = $response->json('totals');
        $this->assertSame(1, $totals['count']);
        $this->assertEqualsWithDelta(2000, $totals['total_amount_paid'], 0.01, 'Voided money is not collected money.');
        $this->assertEqualsWithDelta(
            $totals['total_amount_paid'],
            $totals['total_principal_applied'] + $totals['total_interest_applied'] + $totals['total_penalty_applied'],
            0.01,
            'Allocation totals must add back up to the cash collected.',
        );

        // The receipt is excluded from collections, not deleted — it is still
        // retrievable on demand.
        $voidedOnly = $this->getJson('/api/reports/repayments?status=voided')->assertOk();
        $this->assertSame([$voidedId], array_column($voidedOnly->json('data'), 'id'));
        $this->assertSame('voided', $voidedOnly->json('data.0.status'));
        $this->assertSame(1, $voidedOnly->json('totals.count'));
        $this->assertEqualsWithDelta(1000, $voidedOnly->json('totals.total_amount_paid'), 0.01);
    }

    public function test_repayments_report_excludes_payments_outside_the_date_range(): void
    {
        $loan = $this->createReleasedLoan();

        $today = Carbon::today()->toDateString();
        $tenDaysAgo = Carbon::today()->subDays(10)->toDateString();

        $olderId = $this->postRepayment($loan, 1500, $tenDaysAgo);
        $recentId = $this->postRepayment($loan, 2500, $today);

        $inRange = $this->getJson("/api/reports/repayments?date_from={$today}&date_to={$today}")->assertOk();
        $ids = array_column($inRange->json('data'), 'id');
        $this->assertSame([$recentId], $ids);
        $this->assertNotContains($olderId, $ids, 'A payment before date_from must not be listed.');
        $this->assertSame(1, $inRange->json('totals.count'));
        $this->assertEqualsWithDelta(2500, $inRange->json('totals.total_amount_paid'), 0.01);

        $earlier = $this->getJson("/api/reports/repayments?date_from={$tenDaysAgo}&date_to={$tenDaysAgo}")->assertOk();
        $this->assertSame([$olderId], array_column($earlier->json('data'), 'id'));
        $this->assertEqualsWithDelta(1500, $earlier->json('totals.total_amount_paid'), 0.01);

        $wholeWindow = $this->getJson("/api/reports/repayments?date_from={$tenDaysAgo}&date_to={$today}")->assertOk();
        $this->assertCount(2, $wholeWindow->json('data'));
        $this->assertEqualsWithDelta(4000, $wholeWindow->json('totals.total_amount_paid'), 0.01);
    }

    // ── Due / Past Due ───────────────────────────────────────────────────

    public function test_ongoing_loan_still_appears_in_due_past_due_and_aging(): void
    {
        $loan = $this->createReleasedLoan();
        $schedule = $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 45);

        $this->postRepayment($loan, 1000);

        // The regression: the reports filtered on status 'released' only, so a
        // loan vanished from every collection report the moment it ever paid.
        $this->assertSame('ongoing', $loan->fresh()->status);

        $duePastDue = $this->getJson('/api/reports/due-past-due')->assertOk();
        $rows = $duePastDue->json('data');

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($schedule->id, $row['id']);
        $this->assertSame($loan->id, $row['loan_id']);
        $this->assertSame(45, $row['days_overdue']);
        $this->assertSame(45, $row['days_past_due']);
        // 1,000 against an overdue period pays the 200 penalty (2% of the 10,000
        // principal still due) then 800 interest, leaving 10,000 + 1,000.
        $this->assertEqualsWithDelta(11000, $row['amount_remaining'], 0.01);
        $this->assertEqualsWithDelta($row['amount_remaining'], $row['balance'], 0.01);

        $this->assertSame(1, $duePastDue->json('totals.count'));
        $this->assertSame(1, $duePastDue->json('totals.overdue_count'));

        $aging = $this->getJson('/api/reports/aging')->assertOk();
        $this->assertSame(1, $aging->json('data.buckets.31_60.count'));
        $this->assertEqualsWithDelta(11000, $aging->json('data.buckets.31_60.amount'), 0.01);
        $this->assertEqualsWithDelta(
            $row['amount_remaining'],
            $aging->json('data.total.amount'),
            0.01,
            'Aging and Due/Past Due must agree on what this loan still owes.',
        );
    }

    public function test_defaulted_loan_appears_in_due_past_due_and_aging(): void
    {
        $loan = $this->createReleasedLoan();
        $schedule = $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 100);

        // No endpoint marks a loan defaulted, so the status is set on the model.
        $loan->update(['status' => 'defaulted']);

        $duePastDue = $this->getJson('/api/reports/due-past-due')->assertOk();
        $rows = $duePastDue->json('data');

        $this->assertCount(1, $rows, 'A defaulted loan is still collectible and must be listed.');
        $this->assertSame($schedule->id, $rows[0]['id']);
        $this->assertSame(100, $rows[0]['days_overdue']);
        $this->assertEqualsWithDelta(11800, $rows[0]['total_due'], 0.01);
        $this->assertSame(1, $duePastDue->json('totals.count'));
        $this->assertSame(1, $duePastDue->json('totals.overdue_count'));
        $this->assertEqualsWithDelta(11800, $duePastDue->json('totals.total_due'), 0.01);
        $this->assertEqualsWithDelta(10000, $duePastDue->json('totals.total_principal_due'), 0.01);
        $this->assertEqualsWithDelta(1800, $duePastDue->json('totals.total_interest_due'), 0.01);

        $aging = $this->getJson('/api/reports/aging')->assertOk();
        $this->assertSame(1, $aging->json('data.buckets.over_90.count'));
        $this->assertEqualsWithDelta(11800, $aging->json('data.buckets.over_90.amount'), 0.01);
        $this->assertEqualsWithDelta(0, $aging->json('data.buckets.1_30.amount'), 0.01);
        $this->assertEqualsWithDelta(11800, $aging->json('data.total.amount'), 0.01);
    }

    public function test_restructure_closed_loan_is_historical_but_neither_active_nor_delinquent(): void
    {
        $closed = $this->createReleasedLoan();
        $this->moveScheduleDaysAgo($closed, periodNumber: 1, daysAgo: 40);

        // Reproduce what LoanService::closeRestructuredSource() leaves behind:
        // the open schedules are cleared and the insurance zeroed BEFORE the
        // status is stamped, because the balance has moved to a new loan.
        $closed->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->delete();
        $closed->update(['status' => 'restructured', 'insurance_remaining_balance' => 0]);

        // Still money the co-op put out the door — releases and disbursement
        // reports must keep showing it.
        $releases = $this->getJson('/api/reports/releases')->assertOk();
        $this->assertSame([$closed->id], array_column($releases->json('data'), 'id'));
        $this->assertEqualsWithDelta(60000, $releases->json('totals.total_principal'), 0.01);

        $disbursements = $this->getJson('/api/reports/disbursements')->assertOk();
        $this->assertSame(1, $disbursements->json('data.loans_released'));

        // But it owes nothing and cannot be paid, so it is neither active nor
        // delinquent.
        $this->assertSame([], $this->getJson('/api/reports/due-past-due')->assertOk()->json('data'));
        $this->assertSame(0, $this->getJson('/api/reports/due-past-due')->json('totals.count'));

        $aging = $this->getJson('/api/reports/aging')->assertOk();
        $this->assertEqualsWithDelta(0, $aging->json('data.total.amount'), 0.01);
        $this->assertSame(0, $aging->json('data.total.count'));
        $this->assertEqualsWithDelta(0, $aging->json('data.buckets.31_60.amount'), 0.01);

        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk();
        $this->assertSame(1, $summary->json('data.portfolio.loan_count'), 'It was released, so it stays in the historical count.');
        $this->assertSame(0, $summary->json('data.active_loans'), 'A closed loan is not an active loan.');
        $this->assertSame(0, $summary->json('data.portfolio.active_loan_count'));
        $this->assertEqualsWithDelta(0, $summary->json('data.outstanding_balance'), 0.01, 'Its balance moved to the new loan.');
        $this->assertEqualsWithDelta(0, $summary->json('data.at_risk_amount'), 0.01);
        $this->assertEqualsWithDelta(0, $summary->json('data.par_ratio'), 0.01);
        $this->assertSame(0, $summary->json('data.overdue.loan_count'));
    }

    /**
     * The same outcome, but driven end to end through the real endpoints.
     *
     * The two tests above assert what the reports do with a closure state that
     * this test file constructs by hand. Nothing checked that
     * `LoanService::closeRestructuredSource()` actually produces that state —
     * ReportAccuracyTest simulates the closure with an update(), and
     * LoanRestructureTest never looks at a report — so a change to the closure
     * could break every delinquency report while both suites stayed green. This
     * closes that seam: restructure for real, then read the reports.
     *
     * The source loan carries an uncollected insurance premium on purpose. The
     * resource accessor adds insurance into the outstanding balance, so if the
     * closure ever stops zeroing it, a written-off loan starts reporting a
     * balance again.
     */
    public function test_restructuring_through_the_endpoint_drops_the_loan_from_every_delinquency_report(): void
    {
        $source = $this->createLoanWithUncollectedInsurance();
        $this->moveScheduleDaysAgo($source, periodNumber: 1, daysAgo: 40);

        $this->assertSame(1, $this->getJson('/api/reports/aging')->assertOk()->json('data.total.count'));
        $this->assertSame(1, $this->getJson('/api/reports/due-past-due')->assertOk()->json('totals.count'));
        $this->assertSame(1, $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data.active_loans'));

        $replacement = Loan::findOrFail(
            $this->postJson("/api/loans/{$source->id}/restructure", [
                'borrower_id' => $source->borrower_id,
                'loan_product_id' => $source->loan_product_id,
                'principal_amount' => 70000,
                'start_date' => Carbon::today()->toDateString(),
                'remarks' => 'Shortfall written off per committee resolution.',
            ])->assertCreated()->json('data.id')
        );

        $this->releaseThroughEndpoints($replacement);

        $source->refresh();
        $this->assertSame('restructured', $source->status);
        $this->assertEqualsWithDelta(0, (float) $source->insurance_remaining_balance, 0.01);
        $this->assertEqualsWithDelta(0, (float) $source->outstanding_balance, 0.01);

        $this->getJson("/api/loans/{$source->id}")->assertOk()
            ->assertJsonPath('data.status', 'restructured')
            ->assertJsonPath('data.outstanding_balance', fn ($v) => abs((float) $v) < 0.01);

        // Money that went out the door stays in the historical reports.
        $releases = $this->getJson('/api/reports/releases')->assertOk();
        $this->assertContains($source->id, array_column($releases->json('data'), 'id'));
        $this->assertSame(2, $this->getJson('/api/reports/disbursements')->assertOk()->json('data.loans_released'));

        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk();
        $this->assertSame(2, $summary->json('data.portfolio.loan_count'), 'Historical count keeps the closed loan.');

        // But it is neither active nor delinquent any more.
        $this->assertSame(1, $summary->json('data.active_loans'), 'Only the replacement is active.');
        $this->assertSame(1, $summary->json('data.portfolio.active_loan_count'));

        $aging = $this->getJson('/api/reports/aging')->assertOk();
        $this->assertSame(0, $aging->json('data.total.count'));
        $this->assertEqualsWithDelta(0, $aging->json('data.total.amount'), 0.01);

        $duePastDue = $this->getJson('/api/reports/due-past-due')->assertOk();
        $this->assertSame(0, $duePastDue->json('totals.count'));
        $this->assertSame([], $duePastDue->json('data'));
    }

    public function test_restructure_closed_loan_is_not_delinquent_even_with_a_stray_open_schedule(): void
    {
        $loan = $this->createReleasedLoan();
        $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 40);

        // Deliberately NOT clearing the schedules: this is the anomaly case — a
        // closure that half-ran, or a legacy row from before `restructured`
        // meant "closed". The status is the second line of defence, and it is
        // what this asserts: re-adding `restructured` to
        // Loan::COLLECTIBLE_STATUSES makes this test fail.
        $loan->update(['status' => 'restructured']);

        $duePastDue = $this->getJson('/api/reports/due-past-due')->assertOk();
        $this->assertSame([], $duePastDue->json('data'), 'A loan closed by a restructure owes nothing and cannot be paid.');
        $this->assertSame(0, $duePastDue->json('totals.count'));

        $aging = $this->getJson('/api/reports/aging')->assertOk();
        $this->assertEqualsWithDelta(0, $aging->json('data.total.amount'), 0.01);
        $this->assertSame(0, $aging->json('data.buckets.31_60.count'));
    }

    public function test_schedule_due_today_is_due_but_not_overdue(): void
    {
        $loan = $this->createReleasedLoan();
        $schedule = $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 0);

        $duePastDue = $this->getJson('/api/reports/due-past-due')->assertOk();
        $rows = $duePastDue->json('data');

        // Only the instalment due today: the five future ones stay out.
        $this->assertCount(1, $rows);
        $this->assertSame($schedule->id, $rows[0]['id']);
        $this->assertSame(Carbon::today()->toDateString(), $rows[0]['due_date']);
        $this->assertSame(0, $rows[0]['days_overdue'], 'Due today is not late.');
        $this->assertSame(0, $rows[0]['days_past_due']);
        $this->assertEqualsWithDelta(11800, $rows[0]['amount_remaining'], 0.01);

        $this->assertSame(1, $duePastDue->json('totals.count'));
        $this->assertSame(0, $duePastDue->json('totals.overdue_count'));
        $this->assertEqualsWithDelta(11800, $duePastDue->json('totals.total_balance'), 0.01);

        $aging = $this->getJson('/api/reports/aging')->assertOk();
        foreach (['1_30', '31_60', '61_90', 'over_90'] as $bucket) {
            $this->assertEqualsWithDelta(0, $aging->json("data.buckets.{$bucket}.amount"), 0.01, "{$bucket} must be empty — nothing is late yet.");
            $this->assertSame(0, $aging->json("data.buckets.{$bucket}.count"));
        }
        $this->assertEqualsWithDelta(0, $aging->json('data.total.amount'), 0.01);
    }

    public function test_due_past_due_date_range_excludes_schedules_outside_the_window(): void
    {
        $loan = $this->createReleasedLoan();
        $older = $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 60);
        $recent = $this->moveScheduleDaysAgo($loan, periodNumber: 2, daysAgo: 10);

        $unfiltered = $this->getJson('/api/reports/due-past-due')->assertOk();
        $this->assertSame([$older->id, $recent->id], array_column($unfiltered->json('data'), 'id'));
        $this->assertSame(2, $unfiltered->json('totals.count'));
        $this->assertEqualsWithDelta(23600, $unfiltered->json('totals.total_due'), 0.01);

        $cutoff = Carbon::today()->subDays(30)->toDateString();

        $recentOnly = $this->getJson("/api/reports/due-past-due?date_from={$cutoff}")->assertOk();
        $ids = array_column($recentOnly->json('data'), 'id');
        $this->assertSame([$recent->id], $ids);
        $this->assertNotContains($older->id, $ids, 'A schedule before date_from must not be listed.');
        $this->assertSame(1, $recentOnly->json('totals.count'));
        $this->assertEqualsWithDelta(11800, $recentOnly->json('totals.total_due'), 0.01);

        $olderOnly = $this->getJson("/api/reports/due-past-due?date_to={$cutoff}")->assertOk();
        $ids = array_column($olderOnly->json('data'), 'id');
        $this->assertSame([$older->id], $ids);
        $this->assertNotContains($recent->id, $ids, 'A schedule after date_to must not be listed.');
        $this->assertSame(1, $olderOnly->json('totals.count'));
    }

    public function test_due_past_due_export_matches_the_preview_row_for_row(): void
    {
        $loan = $this->createReleasedLoan();
        // Period order deliberately disagrees with due-date order, so the shared
        // due_date sort is what has to line the two responses up.
        $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 5);
        $this->moveScheduleDaysAgo($loan, periodNumber: 2, daysAgo: 20);
        $this->moveScheduleDaysAgo($loan, periodNumber: 3, daysAgo: 0);

        $preview = $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();
        $rows = $preview->json('data');

        $this->assertCount(3, $rows);
        $this->assertSame([20, 5, 0], array_column($rows, 'days_overdue'), 'Preview is ordered by due date.');

        $export = $this->get('/api/reports/due-past-due/export');
        $export->assertOk();
        $csv = $this->parseCsv($export->streamedContent());

        $this->assertSame([
            'Borrower', 'Loan #', 'Due Date', 'Days Overdue', 'Principal Due', 'Interest Due', 'Penalty', 'Total Due', 'Status',
        ], $csv[0]);

        $body = array_slice($csv, 1);
        $this->assertCount(count($rows), $body, 'The CSV must export exactly the previewed schedules.');

        foreach ($rows as $index => $row) {
            $this->assertSame($row['borrower_name'], $body[$index][0]);
            $this->assertSame($row['loan_account_number'], $body[$index][1]);
            $this->assertSame($row['due_date'], $body[$index][2]);
            $this->assertSame((string) $row['days_overdue'], $body[$index][3]);
            $this->assertEqualsWithDelta($row['principal_due'], (float) $body[$index][4], 0.01);
            $this->assertEqualsWithDelta($row['interest_due'], (float) $body[$index][5], 0.01);
            $this->assertEqualsWithDelta($row['penalty_amount'], (float) $body[$index][6], 0.01);
            $this->assertEqualsWithDelta($row['total_due'], (float) $body[$index][7], 0.01);
            $this->assertSame($row['status'], $body[$index][8]);
        }
    }

    // ── Aging ────────────────────────────────────────────────────────────

    public function test_aging_boundaries_land_in_exactly_one_bucket_and_sum_to_the_total(): void
    {
        $loan = $this->createReleasedLoan();

        $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 30);
        $this->moveScheduleDaysAgo($loan, periodNumber: 2, daysAgo: 60);
        $this->moveScheduleDaysAgo($loan, periodNumber: 3, daysAgo: 90);
        $this->moveScheduleDaysAgo($loan, periodNumber: 4, daysAgo: 120);
        // Due today — 0 days past due, so it belongs to no bucket at all.
        $this->moveScheduleDaysAgo($loan, periodNumber: 5, daysAgo: 0);

        $aging = $this->getJson('/api/reports/aging')->assertOk();
        $buckets = $aging->json('data.buckets');

        $this->assertSame(Carbon::today()->toDateString(), $aging->json('data.as_of_date'));

        foreach (['1_30' => 30, '31_60' => 60, '61_90' => 90, 'over_90' => 120] as $bucket => $daysOverdue) {
            $this->assertEqualsWithDelta(
                11800,
                $buckets[$bucket]['amount'],
                0.01,
                "A schedule {$daysOverdue} days past due belongs in {$bucket} and nowhere else.",
            );
            $this->assertSame(1, $buckets[$bucket]['count']);
        }

        $bucketSum = array_sum(array_column($buckets, 'amount'));
        $this->assertEqualsWithDelta(
            $aging->json('data.total.amount'),
            $bucketSum,
            0.01,
            'Buckets must reconcile with the independently queried total — no double counting, no gaps.',
        );
        // Four late instalments only: the one due today is excluded.
        $this->assertEqualsWithDelta(47200, $aging->json('data.total.amount'), 0.01);
        $this->assertSame(1, $aging->json('data.total.count'));
    }

    public function test_aging_honours_an_explicit_as_of_date(): void
    {
        $loan = $this->createReleasedLoan();
        $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 40);

        // 40 days late today, but only 10 days late as of 30 days ago.
        $asOf = Carbon::today()->subDays(30)->toDateString();

        $legacy = $this->getJson("/api/reports/aging?as_of_date={$asOf}")->assertOk();
        $this->assertSame($asOf, $legacy->json('data.as_of_date'));
        $this->assertEqualsWithDelta(11800, $legacy->json('data.buckets.1_30.amount'), 0.01);
        $this->assertEqualsWithDelta(0, $legacy->json('data.buckets.31_60.amount'), 0.01);

        $preferred = $this->getJson("/api/reports/aging?date_to={$asOf}")->assertOk();
        $this->assertSame($legacy->json('data.buckets'), $preferred->json('data.buckets'), 'date_to and as_of_date must agree.');

        $today = $this->getJson('/api/reports/aging')->assertOk();
        $this->assertEqualsWithDelta(0, $today->json('data.buckets.1_30.amount'), 0.01);
        $this->assertEqualsWithDelta(11800, $today->json('data.buckets.31_60.amount'), 0.01);
    }

    // ── Releases ─────────────────────────────────────────────────────────

    public function test_releases_rows_carry_a_real_outstanding_balance(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->getJson('/api/reports/releases')->assertOk();
        $rows = $response->json('data');

        $this->assertCount(1, $rows);
        $this->assertGreaterThan(0, $rows[0]['outstanding_balance'], 'A freshly released, unpaid loan cannot owe 0.');
        $this->assertEqualsWithDelta(60000, $rows[0]['outstanding_balance'], 0.01);

        $totals = $response->json('totals');
        $this->assertSame(1, $totals['count']);
        $this->assertEqualsWithDelta(60000, $totals['total_principal'], 0.01);
        $this->assertEqualsWithDelta($rows[0]['outstanding_balance'], $totals['total_outstanding_balance'], 0.01);
        $this->assertGreaterThan(0, $totals['total_net_proceeds']);
        $this->assertLessThan($totals['total_principal'], $totals['total_net_proceeds'], 'Net proceeds are principal less fees.');

        // And the balance tracks payments rather than echoing the principal:
        // 5,000 clears 1,800 interest and 3,200 principal.
        $this->postRepayment($loan, 5000);

        $afterPayment = $this->getJson('/api/reports/releases')->assertOk();
        $this->assertEqualsWithDelta(56800, $afterPayment->json('data.0.outstanding_balance'), 0.01);
        $this->assertEqualsWithDelta(56800, $afterPayment->json('totals.total_outstanding_balance'), 0.01);
        $this->assertEqualsWithDelta(60000, $afterPayment->json('totals.total_principal'), 0.01);
    }

    // ── Daily collection ─────────────────────────────────────────────────

    public function test_daily_collection_honours_the_requested_date_range(): void
    {
        $loan = $this->createReleasedLoan();
        // 11,800 falls due today.
        $this->moveScheduleDaysAgo($loan, periodNumber: 1, daysAgo: 0);

        $today = Carbon::today()->toDateString();
        $fiveDaysAgo = Carbon::today()->subDays(5)->toDateString();

        $this->postRepayment($loan, 1000, $fiveDaysAgo);
        $this->postRepayment($loan, 5900, $today);

        $todayOnly = $this->getJson("/api/reports/daily-collection?date_from={$today}&date_to={$today}")->assertOk();
        $this->assertSame($today, $todayOnly->json('data.date_from'));
        $this->assertSame($today, $todayOnly->json('data.date_to'));
        $this->assertSame($today, $todayOnly->json('data.date'), 'date echoes the end of the range.');
        $this->assertEqualsWithDelta(11800, $todayOnly->json('data.total_due'), 0.01);
        $this->assertEqualsWithDelta(5900, $todayOnly->json('data.total_collected'), 0.01, 'The payment five days ago is outside the window.');
        $this->assertEqualsWithDelta(50.0, $todayOnly->json('data.collection_rate'), 0.01, 'collection_rate is a whole percent.');
        $this->assertEqualsWithDelta(5900, $todayOnly->json('data.uncollected'), 0.01);

        $earlierDay = $this->getJson("/api/reports/daily-collection?date_from={$fiveDaysAgo}&date_to={$fiveDaysAgo}")->assertOk();
        $this->assertSame($fiveDaysAgo, $earlierDay->json('data.date_from'));
        $this->assertSame($fiveDaysAgo, $earlierDay->json('data.date_to'));
        $this->assertSame($fiveDaysAgo, $earlierDay->json('data.date'));
        $this->assertEqualsWithDelta(1000, $earlierDay->json('data.total_collected'), 0.01);
        $this->assertEqualsWithDelta(0, $earlierDay->json('data.total_due'), 0.01);
        $this->assertEqualsWithDelta(0, $earlierDay->json('data.collection_rate'), 0.01);

        $wholeWindow = $this->getJson("/api/reports/daily-collection?date_from={$fiveDaysAgo}&date_to={$today}")->assertOk();
        $this->assertEqualsWithDelta(6900, $wholeWindow->json('data.total_collected'), 0.01);
        $this->assertEqualsWithDelta(11800, $wholeWindow->json('data.total_due'), 0.01);
    }

    // ── Loan balance summary / PAR ───────────────────────────────────────

    public function test_at_risk_amount_and_par_ratio_count_only_loans_past_the_threshold(): void
    {
        $atRiskLoan = $this->createReleasedLoan();
        $this->moveScheduleDaysAgo($atRiskLoan, periodNumber: 1, daysAgo: 45);

        $healthyLoan = $this->createReleasedLoan();
        $healthySchedule = $this->moveScheduleDaysAgo($healthyLoan, periodNumber: 1, daysAgo: 10);

        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk();

        $this->assertSame(30, $summary->json('data.par_threshold_days'));
        $this->assertSame(2, $summary->json('data.portfolio.loan_count'));
        $this->assertSame(2, $summary->json('data.portfolio.active_loan_count'));
        $this->assertSame(2, $summary->json('data.active_loans'));
        $this->assertEqualsWithDelta(120000, $summary->json('data.portfolio.total_released'), 0.01);
        $this->assertEqualsWithDelta(120000, $summary->json('data.outstanding.principal'), 0.01);
        $this->assertEqualsWithDelta(120000, $summary->json('data.outstanding_balance'), 0.01);

        // Only the 45-day-late loan is at risk, and the numerator is its WHOLE
        // remaining principal — not just the late instalment.
        $this->assertEqualsWithDelta(60000, $summary->json('data.at_risk_amount'), 0.01);
        $this->assertEqualsWithDelta(50.0, $summary->json('data.par_ratio'), 0.01);

        // Both loans are late, so 'overdue' is deliberately wider than 'at risk'.
        $this->assertSame(2, $summary->json('data.overdue.loan_count'));
        $this->assertEqualsWithDelta(20000, $summary->json('data.overdue.principal'), 0.01);
        $this->assertEqualsWithDelta(3600, $summary->json('data.overdue.interest'), 0.01);

        $branches = $summary->json('data.by_branch');
        $this->assertCount(1, $branches);
        $this->assertSame($this->branch->id, $branches[0]['branch_id']);
        $this->assertSame(2, $branches[0]['loan_count']);
        $this->assertEqualsWithDelta(120000, $branches[0]['total_released'], 0.01);
        $this->assertEqualsWithDelta(120000, $branches[0]['outstanding_balance'], 0.01);

        // The threshold is exclusive: exactly 30 days late is still the tail of
        // the 1–30 aging bucket, not PAR>30.
        $healthySchedule->update(['due_date' => Carbon::today()->subDays(30)->toDateString()]);
        $atThreshold = $this->getJson('/api/reports/loan-balance-summary')->assertOk();
        $this->assertEqualsWithDelta(60000, $atThreshold->json('data.at_risk_amount'), 0.01);

        $healthySchedule->update(['due_date' => Carbon::today()->subDays(31)->toDateString()]);
        $pastThreshold = $this->getJson('/api/reports/loan-balance-summary')->assertOk();
        $this->assertEqualsWithDelta(120000, $pastThreshold->json('data.at_risk_amount'), 0.01);
        $this->assertEqualsWithDelta(100.0, $pastThreshold->json('data.par_ratio'), 0.01);
    }

    public function test_loan_balance_summary_branch_filter_narrows_every_figure(): void
    {
        $this->createReleasedLoan();

        $filtered = $this->getJson("/api/reports/loan-balance-summary?branch_id={$this->branch->id}")->assertOk();
        $this->assertSame(1, $filtered->json('data.portfolio.loan_count'));
        $this->assertEqualsWithDelta(60000, $filtered->json('data.outstanding.principal'), 0.01);
        $this->assertCount(1, $filtered->json('data.by_branch'));

        $otherBranch = $this->getJson('/api/reports/loan-balance-summary?branch_id=99999')->assertOk();
        $this->assertSame(0, $otherBranch->json('data.portfolio.loan_count'));
        $this->assertEqualsWithDelta(0, $otherBranch->json('data.outstanding.principal'), 0.01);
        $this->assertEqualsWithDelta(0, $otherBranch->json('data.at_risk_amount'), 0.01);
        $this->assertEqualsWithDelta(0, $otherBranch->json('data.par_ratio'), 0.01);
        $this->assertSame([], $otherBranch->json('data.by_branch'));
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_malformed_report_dates_return_422_not_500(): void
    {
        $this->getJson('/api/reports/daily-collection?date_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');

        $this->getJson('/api/reports/daily-collection?date=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->getJson('/api/reports/aging?as_of_date=garbage')
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of_date');

        $this->getJson('/api/reports/repayments?date_to=13/45/2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_to');

        $this->getJson('/api/reports/loan-balance-summary?branch_id=not-a-number')
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');

        // An inverted range is a client mistake, not an empty report.
        $this->getJson('/api/reports/due-past-due?date_from=2026-05-01&date_to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_to');

        $this->getJson('/api/reports/releases/export?date_from=garbage')
            ->assertStatus(422);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * A released loan whose insurance premium was only partly collected, so
     * `insurance_remaining_balance` is non-zero going into a restructure.
     */
    private function createLoanWithUncollectedInsurance(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $loanService = app(LoanService::class);

        $loan = $loanService->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => Carbon::today()->toDateString(),
        ], $this->admin);

        $loanService->submitForReview($loan);
        $loanService->approve($loan, $this->admin, 'Approved for testing');

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 600,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 200,
            'insurance_remaining_balance' => 400,
        ])->assertOk();

        return $loan->fresh();
    }

    /**
     * Drive an application to released through the same endpoints the frontend
     * uses. Approval needs a second user because maker-checker forbids approving
     * your own application.
     */
    private function releaseThroughEndpoints(Loan $loan): void
    {
        $approver = tap(
            User::factory()->create(),
            fn (User $user) => $user->assignRole(Role::where('name', 'admin')->firstOrFail()),
        );

        $this->patchJson("/api/loans/{$loan->id}/submit")->assertOk();

        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'ok'])->assertOk();

        $this->actingAs($this->admin);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    /**
     * Re-date one instalment relative to today so a report scenario can be set
     * up exactly, without waiting for real time to pass.
     */
    private function moveScheduleDaysAgo(Loan $loan, int $periodNumber, int $daysAgo): AmortizationSchedule
    {
        $schedule = $loan->amortizationSchedules()
            ->where('period_number', $periodNumber)
            ->firstOrFail();

        $schedule->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);

        return $schedule->fresh();
    }

    /**
     * Post a repayment through the real endpoint and return its id.
     */
    private function postRepayment(Loan $loan, float $amount, ?string $paymentDate = null): int
    {
        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => $paymentDate ?? Carbon::today()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function parseCsv(string $content): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', trim($content)) as $line) {
            if ($line === '') {
                continue;
            }

            $rows[] = str_getcsv($line, ',', '"', '\\');
        }

        return $rows;
    }
}
