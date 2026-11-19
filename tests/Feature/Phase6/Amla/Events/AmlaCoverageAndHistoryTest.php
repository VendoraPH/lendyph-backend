<?php

namespace Tests\Feature\Phase6\Amla\Events;

use App\Models\AmlaCoverageCheck;
use App\Models\AmlaEvent;
use App\Models\AmlaLogEntry;
use App\Models\Borrower;
use App\Models\GCashTransaction;
use App\Models\Repayment;
use App\Services\Amla\Events\Coverage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The nightly coverage check (AMLA design §4.5) and the history run
 * (`amla:screen-history`, on counsel's advice only): source rows against
 * events, by source and day, gaps and failures read as Unscreened; the history
 * run writes only AMLA tables, previews with --dry-run and is idempotent.
 */
class AmlaCoverageAndHistoryTest extends TestCase
{
    use AmlaEventTestHelpers, AmlaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_coverage_counts_each_sources_rows_events_and_unscreened_gaps_for_a_manila_day(): void
    {
        $this->goLive();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);
        GCashTransaction::factory()->create(['borrower_id' => $member->id, 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1, 'status' => 'paid', 'paid_at' => now()]);
        GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'pending']);
        $this->harvestLater();

        // A row the cursor passed without seeing (a late commit stamped well before the watermark).
        $this->at('2026-11-20 09:30:00');
        $late = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);
        DB::table('gcash_transactions')->where('id', $late->id)->update(['updated_at' => '2026-11-20 09:02:00', 'created_at' => '2026-11-20 09:02:00']);
        $this->harvestLater('2026-11-20 09:31:00');

        $this->at('2026-11-21 00:45:00');
        $result = app(Coverage::class)->check('2026-11-20');

        $this->assertSame(['rows' => 3, 'events' => 1, 'failed' => 1, 'unscreened' => 2, 'pending' => 1], $result['gcash']);
        $this->assertSame(['rows' => 0, 'events' => 0, 'failed' => 0, 'unscreened' => 0, 'pending' => 0], $result['repayments']);
        $stored = AmlaCoverageCheck::query()->where('source', 'gcash')->where('day', '2026-11-20')->sole();
        $this->assertSame(2, $stored->unscreened);
        $this->assertSame(8, AmlaCoverageCheck::query()->count());
    }

    public function test_the_nightly_command_checks_yesterday_and_does_nothing_while_monitoring_is_off(): void
    {
        config(['amla.monitoring_enabled' => false]);
        $this->artisan('amla:coverage-check')->expectsOutputToContain('AMLA monitoring is off')->assertSuccessful();
        $this->assertSame(0, AmlaCoverageCheck::query()->count());

        $this->goLive();
        $this->at('2026-11-21 00:45:00');
        $this->artisan('amla:coverage-check')->expectsOutputToContain('Nov 20, 2026')->assertSuccessful();
        $this->assertSame(['2026-11-20'], AmlaCoverageCheck::query()->distinct()->pluck('day')->map(fn ($d) => Carbon::parse($d)->toDateString())->all());
    }

    public function test_a_voided_payment_whose_event_isnt_marked_is_unscreened(): void
    {
        $this->goLive();
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['payment_date' => '2026-11-20', 'amount_paid' => 5000, 'method' => 'cash'])->assertCreated();
        $this->harvestLater();
        $repayment = Repayment::query()->where('loan_id', $loan->id)->sole();
        $this->at('2026-11-20 10:00:00');
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Duplicate entry'])->assertOk();

        $this->at('2026-11-21 00:45:00');
        $before = app(Coverage::class)->check('2026-11-20', store: false);
        $this->harvestLater('2026-11-21 00:46:00');
        $after = app(Coverage::class)->check('2026-11-20', store: false);

        $this->assertSame(1, $before['repayment_voids']['unscreened']);
        $this->assertSame(0, $after['repayment_voids']['unscreened']);
    }

    public function test_the_history_dry_run_writes_nothing_and_the_real_run_takes_only_rows_from_before_go_live(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->at('2026-11-18 14:00:00');
        $old = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now(), 'total_amount' => 1020]);
        $this->at('2026-11-10 14:00:00');
        $tooOld = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);
        $this->goLive();
        $new = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'status' => 'paid', 'paid_at' => now()]);
        $this->harvestLater();

        $sums = $this->sourceChecksums();
        $outside = $this->outsideAmlaCounts();
        $events = AmlaEvent::query()->count();
        $log = AmlaLogEntry::query()->count();

        $this->artisan('amla:screen-history', ['--from' => '2026-11-15', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('GCash: 1 to add')
            ->assertSuccessful();
        $this->assertSame($events, AmlaEvent::query()->count());
        $this->assertSame($log, AmlaLogEntry::query()->count());

        $this->artisan('amla:screen-history', ['--from' => '2026-11-15'])->assertSuccessful();
        $this->artisan('amla:screen-history', ['--from' => '2026-11-15'])->expectsOutputToContain('GCash: 0 to add')->assertSuccessful();

        $history = $this->soleEvent('gcash', $old->id);
        $this->assertSame('history', $history->harvested_by);
        $this->assertSame('1020.00', $history->amount);
        $this->assertSame([], $this->eventsFor('gcash', $tooOld->id));
        $this->assertSame('monitor', $this->soleEvent('gcash', $new->id)->harvested_by);
        $this->assertSame($sums, $this->sourceChecksums());
        $this->assertNothingWrittenOutsideAmla($outside);

        $entries = AmlaLogEntry::query()->where('action', 'history_screened')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('system', $entries[0]->actor_type);
        $this->assertSame(1, $entries[0]->details['sources']['gcash']['added']);
    }

    public function test_the_history_run_refuses_while_monitoring_is_off_or_before_go_live(): void
    {
        config(['amla.monitoring_enabled' => false]);
        $this->artisan('amla:screen-history', ['--from' => '2026-11-01'])->expectsOutputToContain('AMLA monitoring is off')->assertFailed();

        $this->monitoringOn();
        $this->artisan('amla:screen-history', ['--from' => '2026-11-01'])->expectsOutputToContain('has not started')->assertFailed();
        $this->artisan('amla:screen-history', ['--from' => 'yesterday'])->expectsOutputToContain('YYYY-MM-DD')->assertFailed();
        $this->assertSame(0, AmlaEvent::query()->count());
    }

    public function test_the_harvester_runs_every_minute_and_the_coverage_check_nightly_in_manila(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command)) => [$event->expression, $event->timezone, $event->withoutOverlapping]]);

        $this->assertSame(['* * * * *', null, true], [$events['amla:harvest'][0], null, $events['amla:harvest'][2]]);
        $this->assertSame('45 0 * * *', $events['amla:coverage-check'][0]);
        $this->assertSame('Asia/Manila', (string) $events['amla:coverage-check'][1]);
    }
}
