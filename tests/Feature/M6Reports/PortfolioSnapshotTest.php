<?php

namespace Tests\Feature\M6Reports;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The daily portfolio snapshot (decision 2d, 2026-11-02): `portfolio:snapshot`
 * records the day's overdue count and amount (grace rule), total outstanding
 * and PAR 1/30/90, upserting one row per day; it runs at 23:55 Asia/Manila,
 * and the dashboard's overdue sparkline reads its six past days from it.
 */
class PortfolioSnapshotTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Cache::flush();
    }

    public function test_the_command_records_the_day_s_figures(): void
    {
        // Late 40 days (grace 0): overdue, PAR 1 and 30.
        $forty = $this->loan(grace: 0);
        $this->moveSchedule($forty, 1, 40);

        // Late 100 days: overdue, PAR 1, 30 and 90.
        $hundred = $this->loan(grace: 0);
        $this->moveSchedule($hundred, 1, 100);

        // Two days past its due date, inside a 3-day grace: not overdue, but
        // PAR is struck from the bare due date, so PAR 1.
        $withinGrace = $this->loan(grace: 3);
        $this->moveSchedule($withinGrace, 1, 2);

        // Due today: nothing late, in no bucket.
        $current = $this->loan(grace: 0);
        $this->moveSchedule($current, 1, 0);

        // Not collectible: in no figure.
        $closed = $this->loan(grace: 0);
        $this->moveSchedule($closed, 1, 60);
        $closed->forceFill(['status' => 'restructured'])->save();

        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $snapshot = PortfolioDailySnapshot::query()->sole();
        $summaries = collect([$forty, $hundred, $withinGrace, $current])
            ->mapWithKeys(fn (Loan $loan) => [$loan->id => $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data')]);
        $principal = fn (Loan ...$loans) => round(array_sum(array_map(fn (Loan $l) => $summaries[$l->id]['outstanding_principal'], $loans)), 2);

        $this->assertSame(Carbon::today()->toDateString(), $snapshot->snapshot_date->toDateString());
        $this->assertSame(2, $snapshot->overdue_loans_count);
        $this->assertSame($this->getJson('/api/dashboard/stats')->json('data.overdue_count'), $snapshot->overdue_loans_count);
        $this->assertEqualsWithDelta($summaries->sum('overdue_amount'), (float) $snapshot->overdue_amount, 0.001);
        $this->assertEqualsWithDelta($summaries->sum('total_outstanding'), (float) $snapshot->total_outstanding, 0.001);
        $this->assertEqualsWithDelta($principal($forty, $hundred, $withinGrace), (float) $snapshot->par_1_amount, 0.001);
        $this->assertEqualsWithDelta($principal($forty, $hundred), (float) $snapshot->par_30_amount, 0.001);
        $this->assertEqualsWithDelta($principal($hundred), (float) $snapshot->par_90_amount, 0.001);
    }

    public function test_par_counts_the_loan_s_whole_principal_from_its_oldest_unpaid_period(): void
    {
        $loan = $this->loan(grace: 0);
        $this->moveSchedule($loan, 1, 31);
        $this->moveSchedule($loan, 2, 10);

        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->json('data');
        $snapshot = PortfolioDailySnapshot::query()->sole();

        $this->assertEqualsWithDelta($summary['outstanding_principal'], (float) $snapshot->par_30_amount, 0.001, 'Every unpaid period\'s principal, not only the late one.');
        $this->assertEquals(0, (float) $snapshot->par_90_amount);
    }

    public function test_exactly_thirty_days_is_not_yet_par_30(): void
    {
        $loan = $this->loan(grace: 0);
        $this->moveSchedule($loan, 1, 30);

        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $snapshot = PortfolioDailySnapshot::query()->sole();
        $this->assertGreaterThan(0, (float) $snapshot->par_1_amount);
        $this->assertEquals(0, (float) $snapshot->par_30_amount);
    }

    public function test_a_re_run_replaces_the_day_and_adds_no_row(): void
    {
        $loan = $this->loan(grace: 0);
        $period = $this->moveSchedule($loan, 1, 10);

        $this->artisan('portfolio:snapshot')->assertSuccessful();
        $first = PortfolioDailySnapshot::query()->sole();
        $this->assertSame(1, $first->overdue_loans_count);

        $this->artisan('portfolio:snapshot')->assertSuccessful();
        $this->assertSame(1, PortfolioDailySnapshot::query()->count());
        $this->assertEquals($first->only(['overdue_loans_count', 'overdue_amount', 'total_outstanding', 'par_1_amount', 'par_30_amount', 'par_90_amount']),
            PortfolioDailySnapshot::query()->sole()->only(['overdue_loans_count', 'overdue_amount', 'total_outstanding', 'par_1_amount', 'par_30_amount', 'par_90_amount']));

        $period->update([
            'principal_paid' => $period->principal_due,
            'interest_paid' => $period->interest_due,
            'status' => 'paid',
        ]);

        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $again = PortfolioDailySnapshot::query()->sole();
        $this->assertSame(0, $again->overdue_loans_count);
        $this->assertEquals(0, (float) $again->overdue_amount);
        $this->assertEquals(0, (float) $again->par_1_amount);
    }

    public function test_the_date_option_records_that_day(): void
    {
        $loan = $this->loan(grace: 0);
        $this->moveSchedule($loan, 1, 3);

        $day = Carbon::today()->subDays(5)->toDateString();
        $this->artisan('portfolio:snapshot', ['--date' => $day])->assertSuccessful();

        $snapshot = PortfolioDailySnapshot::query()->sole();
        $this->assertSame($day, $snapshot->snapshot_date->toDateString());
        $this->assertSame(0, $snapshot->overdue_loans_count, 'Five days ago the period was not yet due.');
    }

    public function test_an_invalid_date_is_refused_and_writes_nothing(): void
    {
        $this->artisan('portfolio:snapshot', ['--date' => '2026-02-30'])->assertFailed();
        $this->artisan('portfolio:snapshot', ['--date' => 'yesterday'])->assertFailed();

        $this->assertSame(0, PortfolioDailySnapshot::query()->count());
    }

    public function test_it_is_scheduled_daily_at_23_55_manila(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'portfolio:snapshot'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('55 23 * * *', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
    }

    public function test_the_sparkline_reads_past_days_from_the_snapshots(): void
    {
        foreach ([6 => 5, 4 => 3, 1 => 2] as $daysAgo => $count) {
            $this->snapshot($daysAgo, $count);
        }
        // Outside the six past days: never read.
        $this->snapshot(7, 9);
        $this->snapshot(0, 8);

        $loan = $this->loan(grace: 0);
        $this->moveSchedule($loan, 1, 3);

        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');

        $this->assertSame(
            [5, null, 3, null, null, 2, 1],
            array_column($stats['sparklines']['overdue'], 'v'),
            'Missing days are null; today is the live card figure, not today\'s snapshot.',
        );
        $this->assertSame(1, $stats['overdue_count']);
    }

    public function test_with_no_snapshots_every_past_point_is_null(): void
    {
        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');

        $this->assertSame([null, null, null, null, null, null, 0], array_column($stats['sparklines']['overdue'], 'v'));
    }

    private function snapshot(int $daysAgo, int $count): void
    {
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'overdue_loans_count' => $count,
            'overdue_amount' => 0,
            'total_outstanding' => 0,
            'par_1_amount' => 0,
            'par_30_amount' => 0,
            'par_90_amount' => 0,
        ]);
    }

    private function loan(int $grace): Loan
    {
        return $this->createReleasedLoan(['product' => ['grace_period_days' => $grace]]);
    }

    private function moveSchedule(Loan $loan, int $periodNumber, int $daysAgo): AmortizationSchedule
    {
        $schedule = $loan->amortizationSchedules()->where('period_number', $periodNumber)->firstOrFail();
        $schedule->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);

        return $schedule->fresh();
    }
}
