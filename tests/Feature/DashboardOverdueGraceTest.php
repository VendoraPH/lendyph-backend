<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
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
        // Five days ago with three days' grace, it first became late two days ago.
        $this->assertSame([0, 0, 0, 0, 0, 1, 1], $points);
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
