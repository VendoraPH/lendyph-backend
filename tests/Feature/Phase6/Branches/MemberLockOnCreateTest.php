<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\TimeDeposit;
use App\Services\Branches\MemberLock;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\TimeDeposits\TimeDepositFixtures;
use Tests\TestCase;

/**
 * Phase 6 MB-BE4: a time deposit placement and a member's GCash transaction
 * hold the member's branch lock (MemberLock) while they write, so they queue
 * behind a member transfer and copy the branch as it stands then. The loan
 * path is proven with two processes in MemberTransferLockTest.
 */
class MemberLockOnCreateTest extends TestCase
{
    use TimeDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    public function test_a_time_deposit_placement_holds_the_members_branch_lock_while_it_writes(): void
    {
        $held = $this->lockHeldAt('insert into `time_deposits`');

        $this->place()->assertCreated();

        $this->assertTrue($held(), "The member's branch lock wasn't held when the time deposit was written.");
    }

    public function test_a_members_gcash_transaction_holds_the_members_branch_lock_while_it_writes(): void
    {
        GCashTier::query()->create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
        $held = $this->lockHeldAt('insert into `gcash_transactions`');

        $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_in', 'amount' => 1000])->assertCreated();

        $this->assertTrue($held(), "The member's branch lock wasn't held when the GCash transaction was written.");
    }

    public function test_a_held_lock_answers_409_in_words_without_waiting_inside_a_transaction(): void
    {
        // The clock is frozen here (TimeDepositFixtures), and the test runs in
        // a transaction: the write must answer at once, never spin.
        $started = microtime(true);
        $lock = Cache::lock(MemberLock::key($this->member->id), 60);
        $lock->get();

        try {
            $this->place()->assertStatus(409)->assertJsonPath('message', MemberLock::BUSY);
        } finally {
            $lock->release();
        }

        $this->assertLessThan(2.0, microtime(true) - $started, 'the write waited for the lock inside a transaction');
        $this->assertSame(0, TimeDeposit::query()->where('borrower_id', $this->member->id)->count());
    }

    public function test_a_walk_in_gcash_transaction_takes_no_member_lock_and_completes(): void
    {
        GCashTier::query()->create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
        $walkIn = GCashNonMember::factory()->create();

        $this->postJson('/api/gcash/transactions', ['gcash_non_member_id' => $walkIn->id, 'branch_id' => $this->branch->id, 'type' => 'cash_in', 'amount' => 1000])
            ->assertCreated();
    }

    public function test_a_nested_hold_on_the_same_member_runs_at_once_and_releases_once(): void
    {
        $started = microtime(true);

        $result = MemberLock::whileHeld($this->member->id, fn (): string => MemberLock::whileHeld($this->member->id, fn (): string => 'inner'));

        $this->assertSame('inner', $result);
        $this->assertLessThan(1.0, microtime(true) - $started, 'the nested hold waited on its own lock');

        $probe = Cache::lock(MemberLock::key($this->member->id), 60);
        $this->assertTrue($probe->get(), 'the lock was left held after the outer hold ended');
        $probe->release();
    }

    /**
     * Whether the member's lock was held (another owner couldn't take it)
     * when `$insert` ran; false when it never ran.
     *
     * @return Closure(): bool
     */
    private function lockHeldAt(string $insert): Closure
    {
        $held = null;

        DB::listen(function (QueryExecuted $query) use ($insert, &$held): void {
            if ($held !== null || ! str_starts_with(strtolower($query->sql), $insert)) {
                return;
            }

            $probe = Cache::lock(MemberLock::key($this->member->id), 60);
            $held = ! $probe->get();

            if (! $held) {
                $probe->release();
            }
        });

        return function () use (&$held): bool {
            return $held === true;
        };
    }
}
