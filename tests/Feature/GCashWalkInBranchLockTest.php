<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A walk-in GCash transaction recorded in a branch takes that branch's row
 * lock inside its transaction and re-checks the branch there (Finish run
 * round 2, decision 4): a branch waiting to close (a pending closure request)
 * takes nothing new, a closed one neither, and a suspended one with no
 * closure request keeps the existing rule (it still takes GCash).
 */
class GCashWalkInBranchLockTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    private GCashNonMember $walkIn;

    private ?Borrower $farMember = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->walkIn = GCashNonMember::factory()->create();
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    public function test_a_branch_waiting_to_close_refuses_a_walk_in_transaction_in_words(): void
    {
        $this->far->forceFill(['status' => Branch::STATUS_SUSPENDED])->save();
        BranchClosure::create([
            'branch_id' => $this->far->id,
            'status' => BranchClosure::STATUS_PENDING,
            'effective_on' => now()->toDateString(),
            'reason' => 'Merging into Main',
            'requested_by' => $this->admin->id,
        ]);

        $this->record()
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Far Branch" (FAR) is waiting to close, so nothing new can be added there.');

        $this->assertSame(0, GCashTransaction::query()->where('branch_id', $this->far->id)->count());
    }

    public function test_a_suspended_branch_with_no_closure_request_still_takes_a_walk_in_transaction(): void
    {
        $this->far->forceFill(['status' => Branch::STATUS_SUSPENDED])->save();
        BranchClosure::create([
            'branch_id' => $this->far->id,
            'status' => BranchClosure::STATUS_CANCELLED,
            'effective_on' => now()->toDateString(),
            'reason' => 'Changed our mind',
            'requested_by' => $this->admin->id,
        ]);

        $this->record()->assertCreated()->assertJsonPath('data.branch_id', $this->far->id);
    }

    public function test_the_branch_row_is_locked_before_the_reference_lock(): void
    {
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'for update') || str_contains($query->sql, 'lock in share mode') || str_contains($query->sql, 'for share')) {
                $locks[] = $query->sql;
            }
        });

        $this->record()->assertCreated();

        $branchLock = array_key_first(array_filter($locks, fn (string $sql): bool => str_contains($sql, 'from `branches`') && str_contains($sql, 'for update')));
        $referenceLock = array_key_first(array_filter($locks, fn (string $sql): bool => str_contains($sql, 'from `gcash_transactions`') && str_contains($sql, '`reference_no` like')));

        $this->assertNotNull($branchLock, 'the branch row is locked FOR UPDATE');
        $this->assertNotNull($referenceLock, 'the reference lock still runs');
        $this->assertLessThan($referenceLock, $branchLock, 'the branch row comes first, as the closure takes it');
    }

    public function test_a_head_office_walk_in_takes_no_branch_lock(): void
    {
        $locked = false;
        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            $locked = $locked || (str_contains($query->sql, 'from `branches`') && str_contains($query->sql, 'for update'));
        });

        $this->postJson('/api/gcash/transactions', ['gcash_non_member_id' => $this->walkIn->id, 'branch_id' => null, 'type' => 'cash_in', 'amount' => 1200])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', null);

        $this->assertFalse($locked);
    }

    public function test_a_members_branch_waiting_to_close_refuses_the_members_transaction_in_the_same_words(): void
    {
        $this->closing();

        $this->recordForMember()
            ->assertUnprocessable()
            ->assertJsonPath('errors.borrower_id.0', 'Branch "Far Branch" (FAR) is waiting to close, so nothing new can be added there.');

        $this->assertSame(0, GCashTransaction::query()->where('borrower_id', $this->member()->id)->count());
    }

    public function test_a_members_suspended_branch_with_no_closure_request_still_takes_the_members_transaction(): void
    {
        $this->far->forceFill(['status' => Branch::STATUS_SUSPENDED])->save();

        $this->recordForMember()->assertCreated()->assertJsonPath('data.branch_id', $this->far->id);
    }

    public function test_a_members_transaction_locks_the_member_then_the_members_branch_before_the_reference_lock(): void
    {
        $member = $this->member();
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'for update') || str_contains($query->sql, 'for share') || str_contains($query->sql, 'lock in share mode')) {
                $locks[] = [$query->sql, $query->bindings];
            }
        });

        $this->recordForMember($member)->assertCreated();

        $memberLock = array_key_first(array_filter($locks, fn (array $q): bool => str_contains($q[0], 'from `borrowers`') && $q[1] === [$member->id]));
        $branchLock = array_key_first(array_filter($locks, fn (array $q): bool => str_contains($q[0], 'from `branches`') && str_contains($q[0], 'for update') && $q[1] === [$this->far->id]));
        $referenceLock = array_key_first(array_filter($locks, fn (array $q): bool => str_contains($q[0], 'from `gcash_transactions`') && str_contains($q[0], '`reference_no` like')));

        $this->assertNotNull($memberLock, "the member's row is read with a lock");
        $this->assertNotNull($branchLock, "the member's branch row is locked FOR UPDATE");
        $this->assertNotNull($referenceLock, 'the reference lock still runs');
        $this->assertLessThan($branchLock, $memberLock, 'the member comes before the branch, as MemberTransfer takes them');
        $this->assertLessThan($referenceLock, $branchLock, 'the branch row comes before the GCash rows');
    }

    private function closing(): void
    {
        $this->far->forceFill(['status' => Branch::STATUS_SUSPENDED])->save();
        BranchClosure::create([
            'branch_id' => $this->far->id,
            'status' => BranchClosure::STATUS_PENDING,
            'effective_on' => now()->toDateString(),
            'reason' => 'Merging into Main',
            'requested_by' => $this->admin->id,
        ]);
    }

    private function member(): Borrower
    {
        return $this->farMember ??= Borrower::factory()->create(['branch_id' => $this->far->id]);
    }

    private function recordForMember(?Borrower $member = null): TestResponse
    {
        return $this->postJson('/api/gcash/transactions', [
            'borrower_id' => ($member ?? $this->member())->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ]);
    }

    private function record(): TestResponse
    {
        return $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $this->walkIn->id,
            'branch_id' => $this->far->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ]);
    }
}
