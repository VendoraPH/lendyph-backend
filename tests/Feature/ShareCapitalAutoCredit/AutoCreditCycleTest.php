<?php

namespace Tests\Feature\ShareCapitalAutoCredit;

use App\Models\AutoCreditRun;
use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Services\ShareCapitalService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 8: a share capital auto-credit run is for one cycle
 * (`cycle_date`, a month's 15th or its 30th, the month's last day), as the
 * savings auto-credit picks its cycle.
 *
 * - The current cycle (the latest 15th or month's last day on or before today
 *   in Manila) or a missed one up to 92 days ago, never a future one;
 *   refused in words. A weekend cycle is run late, for its own cycle.
 * - A 15th credits "15" and "15/30" pledges, a 30th "30" and "15/30"; other
 *   schedules are skipped with the reason.
 * - A pledge is credited at most once per cycle by runs. Round 7's monthly cap
 *   still holds on top. Both count in the cycle's month, not the run's date,
 *   so a Nov 30 run done on Dec 1 counts for November.
 * - Runs made before this (no cycle) count by their row's date.
 * - The status (the run's preview) takes `?cycle_date=`, defaulting to the
 *   current cycle, and equals what the run posts.
 */
class AutoCreditCycleTest extends TestCase
{
    use SetupLendyPH;

    /** Pledges "15" (Ana), "30" (Ben) and "15/30" (Cy). */
    private Borrower $ana;

    private Borrower $ben;

    private Borrower $cy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->on('2026-11-16');

        $this->ana = $this->member('Ana', 'Santos', '15', 500);
        $this->ben = $this->member('Ben', 'Cruz', '30', 300);
        $this->cy = $this->member('Cy', 'Reyes', '15/30', 200);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_schedule_and_cycle_pairing(): void
    {
        $this->runFor('2026-11-15')
            ->assertJsonPath('data.cycle_date', '2026-11-15')
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.total_amount', 700)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.pledge_id', $this->ben->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.0.reason', 'This pledge is credited on the 30th, so the November 15 run skips it.');

        $this->on('2026-11-30');
        $this->runFor('2026-11-30')
            ->assertJsonPath('data.cycle_date', '2026-11-30')
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.total_amount', 500)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.pledge_id', $this->ana->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.0.reason', 'This pledge is credited on the 15th, so the November 30 run skips it.');

        $this->assertSame(1, $this->creditsOf($this->ana));
        $this->assertSame(1, $this->creditsOf($this->ben));
        $this->assertSame(2, $this->creditsOf($this->cy));
    }

    public function test_the_reasons_read_in_words(): void
    {
        $this->assertSame('This pledge is credited on the 30th, so the November 15 run skips it.', ShareCapitalService::otherHalfReason('30', '2026-11-15'));
        $this->assertSame('This pledge is credited on the 15th, so the February 28 run skips it.', ShareCapitalService::otherHalfReason('15', '2027-02-28'));
        $this->assertSame(
            'Credited by auto-credit for November 15 on Nov 14, 2026, so the November 15 run skips it.',
            ShareCapitalService::sameHalfReason('2026-11-15', '2026-11-14'),
        );
        $this->assertSame(
            'Credited by hand on Nov 5, 2026, so auto-credit skips it for November 2026.',
            ShareCapitalService::alreadyCreditedReason([['hand', '2026-11-05', null]], 'November 2026'),
        );
    }

    public function test_a_future_cycle_is_refused_in_words_and_posts_nothing(): void
    {
        $this->on('2026-11-14');
        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The 15th run for November opens on Nov 15.');

        // From the 16th to the 29th, the 30th's cycle is still ahead.
        foreach (['2026-11-16', '2026-11-29'] as $day) {
            $this->on($day);
            $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-11-30'])->assertUnprocessable()
                ->assertJsonPath('errors.cycle_date.0', 'The 30th run for November opens on Nov 30.');
        }

        $this->on('2026-12-01');
        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-12-15'])->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The 15th run for December opens on Dec 15.');
        $this->getJson('/api/auto-credit/status?cycle_date=2026-12-15')->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The 15th run for December opens on Dec 15.');

        $this->on('2026-12-31');
        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2027-01-15'])->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The 15th run for January 2027 opens on Jan 15.');

        $this->assertSame(0, AutoCreditRun::query()->count());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_a_cycle_must_be_a_15th_or_a_month_end_within_92_days(): void
    {
        $this->postJson('/api/auto-credit/process')->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'Choose the cycle this run is for: a 15th or a 30th.');

        foreach (['15', '2026-11-1', ['2026-11-15']] as $wrong) {
            $this->postJson('/api/auto-credit/process', ['cycle_date' => $wrong])->assertUnprocessable()
                ->assertJsonPath('errors.cycle_date.0', 'The cycle date must be written as YYYY-MM-DD.');
        }

        // Nov 30 is the 30th; Oct 30 is not (Oct 31 is).
        foreach (['2026-11-14', '2026-10-30'] as $notACycle) {
            $this->postJson('/api/auto-credit/process', ['cycle_date' => $notACycle])->assertUnprocessable()
                ->assertJsonPath('errors.cycle_date.0', "A run is for a month's 15th or its 30th (the month's last day).");
        }

        // 92 days before Nov 16 is Aug 16: Aug 31 can still be run, Aug 15 can't.
        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-08-15'])->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'A missed run can be made for a cycle up to 92 days ago.');
        $this->assertSame(0, AutoCreditRun::query()->count());

        $this->runFor('2026-08-31')->assertJsonPath('data.cycle_date', '2026-08-31');
    }

    public function test_the_same_cycle_run_twice_gives_one_credit(): void
    {
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 2);

        $this->runFor('2026-11-15')
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.total_amount', 0)
            ->assertJsonPath('data.skipped_count', 3)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::sameHalfReason('2026-11-15', '2026-11-16'))
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::otherHalfReason('30', '2026-11-15'))
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('2026-11-15', '2026-11-16'));

        $this->assertSame(1, $this->creditsOf($this->ana));
        $this->assertSame(1, $this->creditsOf($this->cy));
    }

    public function test_the_sunday_scenario_gives_two_a_month_never_three(): void
    {
        // Nov 15, 2026 is a Sunday: its run is done late, on Monday the 16th,
        // and again on Tuesday the 17th by someone who didn't know.
        $this->on('2026-11-16');
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 2);
        $this->on('2026-11-17');
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('2026-11-15', '2026-11-16'));

        // The 30th's run, and a second one by mistake.
        $this->on('2026-11-30');
        $this->runFor('2026-11-30')->assertJsonPath('data.member_count', 2);
        $this->runFor('2026-11-30')->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('2026-11-30', '2026-11-30'));

        // Both cycles on the same day: still one each.
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 0);

        $this->assertSame(2, $this->creditsOf($this->cy));
        $this->assertSame(['2026-11-16', '2026-11-30'], $this->datesOf($this->cy));
        $this->assertSame(1, $this->creditsOf($this->ana));
        $this->assertSame(1, $this->creditsOf($this->ben));
    }

    public function test_a_late_nov_30_run_on_dec_1_counts_for_november_and_decembers_cycles_still_credit(): void
    {
        // Ben's monthly pledge was credited by hand in November.
        $this->handCredit($this->ben, '2026-11-05', 'CSH-0001-2026-0007');

        $this->on('2026-12-01');
        $this->runFor('2026-11-30')
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::otherHalfReason('15', '2026-11-30'))
            // Counted in November, the cycle's month.
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::alreadyCreditedReason(
                [['hand', '2026-11-05', 'CSH-0001-2026-0007']],
                'November 2026',
            ));
        $this->assertSame(['2026-12-01'], $this->datesOf($this->cy));

        // December's cycles credit as usual: the Dec 1 row is November's.
        $this->on('2026-12-15');
        $this->runFor('2026-12-15')->assertJsonPath('data.member_count', 2);
        $this->on('2026-12-31');
        $this->runFor('2026-12-31')->assertJsonPath('data.member_count', 2);
        $this->runFor('2026-12-31')->assertJsonPath('data.member_count', 0);

        $this->assertSame(['2026-12-01', '2026-12-15', '2026-12-31'], $this->datesOf($this->cy));
        $this->assertSame(1, $this->creditsOf($this->ana, '2026-12'));
        $this->assertSame(1, $this->creditsOf($this->ben, '2026-12'));

        // November's cycles are both in for Cy: the 15th was missed, so it
        // may still be run, once.
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 2);
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('2026-11-15', '2026-12-31'));
    }

    public function test_the_hand_credit_cap_still_holds(): void
    {
        // A twice-monthly pledge credited by hand counts one instalment: the
        // 15th's run gives the second, and the 30th's finds the month full.
        $this->handCredit($this->cy, '2026-11-05', 'CSH-0001-2026-0007');
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 2);

        $this->on('2026-11-30');
        $this->runFor('2026-11-30')
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.1.pledge_id', $this->cy->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::alreadyCreditedReason([
                ['hand', '2026-11-05', 'CSH-0001-2026-0007'],
                ['auto_credit', '2026-11-16', null],
            ]));

        // A monthly pledge credited by hand isn't credited by its own half's run.
        $this->handCredit($this->ana, '2026-12-02', null);
        $this->on('2026-12-15');
        $this->runFor('2026-12-15')
            ->assertJsonPath('data.skipped.0.pledge_id', $this->ana->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::handCreditedReason('2026-12-02', null));

        $this->assertSame(2, $this->creditsOf($this->cy));
        $this->assertSame(1, $this->creditsOf($this->ana, '2026-12'));
    }

    public function test_old_run_rows_with_no_cycle_count_toward_the_cap_and_their_cycle_by_date(): void
    {
        // A round 7 run (rows linked, no cycle) on Monday the 16th: the 30th's
        // cycle by its date.
        $round7 = $this->oldRun('2026-11-16 10:00:00', linksRows: true);
        $this->runRow($this->cy, '2026-11-16', $round7);
        // A run from before rows were linked, dated the 3rd: the 15th's cycle.
        // Its rows are told by the run's description, up to that run.
        $this->runRow($this->ben, '2026-11-03', null);
        $this->oldRun('2026-11-16 10:00:01', linksRows: false);

        $this->on('2026-11-30');
        $this->runFor('2026-11-30')
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped_count', 3)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::otherHalfReason('15', '2026-11-30'))
            // Ben's monthly pledge: its month is full (the cap), whatever the half.
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::alreadyCreditedReason([['auto_credit', '2026-11-03', null]]))
            // Cy's November 30 cycle is taken by the old run dated the 16th.
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('2026-11-30', '2026-11-16'));

        // The 15th's cycle is still open for Cy (and Ana), once.
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 2);
        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 0);

        $this->assertSame(2, $this->creditsOf($this->cy));
        $this->assertSame(1, $this->creditsOf($this->ben));
    }

    public function test_an_old_run_on_the_15th_takes_the_15th_cycle(): void
    {
        $round7 = $this->oldRun('2026-11-15 10:00:00', linksRows: true);
        $this->runRow($this->cy, '2026-11-15', $round7);

        $this->runFor('2026-11-15')->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::sameHalfReason('2026-11-15', '2026-11-15'));
        $this->on('2026-11-30');
        $this->runFor('2026-11-30')->assertJsonPath('data.member_count', 2);

        $this->assertSame(2, $this->creditsOf($this->cy));
    }

    public function test_the_preview_equals_the_run(): void
    {
        $this->handCredit($this->cy, '2026-11-02', null);
        $this->runFor('2026-11-15');

        foreach (['2026-11-15' => '2026-11-16', '2026-11-30' => '2026-11-30', '2026-12-15' => '2026-12-15'] as $cycle => $day) {
            $this->on($day);
            $preview = $this->getJson('/api/auto-credit/status?cycle_date='.$cycle)->assertOk()->json('data');
            $run = $this->postJson('/api/auto-credit/process', ['cycle_date' => $cycle])->assertCreated()->json('data');

            $this->assertSame($cycle, $preview['cycle_date']);
            $this->assertSame($run['member_count'], $preview['active_count'], "cycle {$cycle}: members");
            $this->assertEquals($run['total_amount'], $preview['total_to_credit'], "cycle {$cycle}: total");
            $this->assertSame($run['skipped_count'], $preview['skipped_count'], "cycle {$cycle}: skipped");
            $this->assertSame(
                array_map(fn (array $skip): array => [$skip['pledge_id'], $skip['reason']], $run['skipped']),
                array_map(fn (array $skip): array => [$skip['id'], $skip['reason']], $preview['skipped_members']),
                "cycle {$cycle}: skipped list",
            );
        }
    }

    public function test_the_status_defaults_to_the_latest_cycle_on_or_before_today_in_manila_and_lists_the_allowed_ones(): void
    {
        // Nov 15, 23:30 in Manila (15:30 UTC): the November 15 cycle.
        Carbon::setTestNow(Carbon::parse('2026-11-15 15:30:00', 'UTC'));
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.cycle_date', '2026-11-15');

        // Nov 16, 00:30 in Manila (Nov 15 in UTC): still November 15, never
        // the 30th ahead of its date.
        Carbon::setTestNow(Carbon::parse('2026-11-15 16:30:00', 'UTC'));
        $data = $this->getJson('/api/auto-credit/status')->assertOk()
            ->assertJsonPath('data.cycle_date', '2026-11-15')
            ->assertJsonPath('data.cycle_label', 'November 15')
            ->assertJsonPath('data.active_count', 2)
            ->assertJsonPath('data.skipped_members.0.reason', ShareCapitalService::otherHalfReason('30', '2026-11-15'))
            ->json('data');

        // The current cycle first, then each earlier one back to 92 days ago (Aug 16).
        $this->assertSame(
            ['2026-11-15', '2026-10-31', '2026-10-15', '2026-09-30', '2026-09-15', '2026-08-31'],
            array_column($data['cycle_options'], 'date'),
        );
        $this->assertSame(['date' => '2026-10-31', 'label' => 'October 31'], $data['cycle_options'][1]);

        // The 30th is offered only from the 30th; before the 15th, last month's end.
        $this->on('2026-11-29');
        $this->assertSame('2026-11-15', $this->getJson('/api/auto-credit/status')->json('data.cycle_options.0.date'));
        $this->on('2026-11-30');
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.cycle_date', '2026-11-30')
            ->assertJsonPath('data.cycle_options.0.date', '2026-11-30');
        $this->on('2026-12-14');
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.cycle_date', '2026-11-30')
            ->assertJsonPath('data.cycle_options.0.date', '2026-11-30');
    }

    public function test_february_ends_its_30th_cycle_on_the_28th(): void
    {
        $this->on('2027-02-27');
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.cycle_date', '2027-02-15');
        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2027-02-28'])->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The 30th run for February opens on Feb 28.');

        $this->on('2027-02-28');
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.cycle_date', '2027-02-28')
            ->assertJsonPath('data.cycle_label', 'February 28');
        $this->runFor('2027-02-28')->assertJsonPath('data.member_count', 2);
    }

    public function test_the_status_says_whether_the_chosen_cycle_has_a_run(): void
    {
        $this->getJson('/api/auto-credit/status?cycle_date=2026-11-15')->assertOk()->assertJsonPath('data.cycle_run', null);

        $id = $this->runFor('2026-11-15')->json('data.id');

        $this->on('2026-11-30');
        $this->getJson('/api/auto-credit/status?cycle_date=2026-11-15')->assertOk()
            ->assertJsonPath('data.cycle_run.id', $id)
            ->assertJsonPath('data.cycle_run.status', 'completed')
            ->assertJsonPath('data.cycle_run.processed_at', '2026-11-16 10:00:00');
        // The last run is November 15's, but November 30 has none yet.
        $this->getJson('/api/auto-credit/status')->assertOk()
            ->assertJsonPath('data.cycle_date', '2026-11-30')
            ->assertJsonPath('data.cycle_run', null)
            ->assertJsonPath('data.last_run.id', $id);
        // A run made before cycles is never any cycle's run.
        $this->oldRun('2026-10-31 10:00:00', linksRows: true);
        $this->getJson('/api/auto-credit/status?cycle_date=2026-10-31')->assertOk()->assertJsonPath('data.cycle_run', null);
    }

    public function test_the_run_stores_its_cycle_and_names_it(): void
    {
        $id = $this->runFor('2026-11-15')
            ->assertJsonPath('message', 'Auto-credit for November 15 processed for 2 members. Total: ₱700.00.')
            ->assertJsonPath('data.cycle_label', 'November 15')
            ->json('data.id');

        $this->assertSame('2026-11-15', AutoCreditRun::query()->findOrFail($id)->cycle_date->toDateString());
        $this->getJson('/api/auto-credit/status')->assertOk()
            ->assertJsonPath('data.last_run.cycle_date', '2026-11-15')
            ->assertJsonPath('data.last_run.cycle_label', 'November 15');
    }

    private function runFor(string $cycleDate): TestResponse
    {
        return $this->postJson('/api/auto-credit/process', ['cycle_date' => $cycleDate])->assertCreated();
    }

    private function on(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 10:00:00', 'Asia/Manila'));
    }

    private function member(string $first, string $last, string $schedule, int $amount): Borrower
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => $first, 'middle_name' => null, 'last_name' => $last]);
        $member->shareCapitalPledge->update(['amount' => $amount, 'auto_credit' => true, 'schedule' => $schedule]);

        return $member;
    }

    private function oldRun(string $processedAt, bool $linksRows): AutoCreditRun
    {
        return AutoCreditRun::query()->create([
            'total_amount' => 200, 'member_count' => 1, 'processed_at' => Carbon::parse($processedAt, 'Asia/Manila'),
            'processed_by' => $this->admin->id, 'status' => 'completed', 'links_rows' => $linksRows,
        ]);
    }

    /** A credit an old run posted: linked to its run (round 7), or by the run's description (before). */
    private function runRow(Borrower $member, string $date, ?AutoCreditRun $run): void
    {
        ShareCapitalLedger::query()->create([
            'borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'description' => ShareCapitalService::AUTO_CREDIT_DESCRIPTION, 'debit' => 0, 'credit' => 200,
            'method' => 'bank_transfer', 'created_by' => $this->admin->id, 'auto_credit_run_id' => $run?->id,
        ]);
    }

    private function handCredit(Borrower $member, string $date, ?string $reference): void
    {
        ShareCapitalLedger::query()->create([
            'borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'description' => 'Manual credit entry', 'debit' => 0, 'credit' => 500,
            'method' => 'cash', 'reference_number' => $reference, 'created_by' => $this->admin->id,
        ]);
    }

    /** How many credit rows the member got in the month (YYYY-MM). */
    private function creditsOf(Borrower $member, string $month = '2026-11'): int
    {
        return ShareCapitalLedger::query()->where('borrower_id', $member->id)->where('credit', '>', 0)
            ->where('date', 'like', $month.'-%')->count();
    }

    /** @return list<string> */
    private function datesOf(Borrower $member): array
    {
        return ShareCapitalLedger::query()->where('borrower_id', $member->id)->orderBy('date')
            ->pluck('date')->map(fn ($date): string => Carbon::parse($date)->toDateString())->all();
    }
}
