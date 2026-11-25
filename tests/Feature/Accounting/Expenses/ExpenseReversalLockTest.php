<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Services\Branches\BranchRowLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Reversing a payment or an expense takes the locks paying takes (finish run
 * round 5), shown on two real connections: the branch, then the expense row,
 * then the journals. So a payment and a reversal on one expense, or two
 * reversals, never interleave: while one holds the expense, the other waits,
 * and one that waits too long answers 409 in words with nothing recorded. One
 * that gets the lock reads the other's result (ExpenseReversalTest). Paying
 * reads each payment's journal under the lock a reversal takes, so it never
 * counts a payment whose reversal is in flight.
 */
class ExpenseReversalLockTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'expense_reversal_lock';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER_CONNECTION)->rollBack(0);
        DB::purge(self::OTHER_CONNECTION);
        DB::statement('SET SESSION innodb_lock_wait_timeout = @@GLOBAL.innodb_lock_wait_timeout');
        parent::tearDown();
    }

    /**
     * A payment, an edit or another reversal holding the expense row: each
     * reversal and the edit wait, and answer 409 with nothing recorded.
     */
    public function test_the_reversals_and_the_edit_wait_for_a_write_holding_the_expense(): void
    {
        $id = $this->record();
        $payment = $this->pay($id);
        $journals = AccountingJournal::query()->count();

        $this->holdOnTheOtherConnection('accounting_expenses', $id);

        $this->postJson("/api/accounting/expenses/{$id}/payments/{$payment->id}/reverse", ['reason' => 'Paid twice'])
            ->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);
        $this->postJson("/api/accounting/expenses/{$id}/reverse", ['reason' => 'Recorded twice'])
            ->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);
        $this->putJson("/api/accounting/expenses/{$id}", ['payee' => 'Someone else'])
            ->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);

        DB::connection(self::OTHER_CONNECTION)->rollBack();
        $this->assertSame($journals, AccountingJournal::query()->count());
        $this->assertSame('posted', AccountingJournal::query()->findOrFail($payment->journal_id)->status);
        $this->assertSame('Meralco', AccountingExpense::query()->findOrFail($id)->payee);
    }

    /**
     * A reversal in flight holds the payment's journal, FOR UPDATE, as
     * JournalPoster::reverse() takes it: a payment waits rather than counting
     * that payment, and answers 409 with nothing recorded.
     */
    public function test_a_payment_waits_for_a_reversal_holding_a_payments_journal(): void
    {
        $id = $this->record();
        $payment = $this->pay($id);
        // A later entry, so the payment's journal isn't the newest numbered
        // one, which every post locks to number itself (allocateJournalNo()).
        $this->postSimpleJournal('5030', '1010', 100, ['date' => now()->toDateString()]);

        $this->holdOnTheOtherConnection('accounting_journals', $payment->journal_id);

        $this->postJson("/api/accounting/expenses/{$id}/pay", [
            'date' => now()->toDateString(), 'amount' => 10000, 'account_id' => $this->account('1010'),
        ])->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);

        DB::connection(self::OTHER_CONNECTION)->rollBack();
        $this->assertSame(1, AccountingExpensePayment::query()->where('accounting_expense_id', $id)->count());
    }

    /** Locks one row FOR UPDATE on the other connection, and waits at most a second for it here. */
    private function holdOnTheOtherConnection(string $table, int $id): void
    {
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table($table)->where('id', $id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    private function record(): int
    {
        return $this->postJson('/api/accounting/expenses', [
            'date' => now()->toDateString(), 'payee' => 'Meralco', 'expense_account_id' => $this->account('5030'),
            'amount' => 150000, 'branch_id' => $this->branch->id,
        ])->assertCreated()->json('data.id');
    }

    private function pay(int $expenseId): AccountingExpensePayment
    {
        $this->postJson("/api/accounting/expenses/{$expenseId}/pay", [
            'date' => now()->toDateString(), 'amount' => 50000, 'account_id' => $this->account('1010'),
        ])->assertOk();

        return AccountingExpensePayment::query()->where('accounting_expense_id', $expenseId)->sole();
    }
}
