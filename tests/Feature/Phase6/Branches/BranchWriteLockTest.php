<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\User;
use App\Services\Branches\BranchRowLock;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 2, decision 4: user saves, journals, expenses, fund
 * transfers and reconciliation actions take the branch row lock as their
 * first statement and re-read the branch inside their transaction. A branch
 * waiting to close (a pending closure request) takes nothing new, the same
 * as a closed one; a suspended branch keeps its rules. A deadlock answers 409.
 */
class BranchWriteLockTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const CLOSING = 'Branch "Far Branch" (FAR) is waiting to close, so nothing new can be added there.';

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR', 'status' => Branch::STATUS_SUSPENDED]);
    }

    // A closing branch refuses each write ---------------------------------

    public function test_a_closing_branch_takes_no_expense(): void
    {
        $this->closing();

        $this->postJson('/api/accounting/expenses', $this->expense())
            ->assertUnprocessable()->assertJsonPath('errors.branch_id.0', self::CLOSING);
        $this->assertSame(0, AccountingExpense::query()->where('branch_id', $this->far->id)->count());
    }

    public function test_a_closing_branchs_expense_is_not_paid(): void
    {
        $expense = AccountingExpense::factory()->create(['branch_id' => $this->far->id, 'payment_account_id' => null]);
        $this->closing();

        $this->postJson("/api/accounting/expenses/{$expense->id}/pay", ['date' => now()->toDateString(), 'amount' => 100, 'account_id' => $this->account('1010')])
            ->assertUnprocessable()->assertJsonPath('errors.branch.0', self::CLOSING);
        $this->assertSame(0, (int) $expense->refresh()->amount_paid);
    }

    public function test_a_closing_branch_takes_no_fund_transfer(): void
    {
        $this->closing();
        $before = AccountingJournal::count();

        $this->postJson('/api/accounting/cash-accounts/transfer', $this->transfer())
            ->assertUnprocessable()->assertJsonPath('errors.branch_id.0', self::CLOSING);
        $this->assertSame($before, AccountingJournal::count());
    }

    public function test_a_closing_branch_takes_no_new_or_moved_manual_journal(): void
    {
        $this->closing();

        $this->postJson('/api/accounting/journals', $this->journal())
            ->assertUnprocessable()->assertJsonPath('errors.branch_id.0', self::CLOSING);

        $draft = $this->draftJournal($this->lines(), ['branch_id' => $this->branch->id]);
        $this->putJson("/api/accounting/journals/{$draft->id}", $this->journal())
            ->assertUnprocessable()->assertJsonPath('errors.branch_id.0', self::CLOSING);
        $this->assertSame($this->branch->id, $draft->refresh()->branch_id);
    }

    public function test_a_closing_branchs_draft_is_not_posted_and_its_entries_are_not_reversed(): void
    {
        $draft = $this->draftJournal($this->lines(), ['branch_id' => $this->far->id]);
        $posted = $this->postJournal($this->lines(), ['branch_id' => $this->far->id]);
        $this->closing();

        $this->postJson("/api/accounting/journals/{$draft->id}/post")
            ->assertUnprocessable()->assertJsonPath('errors.branch.0', self::CLOSING);
        $this->assertSame('draft', $draft->refresh()->status);

        $this->postJson("/api/accounting/journals/{$posted->id}/reverse")
            ->assertUnprocessable()->assertJsonPath('errors.branch.0', self::CLOSING);
        $this->assertSame('posted', $posted->refresh()->status);
    }

    public function test_a_closing_branch_takes_no_reconciliation_action(): void
    {
        $reconciliation = AccountingReconciliation::create([
            'accounting_account_id' => $this->account('1040'), 'branch_id' => $this->far->id, 'period' => 'September 2026',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'statement_balance' => 0, 'created_by' => $this->admin->id,
        ]);
        $line = AccountingReconciliationLine::create([
            'accounting_reconciliation_id' => $reconciliation->id, 'date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000, 'created_by' => $this->admin->id,
        ]);
        $this->closing();

        foreach (['expense' => ['expense_account_id' => $this->account('5030'), 'payee' => 'Bank'], 'transfer' => ['counter_account_id' => $this->account('1010')], 'suspense' => []] as $action => $body) {
            $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/lines/{$line->id}/{$action}", $body)
                ->assertUnprocessable()->assertJsonPath('errors.branch.0', self::CLOSING);
        }

        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    public function test_a_user_is_not_put_in_a_closing_branch_nor_given_it_as_primary(): void
    {
        $this->closing();

        $this->postJson('/api/users', $this->newUser(['branch_ids' => [$this->branch->id, $this->far->id]]))
            ->assertUnprocessable()->assertJsonPath('errors.branch_ids.0', self::CLOSING);
        $this->assertNull(User::query()->where('username', 'newcashier')->first());

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('cashier');

        $this->putJson("/api/users/{$user->id}", ['branch_ids' => [$this->branch->id, $this->far->id]])
            ->assertUnprocessable()->assertJsonPath('errors.branch_ids.0', self::CLOSING);
        $this->assertSame([$this->branch->id], $user->branches()->pluck('branches.id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_an_interbranch_transfer_is_not_requested_into_or_out_of_a_closing_branch(): void
    {
        $this->closing();
        $payload = [
            'source_branch_id' => $this->branch->id, 'destination_branch_id' => $this->far->id,
            'from_account_id' => $this->account('1010'), 'to_account_id' => $this->account('1040'),
            'amount' => 120_000, 'purpose' => 'Cash for Monday releases',
        ];

        $this->postJson('/api/interbranch-transfers', $payload)
            ->assertUnprocessable()->assertJsonPath('errors.destination_branch_id.0', 'The destination branch is waiting to close, so no money can move into it.');
        $this->postJson('/api/interbranch-transfers', [...$payload, 'source_branch_id' => $this->far->id, 'destination_branch_id' => $this->branch->id])
            ->assertUnprocessable()->assertJsonPath('errors.source_branch_id.0', 'The source branch is waiting to close, so no money can move out of it.');
    }

    public function test_a_suspended_branch_with_no_closure_waiting_keeps_its_rules(): void
    {
        $this->postJson('/api/accounting/expenses', $this->expense())->assertCreated();
        $this->postJson('/api/accounting/cash-accounts/transfer', $this->transfer())->assertCreated();
        $id = $this->postJson('/api/accounting/journals', $this->journal())->assertCreated()->json('data.id');
        $this->postJson("/api/accounting/journals/{$id}/post")->assertOk();
        $this->postJson('/api/users', $this->newUser(['branch_ids' => [$this->far->id]]))->assertCreated();
    }

    public function test_a_rejected_or_cancelled_closure_no_longer_refuses(): void
    {
        $this->closing()->update(['status' => BranchClosure::STATUS_CANCELLED, 'decided_by' => $this->admin->id, 'decided_at' => now()]);

        $this->postJson('/api/accounting/expenses', $this->expense())->assertCreated();
    }

    // The lock order ------------------------------------------------------

    public function test_each_write_takes_the_branch_row_lock_first_then_the_closure_rows(): void
    {
        $draft = $this->draftJournal($this->lines(), ['branch_id' => $this->far->id]);
        $posted = $this->postJournal($this->lines(), ['branch_id' => $this->far->id]);
        $expense = AccountingExpense::factory()->create(['branch_id' => $this->far->id, 'payment_account_id' => null]);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('cashier');

        $writes = [
            'expense' => fn (): TestResponse => $this->postJson('/api/accounting/expenses', $this->expense())->assertCreated(),
            'expense payment' => fn (): TestResponse => $this->postJson("/api/accounting/expenses/{$expense->id}/pay", ['date' => now()->toDateString(), 'amount' => 100, 'account_id' => $this->account('1010')])->assertOk(),
            'fund transfer' => fn (): TestResponse => $this->postJson('/api/accounting/cash-accounts/transfer', $this->transfer())->assertCreated(),
            'journal create' => fn (): TestResponse => $this->postJson('/api/accounting/journals', $this->journal())->assertCreated(),
            'journal edit' => fn (): TestResponse => $this->putJson("/api/accounting/journals/{$draft->id}", $this->journal())->assertOk(),
            'journal post' => fn (): TestResponse => $this->postJson("/api/accounting/journals/{$draft->id}/post")->assertOk(),
            'journal reversal' => fn (): TestResponse => $this->postJson("/api/accounting/journals/{$posted->id}/reverse")->assertCreated(),
            'user create' => fn (): TestResponse => $this->postJson('/api/users', $this->newUser(['branch_ids' => [$this->far->id]]))->assertCreated(),
            'user update' => fn (): TestResponse => $this->putJson("/api/users/{$user->id}", ['branch_ids' => [$this->branch->id, $this->far->id]])->assertOk(),
        ];

        foreach ($writes as $name => $write) {
            $statements = $this->statementsInsideTheWrite($write);

            $this->assertGreaterThanOrEqual(2, count($statements), $name);
            $this->assertMatchesRegularExpression('/^select .* from `branches` where `id` in \(.*\) order by `id` asc lock in share mode$/', $statements[0], "{$name}: the branch rows are its first statement");
            $this->assertMatchesRegularExpression('/^select .* from `branch_closures` .* lock in share mode$/', $statements[1], "{$name}: then the closure rows");
        }
    }

    public function test_a_closure_step_takes_the_branch_row_for_update_first(): void
    {
        $statements = $this->statementsInsideTheWrite(fn (): TestResponse => $this->postJson("/api/branches/{$this->far->id}/closure", [
            'effective_on' => now()->toDateString(), 'reason' => 'Merged into Main',
        ])->assertCreated());

        $this->assertMatchesRegularExpression('/^select \* from `branches` where `branches`.`id` = \? limit 1 for update$/', $statements[0]);
    }

    // Deadlocks -----------------------------------------------------------

    public function test_a_deadlock_on_the_branch_lock_answers_the_worded_409_and_saves_nothing(): void
    {
        $this->deadlockOnce('lock in share mode', '`branches`');
        $before = AccountingExpense::count();

        $this->postJson('/api/accounting/expenses', $this->expense())
            ->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);
        $this->assertSame($before, AccountingExpense::count());
    }

    public function test_a_deadlock_on_a_user_save_or_a_journal_answers_409(): void
    {
        $this->deadlockOnce('lock in share mode', '`branches`');
        $this->postJson('/api/users', $this->newUser(['branch_ids' => [$this->far->id]]))->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);
        $this->assertNull(User::query()->where('username', 'newcashier')->first());

        $this->deadlockOnce('lock in share mode', '`branches`');
        $this->postJson('/api/accounting/journals', $this->journal())->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);
    }

    public function test_a_deadlock_on_a_closure_step_answers_409(): void
    {
        $this->deadlockOnce('for update', '`branches`');

        $this->postJson("/api/branches/{$this->far->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Merged into Main'])
            ->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);
        $this->assertSame(0, BranchClosure::query()->where('branch_id', $this->far->id)->count());
    }

    // Helpers -------------------------------------------------------------

    private function closing(): BranchClosure
    {
        return BranchClosure::create([
            'branch_id' => $this->far->id, 'status' => BranchClosure::STATUS_PENDING, 'effective_on' => now()->toDateString(),
            'reason' => 'Merged into Main', 'requested_by' => $this->admin->id,
        ]);
    }

    /**
     * The SQL run inside the write's own transaction (deeper than the test's
     * wrapper), in order: the request's validation reads run outside it.
     *
     * @return list<string>
     */
    private function statementsInsideTheWrite(\Closure $write): array
    {
        $baseline = DB::transactionLevel();
        $statements = [];
        $recording = true;

        DB::listen(function (QueryExecuted $query) use (&$statements, &$recording, $baseline): void {
            if ($recording && $query->connection->transactionLevel() > $baseline) {
                $statements[] = $query->sql;
            }
        });

        $write();
        $recording = false;

        return $statements;
    }

    private function deadlockOnce(string $lockClause, string $table): void
    {
        $fired = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $lockClause, $table): void {
            if ($fired || ! str_contains($query, $lockClause) || ! str_contains($query, "from {$table}")) {
                return;
            }

            $fired = true;
            $errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            $pdo = new PDOException("SQLSTATE[{$errorInfo[0]}]: General error: {$errorInfo[1]} {$errorInfo[2]}");
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $errorInfo[0]);

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });
    }

    /** @return list<array{account_id: int, debit: int, credit: int}> */
    private function lines(): array
    {
        return [
            ['account_id' => $this->account('1010'), 'debit' => 10_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 10_000],
        ];
    }

    /** @return array<string, mixed> */
    private function journal(): array
    {
        return ['date' => now()->toDateString(), 'description' => 'Cash count correction', 'branch_id' => $this->far->id, 'lines' => $this->lines()];
    }

    /** @return array<string, mixed> */
    private function expense(): array
    {
        return [
            'date' => now()->toDateString(), 'payee' => 'Meralco', 'expense_account_id' => $this->account('5030'),
            'amount' => 150000, 'payment_account_id' => $this->account('1010'), 'branch_id' => $this->far->id,
        ];
    }

    /** @return array<string, mixed> */
    private function transfer(): array
    {
        return [
            'date' => now()->toDateString(), 'from_account_id' => $this->account('1010'), 'to_account_id' => $this->account('1040'),
            'amount' => 50000, 'description' => 'Cash to bank', 'branch_id' => $this->far->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newUser(array $overrides = []): array
    {
        return [
            'username' => 'newcashier', 'first_name' => 'New', 'last_name' => 'Cashier', 'email' => 'new@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'cashier', ...$overrides,
        ];
    }
}
