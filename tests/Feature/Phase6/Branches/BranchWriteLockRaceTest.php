<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingExpense;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\User;
use App\Services\Branches\BranchRowLock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The branch row lock on two real connections (finish run round 2, decision
 * 4): a closure and a write in the same branch never interleave.
 *
 * - A closure that commits after the write's FormRequest passed is still
 *   seen: the write re-reads the branch under its lock and refuses.
 * - While a write holds the branch, a closure step can't lock it.
 * - While a closure step holds the branch, a write waits; one that waits too
 *   long answers 409 with nothing saved.
 */
class BranchWriteLockRaceTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'branch_write_lock_race';

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR', 'status' => Branch::STATUS_SUSPENDED]);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER_CONNECTION)->rollBack(0);
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    /**
     * The branch closes on the other connection right after the FormRequest's
     * BranchTakesNew read (a suspended branch, so it passed) and before the
     * write's transaction: the write reads the branch again under its lock
     * and refuses. Before the lock it saved the expense into a closed branch.
     */
    public function test_a_branch_closed_after_the_requests_check_is_seen_under_the_lock(): void
    {
        $closed = false;
        $relocked = false;

        DB::listen(function (QueryExecuted $query) use (&$relocked): void {
            if ($query->connectionName !== self::OTHER_CONNECTION && DB::transactionLevel() > 0
                && str_contains($query->sql, 'from `branches`') && str_contains($query->sql, 'lock in share mode')) {
                $relocked = true;
            }
        });

        DB::listen(function (QueryExecuted $query) use (&$closed): void {
            if ($closed || $query->connectionName === self::OTHER_CONNECTION || DB::transactionLevel() > 0
                || $query->sql !== 'select `id`, `name`, `code`, `status` from `branches` where `branches`.`id` = ? limit 1'
                || (int) ($query->bindings[0] ?? 0) !== $this->far->id) {
                return;
            }

            $closed = true;
            $other = DB::connection(self::OTHER_CONNECTION);
            $other->transaction(function () use ($other): void {
                $other->table('branch_closures')->insert([
                    'branch_id' => $this->far->id, 'status' => BranchClosure::STATUS_APPROVED, 'effective_on' => now()->toDateString(),
                    'reason' => 'Merged into Main', 'requested_by' => $this->admin->id, 'decided_by' => $this->admin->id,
                    'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $other->table('branches')->where('id', $this->far->id)->update(['status' => Branch::STATUS_CLOSED, 'closed_on' => now()->toDateString()]);
            });
        });

        $this->postJson('/api/accounting/expenses', $this->expense())
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Far Branch" (FAR) is closed, so nothing new can be added there.');

        $this->assertTrue($closed, "the branch closed right after BranchTakesNew's read");
        $this->assertTrue($relocked, "the write read the branch again under its lock, so the request rule alone didn't refuse it");
        $this->assertSame(0, AccountingExpense::query()->where('branch_id', $this->far->id)->count());
    }

    public function test_a_closure_step_cannot_lock_the_branch_while_a_write_holds_it(): void
    {
        $blocked = null;

        DB::listen(function (QueryExecuted $query) use (&$blocked): void {
            if ($blocked !== null || $query->connectionName === self::OTHER_CONNECTION || ! str_contains($query->sql, 'from `branch_closures`')) {
                return;
            }

            // The write holds the branch row FOR SHARE: the closure's first
            // statement, the branch row FOR UPDATE, waits and times out.
            try {
                DB::connection(self::OTHER_CONNECTION)->transaction(
                    fn ($other) => $other->table('branches')->where('id', $this->far->id)->lockForUpdate()->first(),
                );
                $blocked = false;
            } catch (QueryException) {
                $blocked = true;
            }
        });

        $this->postJson('/api/accounting/expenses', $this->expense())->assertCreated();

        $this->assertTrue($blocked, 'a closure step locked the branch while the write held it');
    }

    public function test_a_write_waits_for_a_closure_step_and_answers_409_when_it_waits_too_long(): void
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('branches')->where('id', $this->far->id)->lockForUpdate()->first();

        $before = AccountingExpense::count();

        $this->postJson('/api/accounting/expenses', $this->expense())
            ->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);

        $other->rollBack();
        $this->assertSame($before, AccountingExpense::count());
    }

    public function test_a_closure_approval_waits_for_a_write_and_answers_409_when_it_waits_too_long(): void
    {
        $closure = BranchClosure::create([
            'branch_id' => $this->far->id, 'status' => BranchClosure::STATUS_PENDING, 'effective_on' => now()->toDateString(),
            'reason' => 'Merged into Main', 'requested_by' => $this->user()->id,
        ]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('branches')->where('id', $this->far->id)->sharedLock()->first();

        $this->postJson("/api/branches/{$this->far->id}/closure/approve")
            ->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);

        $other->rollBack();
        $this->assertSame(BranchClosure::STATUS_PENDING, $closure->refresh()->status);
        $this->assertSame(Branch::STATUS_SUSPENDED, $this->far->refresh()->status);
    }

    /** Someone other than the approver, who asked for the closure. */
    private function user(): User
    {
        return User::factory()->inBranches($this->branch)->create();
    }

    /** @return array<string, mixed> */
    private function expense(): array
    {
        return [
            'date' => now()->toDateString(), 'payee' => 'Meralco', 'expense_account_id' => $this->account('5030'),
            'amount' => 150000, 'payment_account_id' => $this->account('1010'), 'branch_id' => $this->far->id,
        ];
    }
}
