<?php

namespace Tests\Feature\CashReferences;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\ShareCapitalLedger;
use App\Services\ShareCapitalService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A share capital write reads its members' branches before its transaction,
 * locks those branch rows, then reads the members' branches again FOR SHARE
 * (review of #378). A member moved to another branch in between, on another
 * connection, answers 409 in words with nothing saved and no number used;
 * sent again, the entry posts in the member's new branch.
 */
class ShareCapitalMemberMoveRaceTest extends TestCase
{
    use SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'share_capital_member_move';

    private Borrower $member;

    private Branch $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->elsewhere = Branch::factory()->create(['name' => 'Town Branch', 'code' => 'TWN']);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_entry_whose_member_moves_branch_while_it_saves_answers_409_and_saves_nothing(): void
    {
        $moved = $this->moveTheMemberRightAfterTheBranchLock();

        $this->ledgerEntry()->assertStatus(409)->assertExactJson(['message' => ShareCapitalService::MEMBER_MOVED]);

        $this->assertTrue($moved());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());

        $this->ledgerEntry()->assertCreated();
        $this->assertSame($this->elsewhere->id, ShareCapitalLedger::query()->sole()->branch_id);
    }

    public function test_a_bulk_batch_whose_member_moves_branch_answers_409_and_posts_nothing(): void
    {
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $moved = $this->moveTheMemberRightAfterTheBranchLock();

        $this->postJson('/api/pledges/bulk-entries', ['entries' => [
            ['pledge_id' => $other->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
            ['pledge_id' => $this->member->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
        ]])->assertStatus(409)->assertExactJson(['message' => ShareCapitalService::BATCH_MEMBER_MOVED]);

        $this->assertTrue($moved());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_an_auto_credit_run_whose_member_moves_branch_answers_409_and_posts_nothing(): void
    {
        $this->member->shareCapitalPledge->update(['auto_credit' => true, 'amount' => 500]);
        $moved = $this->moveTheMemberRightAfterTheBranchLock();

        $this->postJson('/api/auto-credit/process', ['method' => 'cash'])
            ->assertStatus(409)->assertExactJson(['message' => ShareCapitalService::AUTO_CREDIT_MEMBER_MOVED]);

        $this->assertTrue($moved());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    /**
     * Right before the write reads its members' branches again, another
     * connection moves the member to Town Branch and commits.
     *
     * @return Closure(): bool whether it happened
     */
    private function moveTheMemberRightAfterTheBranchLock(): Closure
    {
        $moved = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$moved): void {
            if ($moved || ! str_contains($query, 'from `borrowers`') || ! str_contains($query, 'lock in share mode')) {
                return;
            }

            $moved = true;
            DB::connection(self::OTHER_CONNECTION)->table('borrowers')->where('id', $this->member->id)->update(['branch_id' => $this->elsewhere->id]);
        });

        return function () use (&$moved): bool {
            return $moved;
        };
    }

    private function ledgerEntry(): TestResponse
    {
        return $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id,
            'date' => '2026-11-24',
            'description' => 'Contribution',
            'type' => 'credit',
            'amount' => 1000,
        ]);
    }
}
