<?php

namespace Tests\Feature\ShareCapitalAutoCredit;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Services\ShareCapitalService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7: a pledge's auto-credit switch and an entry by hand can't
 * race. The entry reads the pledge again under its row's shared lock, after
 * the member's branch and the member (the existing lock order), and the switch
 * takes the pledge row FOR UPDATE. So a switch committed while the entry was
 * on its way in is seen, and the entry is refused in words with nothing
 * posted; the same for a bulk batch and for the auto-credit run, which credits
 * only pledges still set to auto-credit under that lock.
 */
class AutoCreditToggleRaceTest extends TestCase
{
    use SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'share_capital_auto_credit_toggle';

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Santos']);
        $this->member->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => false]);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_entry_whose_pledge_is_switched_to_auto_credit_meanwhile_is_refused_with_nothing_posted(): void
    {
        $switched = $this->switchAutoCreditRightAfterTheMemberLock(true);

        $this->postJson("/api/pledges/{$this->member->shareCapitalPledge->id}/entries", ['amount' => 500, 'type' => 'credit', 'date' => '2026-11-24'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.pledge_id.0', ShareCapitalService::autoCreditPledgeRefusal('Ana Santos'));

        $this->assertTrue($switched());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_pledge_entrys_single_entry_through_the_ledger_is_refused_too(): void
    {
        $switched = $this->switchAutoCreditRightAfterTheMemberLock(true);

        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id, 'date' => '2026-11-24', 'description' => 'Manual credit entry', 'type' => 'credit', 'amount' => 500,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.borrower_id.0', ShareCapitalService::autoCreditPledgeRefusal('Ana Santos'));

        $this->assertTrue($switched());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_a_batch_whose_pledge_is_switched_to_auto_credit_meanwhile_is_refused_with_nothing_posted(): void
    {
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $switched = $this->switchAutoCreditRightAfterTheMemberLock(true);

        $this->postJson('/api/pledges/bulk-entries', ['entries' => [
            ['pledge_id' => $other->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
            ['pledge_id' => $this->member->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
        ]])->assertUnprocessable()
            ->assertJsonPath('errors.entries.0', ShareCapitalService::autoCreditBatchRefusal(['Ana Santos']));

        $this->assertTrue($switched());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_a_run_does_not_credit_a_pledge_switched_off_meanwhile(): void
    {
        $this->member->shareCapitalPledge->update(['auto_credit' => true]);
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $other->shareCapitalPledge->update(['amount' => 300, 'auto_credit' => true]);
        $switched = $this->switchAutoCreditRightAfterTheMemberLock(false);

        $this->postJson('/api/auto-credit/process', ['method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.total_amount', 300);

        $this->assertTrue($switched());
        $this->assertSame([$other->id], ShareCapitalLedger::query()->pluck('borrower_id')->all());
    }

    public function test_the_switch_reads_and_flips_the_pledge_under_its_row_lock(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->patchJson("/api/pledges/{$this->member->shareCapitalPledge->id}/auto-credit")->assertOk()->assertJsonPath('auto_credit', true);

        $this->assertNotEmpty(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'from `share_capital_pledges`') && str_contains($sql, 'for update'),
        ));
        $this->assertTrue((bool) $this->member->shareCapitalPledge->refresh()->auto_credit);
    }

    public function test_a_switch_waiting_too_long_behind_a_run_answers_409_in_words_and_changes_nothing(): void
    {
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('share_capital_pledges')->where('id', $this->member->shareCapitalPledge->id)->sharedLock()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->patchJson("/api/pledges/{$this->member->shareCapitalPledge->id}/auto-credit")
                ->assertStatus(409)
                ->assertExactJson(['message' => ShareCapitalService::PLEDGE_SWITCH_BUSY]);
        } finally {
            $other->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertFalse((bool) $this->member->shareCapitalPledge->refresh()->auto_credit);
    }

    /**
     * Right after the write reads its members again under their shared
     * locks, another connection switches the pledge's auto-credit and
     * commits, as PATCH /pledges/{pledge}/auto-credit would.
     *
     * @return Closure(): bool whether it happened
     */
    private function switchAutoCreditRightAfterTheMemberLock(bool $on): Closure
    {
        $switched = false;
        $pledgeId = $this->member->shareCapitalPledge->id;

        DB::connection()->beforeExecuting(function (string $query) use (&$switched, $on, $pledgeId): void {
            if ($switched || ! str_contains($query, 'from `borrowers`') || ! str_contains($query, 'lock in share mode')) {
                return;
            }

            $switched = true;
            DB::connection(self::OTHER_CONNECTION)->table('share_capital_pledges')->where('id', $pledgeId)->update(['auto_credit' => $on]);
        });

        return function () use (&$switched): bool {
            return $switched;
        };
    }
}
