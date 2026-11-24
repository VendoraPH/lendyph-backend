<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * On two real connections: a branch closed after the request's own check
 * passed, but before the walk-in GCash transaction's transaction began, is
 * seen by the re-check under the branch row lock, and nothing is saved
 * (Finish run round 2, decision 4).
 *
 * Before, only the FormRequest's plain read checked the branch, so a closure
 * approved in between let a new transaction, and its journal, into a closed
 * branch.
 */
class GCashWalkInBranchClosureRaceTest extends TestCase
{
    use SetupLendyPH;

    /** The other connection can only see, and close, what this one committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'gcash_branch_closure_race';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    public function test_a_branch_closed_after_validation_refuses_the_walk_in_transaction(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR', 'status' => Branch::STATUS_SUSPENDED]);
        $walkIn = GCashNonMember::factory()->create();

        $closed = false;
        DB::connection()->beforeStartingTransaction(function () use (&$closed, $far): void {
            if ($closed) {
                return;
            }

            $closed = true;
            $other = DB::connection(self::OTHER_CONNECTION);
            $other->transaction(fn () => $other->table('branches')->where('id', $far->id)->update(['status' => Branch::STATUS_CLOSED]));
        });

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $walkIn->id,
            'branch_id' => $far->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Far Branch" (FAR) is closed, so nothing new can be added there.');

        $this->assertTrue($closed, 'the branch closed between the check and the write');
        $this->assertSame(0, GCashTransaction::query()->where('branch_id', $far->id)->count());
    }
}
