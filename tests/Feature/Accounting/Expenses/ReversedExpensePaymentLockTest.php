<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Services\Branches\BranchRowLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A payment and a reversal of the expense's journal never interleave (finish
 * run round 4), shown on two real connections. A reversal locks the journal
 * row FOR UPDATE (JournalPoster::reverse()) and never the expense row, so the
 * payment reads the journal under the same lock: while a reversal holds it,
 * the payment waits, and one that waits too long answers 409 with nothing
 * recorded. A reversal that commits first is then seen, and the payment is
 * refused (ReversedExpenseTest).
 */
class ReversedExpensePaymentLockTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'reversed_expense_payment_lock';

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

    public function test_a_payment_waits_for_a_reversal_holding_the_expenses_journal(): void
    {
        $id = $this->postJson('/api/accounting/expenses', [
            'date' => now()->toDateString(), 'payee' => 'Meralco', 'expense_account_id' => $this->account('5030'),
            'amount' => 150000, 'branch_id' => $this->branch->id,
        ])->assertCreated()->json('data.id');
        $journalId = AccountingExpense::query()->findOrFail($id)->journal_id;
        // A later entry, so the expense's journal isn't the newest numbered
        // one, which every post locks to number itself (allocateJournalNo()).
        $this->postSimpleJournal('5030', '1010', 100, ['date' => now()->toDateString()]);

        // A reversal in flight: the journal row, FOR UPDATE, as JournalPoster::reverse() takes it.
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('accounting_journals')->where('id', $journalId)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->postJson("/api/accounting/expenses/{$id}/pay", [
            'date' => now()->toDateString(), 'amount' => 10000, 'account_id' => $this->account('1010'),
        ])->assertStatus(409)->assertExactJson(['message' => BranchRowLock::CONFLICT]);

        $other->rollBack();
        $this->assertSame(0, AccountingExpensePayment::query()->where('accounting_expense_id', $id)->count());
        $this->assertSame(0, AccountingExpense::query()->findOrFail($id)->amount_paid);
    }
}
