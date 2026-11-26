<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Services\Accounting\ReconciliationMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Whether the reconciliation an expense was recorded from reads Reconciled is
 * judged from its statement lines as locked, never from the snapshot the
 * reversal read before the lock (finish run round 6, review of #396). A match
 * committed on the worksheet right before the lock counts: here it finishes
 * the reconciliation, so the reversal is refused.
 *
 * The match lands on a second connection, as another user saving the
 * worksheet would, so this file opts out of the per-test transaction: the
 * other connection only sees committed rows.
 */
class ExpenseReconciliationLockTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'expense_reconciliation_lock';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_match_committed_just_before_the_lock_finishes_the_reconciliation_and_refuses_the_reversal(): void
    {
        // ₱150.00 out (the expense) and ₱50.00 in (a deposit already in the books).
        $deposit = $this->postSimpleJournal('1040', '4080', 5000, ['date' => '2026-09-15']);
        $id = $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => -10000,
            'lines' => [
                ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000],
                ['date' => '2026-09-15', 'description' => 'Deposit', 'amount' => 5000],
            ],
        ])->assertCreated()->json('data.id');
        [$chargeLine, $depositLine] = AccountingReconciliationLine::query()->where('accounting_reconciliation_id', $id)->orderBy('id')->get()->all();

        $this->postJson("/api/accounting/reconciliations/{$id}/lines/{$chargeLine->id}/expense", [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertCreated();
        $expense = AccountingExpense::query()->sole();
        $this->assertFalse(app(ReconciliationMatcher::class)->present(AccountingReconciliation::query()->findOrFail($id))['is_reconciled']);

        $depositBankLine = AccountingJournalLine::query()->where('accounting_journal_id', $deposit->id)->where('accounting_account_id', $this->account('1040'))->sole()->id;
        $landed = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$landed, $depositLine, $depositBankLine): void {
            if ($landed || ! str_contains($query, 'from `accounting_reconciliation_lines`') || ! str_contains($query, 'for update')) {
                return;
            }

            $landed = true;
            DB::connection(self::OTHER_CONNECTION)->table('accounting_reconciliation_lines')
                ->where('id', $depositLine->id)
                ->update(['matched_journal_line_id' => $depositBankLine]);
        });
        $journals = AccountingJournal::query()->count();

        $this->postJson("/api/accounting/expenses/{$expense->id}/reverse", ['reason' => 'Recorded in error'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.expense.0', 'This expense is matched on the September 2026 reconciliation of 1040 Bank Accounts (All branches), which reads Reconciled. '
                .'Reopen that reconciliation first by unmatching its statement line there, then reverse again.');

        $this->assertTrue($landed, 'the match landed right before the lock');
        $this->assertSame($journals, AccountingJournal::query()->count());
        $this->assertNotNull($chargeLine->refresh()->matched_journal_line_id);
    }
}
