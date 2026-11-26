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
 * Finish run round 8: a share capital auto-credit run is for one half of the
 * month (`cycle`, the 15th or the 30th), as the savings auto-credit picks its
 * cycle.
 *
 * - The 15th run credits "15" and "15/30" pledges, the 30th run "30" and
 *   "15/30"; other schedules are skipped with the reason.
 * - A pledge is credited at most once per half by runs, in the run's month
 *   (Manila). Round 7's monthly cap still holds on top.
 * - Runs made before this (no cycle) count toward the cap, and toward a half
 *   by their row's date (1st–15th, or later).
 * - The status (the run's preview) takes `?cycle=`, defaulting by today in
 *   Manila, and equals what the run posts.
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
        $this->run15()
            ->assertJsonPath('data.cycle', '15')
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.total_amount', 700)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.pledge_id', $this->ben->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.0.reason', 'This pledge is credited on the 30th, so the 15th run skips it.');

        $this->on('2026-11-30');
        $this->run30()
            ->assertJsonPath('data.cycle', '30')
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.total_amount', 500)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.pledge_id', $this->ana->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.0.reason', 'This pledge is credited on the 15th, so the 30th run skips it.');

        $this->assertSame(1, $this->creditsOf($this->ana));
        $this->assertSame(1, $this->creditsOf($this->ben));
        $this->assertSame(2, $this->creditsOf($this->cy));
    }

    public function test_the_other_half_reason_reads_in_words(): void
    {
        $this->assertSame('This pledge is credited on the 30th, so the 15th run skips it.', ShareCapitalService::otherHalfReason('30', '15'));
        $this->assertSame('This pledge is credited on the 15th, so the 30th run skips it.', ShareCapitalService::otherHalfReason('15', '30'));
        $this->assertSame(
            'Credited by auto-credit for the 15th on Nov 14, 2026, so the 15th run skips it this month.',
            ShareCapitalService::sameHalfReason('15', '2026-11-14'),
        );
    }

    public function test_the_same_cycle_run_twice_gives_one_credit(): void
    {
        $this->run15()->assertJsonPath('data.member_count', 2);

        $this->run15()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.total_amount', 0)
            ->assertJsonPath('data.skipped_count', 3)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::sameHalfReason('15', '2026-11-16'))
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::otherHalfReason('30', '15'))
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('15', '2026-11-16'));

        $this->assertSame(1, $this->creditsOf($this->ana));
        $this->assertSame(1, $this->creditsOf($this->cy));
    }

    public function test_the_sunday_scenario_gives_two_a_month_never_three(): void
    {
        // Nov 15, 2026 is a Sunday: the 15th run is done on Saturday the 14th,
        // and again on Monday the 16th by someone who didn't know.
        $this->on('2026-11-14');
        $this->run15()->assertJsonPath('data.member_count', 2);
        $this->on('2026-11-16');
        $this->run15()->assertJsonPath('data.member_count', 0);

        // The 30th run, and a second one by mistake.
        $this->on('2026-11-30');
        $this->run30()->assertJsonPath('data.member_count', 2);
        $this->run30()->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('30', '2026-11-30'));

        // Both halves on the same day: still one each.
        $this->run15()->assertJsonPath('data.member_count', 0);

        $this->assertSame(2, $this->creditsOf($this->cy));
        $this->assertSame(['2026-11-14', '2026-11-30'], $this->datesOf($this->cy));
        $this->assertSame(1, $this->creditsOf($this->ana));
        $this->assertSame(1, $this->creditsOf($this->ben));
    }

    public function test_the_hand_credit_cap_still_holds(): void
    {
        // A twice-monthly pledge credited by hand counts one instalment: the
        // 15th run gives the second, and the 30th run finds the month full.
        $this->handCredit($this->cy, '2026-11-05', 'CSH-0001-2026-0007');
        $this->run15()->assertJsonPath('data.member_count', 2);

        $this->on('2026-11-30');
        $this->run30()
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.1.pledge_id', $this->cy->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::alreadyCreditedReason([
                ['hand', '2026-11-05', 'CSH-0001-2026-0007'],
                ['auto_credit', '2026-11-16', null],
            ]));

        // A monthly pledge credited by hand isn't credited by its own half's run.
        $this->handCredit($this->ana, '2026-12-02', null);
        $this->on('2026-12-15');
        $this->run15()
            ->assertJsonPath('data.skipped.0.pledge_id', $this->ana->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::handCreditedReason('2026-12-02', null));

        $this->assertSame(2, $this->creditsOf($this->cy));
        $this->assertSame(1, $this->creditsOf($this->ana, '2026-12'));
    }

    public function test_old_run_rows_with_no_cycle_count_toward_the_cap_and_their_half_by_date(): void
    {
        // A round 7 run (rows linked, no cycle) on Monday the 16th: the 30th
        // half by its date.
        $round7 = $this->oldRun('2026-11-16 10:00:00', linksRows: true);
        $this->runRow($this->cy, '2026-11-16', $round7);
        // A run from before rows were linked, dated the 3rd: the 15th half.
        // Its rows are told by the run's description, up to that run.
        $this->runRow($this->ben, '2026-11-03', null);
        $this->oldRun('2026-11-16 10:00:01', linksRows: false);

        $this->on('2026-11-30');
        $this->run30()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped_count', 3)
            // Ben's monthly pledge: its month is full (the cap), whatever the half.
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::otherHalfReason('15', '30'))
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::alreadyCreditedReason([['auto_credit', '2026-11-03', null]]))
            // Cy's 30th half is taken by the old run dated the 16th.
            ->assertJsonPath('data.skipped.2.reason', ShareCapitalService::sameHalfReason('30', '2026-11-16'));

        // The 15th half is still open for Cy (and Ana), once.
        $this->run15()->assertJsonPath('data.member_count', 2);
        $this->run15()->assertJsonPath('data.member_count', 0);

        $this->assertSame(2, $this->creditsOf($this->cy));
        $this->assertSame(1, $this->creditsOf($this->ben));
    }

    public function test_an_old_run_on_the_15th_takes_the_15th_half(): void
    {
        $round7 = $this->oldRun('2026-11-15 10:00:00', linksRows: true);
        $this->runRow($this->cy, '2026-11-15', $round7);

        $this->run15()->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::sameHalfReason('15', '2026-11-15'));
        $this->on('2026-11-30');
        $this->run30()->assertJsonPath('data.member_count', 2);

        $this->assertSame(2, $this->creditsOf($this->cy));
    }

    public function test_the_preview_equals_the_run(): void
    {
        $this->handCredit($this->cy, '2026-11-02', null);
        $this->run15();

        foreach (['15', '30'] as $cycle) {
            $this->on($cycle === '15' ? '2026-11-16' : '2026-11-30');
            $preview = $this->getJson('/api/auto-credit/status?cycle='.$cycle)->assertOk()->json('data');
            $run = $this->postJson('/api/auto-credit/process', ['cycle' => $cycle])->assertCreated()->json('data');

            $this->assertSame($cycle, $preview['cycle']);
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

    public function test_the_status_defaults_the_cycle_by_todays_date_in_manila(): void
    {
        // Nov 15, 23:30 in Manila (15:30 UTC): the 15th.
        Carbon::setTestNow(Carbon::parse('2026-11-15 15:30:00', 'UTC'));
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.cycle', '15');

        // Nov 16, 00:30 in Manila (Nov 15 in UTC): the 30th.
        Carbon::setTestNow(Carbon::parse('2026-11-15 16:30:00', 'UTC'));
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.cycle', '30')
            ->assertJsonPath('data.active_count', 2);

        $this->getJson('/api/auto-credit/status?cycle=15')->assertOk()->assertJsonPath('data.cycle', '15')
            ->assertJsonPath('data.active_count', 2)
            ->assertJsonPath('data.skipped_members.0.reason', ShareCapitalService::otherHalfReason('30', '15'));
    }

    public function test_the_run_needs_its_cycle_in_words(): void
    {
        $this->postJson('/api/auto-credit/process')->assertUnprocessable()
            ->assertJsonPath('errors.cycle.0', 'Choose the half of the month this run is for: the 15th or the 30th.');

        foreach (['31', '1', 'fifteenth', ['15']] as $wrong) {
            $this->postJson('/api/auto-credit/process', ['cycle' => $wrong])->assertUnprocessable()
                ->assertJsonPath('errors.cycle.0', 'A run is for the 15th or the 30th.');
        }

        $this->getJson('/api/auto-credit/status?cycle=31')->assertUnprocessable()
            ->assertJsonPath('errors.cycle.0', 'A run is for the 15th or the 30th.');

        $this->assertSame(0, AutoCreditRun::query()->count());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_a_numeric_cycle_is_read_as_its_half(): void
    {
        $this->postJson('/api/auto-credit/process', ['cycle' => 15])->assertCreated()->assertJsonPath('data.cycle', '15');
    }

    public function test_the_run_stores_its_cycle_and_names_its_half(): void
    {
        $id = $this->run15()
            ->assertJsonPath('message', 'Auto-credit for the 15th processed for 2 members. Total: ₱700.00.')
            ->json('data.id');

        $this->assertSame('15', AutoCreditRun::query()->findOrFail($id)->cycle);
        $this->getJson('/api/auto-credit/status')->assertOk()->assertJsonPath('data.last_run.cycle', '15');
    }

    private function run15(): TestResponse
    {
        return $this->postJson('/api/auto-credit/process', ['cycle' => '15'])->assertCreated();
    }

    private function run30(): TestResponse
    {
        return $this->postJson('/api/auto-credit/process', ['cycle' => '30'])->assertCreated();
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
