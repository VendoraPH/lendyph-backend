<?php

namespace Tests\Feature\ShareCapitalAutoCredit;

use App\Models\AutoCreditRun;
use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Services\GeneratedNumberWrite;
use App\Services\ShareCapitalService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 8, on two real connections: two runs for the same cycle
 * started at once never both credit a pledge. Round 7's locks hold it: each
 * run reads the members' rows in the month FOR SHARE, so the second run's
 * credit waits for the first run to commit and, waiting too long, answers 409
 * with nothing posted. The cycle is then taken: running it again credits
 * nothing. Runs for a month's two cycles at once are held the same way, so a
 * twice-monthly pledge never gets a third instalment.
 */
class AutoCreditCycleRaceTest extends TestCase
{
    use SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'auto_credit_cycle_race';

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Asia/Manila'));
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->member->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true, 'schedule' => '15/30']);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER_CONNECTION)->rollBack(0);
        DB::purge(self::OTHER_CONNECTION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_two_runs_for_the_same_half_started_at_once_credit_a_pledge_once_and_the_other_answers_409_with_nothing_posted(): void
    {
        $otherStatus = null;
        $interleaved = false;

        // Both runs have read the month's rows; right before this run credits,
        // the other one runs whole, through the same code.
        DB::connection()->beforeExecuting(function (string $query) use (&$interleaved, &$otherStatus): void {
            if ($interleaved || ! str_starts_with($query, 'insert into `share_capital_ledger`')) {
                return;
            }

            $interleaved = true;
            $otherStatus = $this->onTheOtherConnection(fn () => app(ShareCapitalService::class)->processAutoCredit($this->admin, '2026-11-15'));
        });

        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated()->assertJsonPath('data.member_count', 1);

        $this->assertTrue($interleaved);
        $this->assertSame(409, $otherStatus);
        $this->assertSame(1, ShareCapitalLedger::query()->where('borrower_id', $this->member->id)->count());
        $this->assertSame(1, AutoCreditRun::query()->count(), 'the run that answered 409 left no run');

        // The cycle is taken: the same cycle again credits nothing.
        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::sameHalfReason('2026-11-15', '2026-11-16'));
        $this->assertSame(1, ShareCapitalLedger::query()->where('borrower_id', $this->member->id)->count());
    }

    public function test_runs_for_both_of_a_months_cycles_started_at_once_never_give_a_third_instalment(): void
    {
        // A twice-monthly pledge credited by hand in November: one instalment
        // is left. Runs for Nov 15 (late) and Nov 30 start at the same moment;
        // each alone would credit it.
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Manila'));
        ShareCapitalLedger::query()->create([
            'borrower_id' => $this->member->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-05',
            'description' => 'Manual credit entry', 'debit' => 0, 'credit' => 500,
            'method' => 'cash', 'created_by' => $this->admin->id,
        ]);
        $otherStatus = null;
        $interleaved = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$interleaved, &$otherStatus): void {
            if ($interleaved || ! str_starts_with($query, 'insert into `share_capital_ledger`')) {
                return;
            }

            $interleaved = true;
            $otherStatus = $this->onTheOtherConnection(fn () => app(ShareCapitalService::class)->processAutoCredit($this->admin, '2026-11-15'));
        });

        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-11-30'])->assertCreated()->assertJsonPath('data.member_count', 1);

        $this->assertTrue($interleaved);
        $this->assertSame(409, $otherStatus);
        $this->assertSame(2, ShareCapitalLedger::query()->where('borrower_id', $this->member->id)->where('credit', '>', 0)->count());

        // Run again, the Nov 15 cycle finds November's two instalments in.
        $this->postJson('/api/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::alreadyCreditedReason([
                ['hand', '2026-11-05', null],
                ['auto_credit', '2026-11-30', null],
            ]));
        $this->assertSame(2, ShareCapitalLedger::query()->where('borrower_id', $this->member->id)->where('credit', '>', 0)->count());
    }

    /** Run `$run` as another request would, on the other connection; its HTTP status. */
    private function onTheOtherConnection(Closure $run): int
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::OTHER_CONNECTION);

        try {
            $run();

            return 201;
        } catch (HttpResponseException $e) {
            $this->assertSame(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT], $e->getResponse()->getData(true));

            return $e->getResponse()->getStatusCode();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
