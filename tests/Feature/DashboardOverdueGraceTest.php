<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use Carbon\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The dashboard's overdue count uses the grace rule (decision 5,
 * 2026-11-02): collectible loans with an unpaid period past the due date plus
 * the loan's grace, the rule GET /loans `meta.totals.overdue_count` counts
 * by. A period's stored `overdue` status is not what decides it.
 */
class DashboardOverdueGraceTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_overdue_count_equals_the_loans_list_count(): void
    {
        // Past grace, never stamped `overdue`: the period still reads pending.
        $unstamped = $this->loan(grace: 3);
        $this->moveSchedule($unstamped, 1, 20);
        $this->assertSame('pending', $unstamped->amortizationSchedules()->where('period_number', 1)->value('status'));

        $alsoUnstamped = $this->loan(grace: 0);
        $this->moveSchedule($alsoUnstamped, 1, 3);

        // Past grace, stamped.
        $stamped = $this->loan(grace: 0);
        $this->moveSchedule($stamped, 1, 10, 'overdue');

        // Due, but inside grace: not late.
        $withinGrace = $this->loan(grace: 7);
        $this->moveSchedule($withinGrace, 1, 2);

        // Stamped `overdue`, but its due date is still inside grace.
        $stampedEarly = $this->loan(grace: 7);
        $this->moveSchedule($stampedEarly, 1, 1, 'overdue');

        $listed = $this->getJson('/api/loans')->assertOk()->json('meta.totals.overdue_count');
        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');

        $this->assertSame(3, $listed);
        $this->assertSame($listed, $stats['overdue_count']);
        $this->assertSame($listed, $stats['overdue_loans_count']);
    }

    public function test_a_loan_that_is_no_longer_collectible_is_not_counted(): void
    {
        $loan = $this->loan(grace: 0);
        $this->moveSchedule($loan, 1, 10);
        $loan->forceFill(['status' => 'restructured'])->save();

        $this->assertSame(0, $this->getJson('/api/dashboard/stats')->assertOk()->json('data.overdue_count'));
    }

    public function test_the_sparkline_s_last_point_is_the_card_s_figure(): void
    {
        $loan = $this->loan(grace: 3);
        $this->moveSchedule($loan, 1, 5);

        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
        $points = array_column($stats['sparklines']['overdue'], 'v');

        $this->assertCount(7, $points);
        $this->assertSame(1, $stats['overdue_count']);
        $this->assertSame($stats['overdue_count'], end($points), 'Today is the last point.');
        // The past days come from the daily snapshots (decision 2d), and
        // there are none here: null, not a figure.
        $this->assertSame([null, null, null, null, null, null, 1], $points);
    }

    /**
     * The past points are the daily snapshots (decision 2d), never today's
     * payment state replayed against an old date: with no snapshot recorded
     * they are null, and paying a loan moves only today's point.
     */
    public function test_a_loan_paid_since_leaves_the_past_points(): void
    {
        $loan = $this->loan(grace: 0);
        $period = $this->moveSchedule($loan, 1, 5);
        $period->update([
            'principal_paid' => $period->principal_due,
            'interest_paid' => $period->interest_due,
            'status' => 'paid',
        ]);

        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');

        $this->assertSame(0, $stats['overdue_count']);
        $this->assertSame([null, null, null, null, null, null, 0], array_column($stats['sparklines']['overdue'], 'v'));
    }

    /**
     * The past points are cached for ten minutes; today's is the card's live
     * figure, so a loan that turns late inside that window moves both.
     */
    public function test_today_s_point_is_live_while_the_past_points_are_cached(): void
    {
        $this->getJson('/api/dashboard/stats')->assertOk();

        $loan = $this->loan(grace: 0);
        $this->moveSchedule($loan, 1, 3);
        // Recorded after the points were cached: not served until they expire.
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => Carbon::yesterday()->toDateString(),
            'overdue_loans_count' => 4,
            'overdue_amount' => 0,
            'total_outstanding' => 0,
            'par_1_amount' => 0,
            'par_30_amount' => 0,
            'par_90_amount' => 0,
        ]);

        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
        $points = array_column($stats['sparklines']['overdue'], 'v');

        $this->assertSame(1, $stats['overdue_count']);
        $this->assertSame([null, null, null, null, null, null, 1], $points, 'Past points come from the cache; today is live.');
    }

    private function loan(int $grace): Loan
    {
        return $this->createReleasedLoan(['product' => ['grace_period_days' => $grace]]);
    }

    private function moveSchedule(Loan $loan, int $periodNumber, int $daysAgo, ?string $status = null): AmortizationSchedule
    {
        $schedule = $loan->amortizationSchedules()->where('period_number', $periodNumber)->firstOrFail();
        $schedule->update(array_filter([
            'due_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'status' => $status,
        ]));

        return $schedule->fresh();
    }
}
