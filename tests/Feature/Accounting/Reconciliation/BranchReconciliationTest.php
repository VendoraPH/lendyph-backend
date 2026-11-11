<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\Branch;
use App\Services\BranchAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Bank reconciliation per account per branch (owner answer 9 and the lead's
 * decision, 2026-11-11). Money accounts stay organisation-wide; each
 * reconciliation carries the branch whose journal lines it reconciles, picked
 * when it starts. A branch user picks one of their own branches;
 * `branches:view_all` picks any branch or none ("All branches", every line, as
 * before). Another branch's reconciliation, and one with no branch for a
 * branch user, answers 404 like a missing one.
 */
class BranchReconciliationTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    /** @return array<string, mixed> */
    private function payload(?int $branchId, array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->account('1040'),
            'branch_id' => $branchId,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 0,
        ], $overrides);
    }

    /** A September 2026 reconciliation of 1040 for $branchId (null: all branches), started by the admin. */
    private function reconciliationFor(?int $branchId, array $lines = []): AccountingReconciliation
    {
        $this->actingAs($this->admin);

        $id = $this->postJson('/api/accounting/reconciliations', $this->payload($branchId, ['lines' => $lines]))
            ->assertCreated()
            ->json('data.id');

        return AccountingReconciliation::query()->findOrFail($id);
    }

    /** A deposit of $centavos into $code, in $branchId's books (null: no branch). */
    private function deposit(int $centavos, ?int $branchId, string $date = '2026-09-10', string $code = '1040'): AccountingJournal
    {
        return $this->postJournal([
            ['account_id' => $this->account($code), 'debit' => $centavos, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => $centavos],
        ], ['date' => $date, 'branch_id' => $branchId, 'description' => 'Deposit']);
    }

    private function bankLineOf(AccountingJournal $journal): int
    {
        return (int) AccountingJournalLine::query()
            ->where('accounting_journal_id', $journal->id)
            ->where('accounting_account_id', $this->account('1040'))
            ->value('id');
    }

    // ── Starting one ──

    public function test_a_branch_reconciler_must_pick_one_of_their_own_branches(): void
    {
        $this->actAsUserWith(['accounting:reconcile']);

        $this->postJson('/api/accounting/reconciliations', $this->payload(null))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/accounting/reconciliations', $this->payload($this->far->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
        $this->assertSame(0, AccountingReconciliation::query()->count());

        $this->postJson('/api/accounting/reconciliations', $this->payload($this->branch->id))
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_name', $this->branch->name);
    }

    public function test_view_all_may_pick_any_branch_or_all_branches(): void
    {
        $this->actAsUserWith(['accounting:reconcile', BranchAccess::VIEW_ALL]);

        $this->postJson('/api/accounting/reconciliations', $this->payload($this->far->id))
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $this->far->id)
            ->assertJsonPath('data.branch_name', 'Far Branch');

        $this->postJson('/api/accounting/reconciliations', $this->payload(null))
            ->assertCreated()
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonPath('data.branch_name', 'All branches');

        // Leaving the field out is "all branches" too.
        $this->postJson('/api/accounting/reconciliations', array_diff_key(
            $this->payload(null, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']),
            ['branch_id' => true],
        ))->assertCreated()->assertJsonPath('data.branch_id', null);
    }

    public function test_one_reconciliation_per_account_branch_and_period(): void
    {
        $this->reconciliationFor($this->branch->id);
        $this->reconciliationFor($this->far->id);
        $this->reconciliationFor(null);

        foreach ([$this->branch->id, $this->far->id, null] as $branchId) {
            $this->postJson('/api/accounting/reconciliations', $this->payload($branchId))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('period');
        }

        $this->assertSame(3, AccountingReconciliation::query()->count());
    }

    public function test_the_database_refuses_a_second_all_branches_reconciliation_of_a_period(): void
    {
        $this->reconciliationFor(null);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('accounting_reconciliations')->insert([
            'accounting_account_id' => $this->account('1040'),
            'branch_id' => null,
            'period' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 0,
        ]);
    }

    // ── Who sees which ──

    public function test_a_branch_reconciler_lists_only_their_branchs_reconciliations(): void
    {
        $own = $this->reconciliationFor($this->branch->id);
        $far = $this->reconciliationFor($this->far->id);
        $all = $this->reconciliationFor(null);

        $this->actAsUserWith(['accounting:reconcile']);

        $response = $this->getJson('/api/accounting/reconciliations')->assertOk();
        $this->assertSame([$own->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
        $this->getJson("/api/accounting/reconciliations?branch_id={$this->branch->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/accounting/reconciliations?branch_id={$this->far->id}")->assertForbidden();

        $this->actAsUserWith(['accounting:reconcile', BranchAccess::VIEW_ALL]);

        $ids = array_column($this->getJson('/api/accounting/reconciliations')->assertOk()->json('data'), 'id');
        sort($ids);
        $this->assertSame([$own->id, $far->id, $all->id], $ids);
        $this->assertSame(
            [$far->id],
            array_column($this->getJson("/api/accounting/reconciliations?branch_id={$this->far->id}")->json('data'), 'id'),
        );
        $this->getJson("/api/accounting/reconciliations/{$all->id}")->assertOk()->assertJsonPath('data.branch_name', 'All branches');
    }

    public function test_another_branchs_and_an_all_branches_reconciliation_answer_404_on_every_endpoint(): void
    {
        $line = ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000];
        $far = $this->reconciliationFor($this->far->id, [$line]);
        $all = $this->reconciliationFor(null, [$line]);

        $this->actAsUserWith([
            'accounting:reconcile', 'expenses:create', 'cash_accounts:transfer', 'journals:create', 'journals:post',
        ]);
        $journals = $this->journalCount();

        foreach ([$far, $all] as $hidden) {
            $statementLine = $this->statementLine($hidden);

            $this->getJson("/api/accounting/reconciliations/{$hidden->id}")->assertNotFound();
            $this->postJson("/api/accounting/reconciliations/{$hidden->id}/match", ['accept_suggestions' => true])->assertNotFound();
            $this->act('expense', $hidden, $statementLine, ['expense_account_id' => $this->account('5070'), 'payee' => 'BDO'])->assertNotFound();
            $this->act('transfer', $hidden, $statementLine, ['counter_account_id' => $this->account('1010')])->assertNotFound();
            $this->act('suspense', $hidden, $statementLine)->assertNotFound();
        }

        $this->assertSame($journals, $this->journalCount());
        $this->assertSame(0, AccountingExpense::query()->count());
    }

    // ── What it counts ──

    public function test_a_branch_reconciliation_counts_only_that_branchs_lines(): void
    {
        $own = $this->deposit(100000, $this->branch->id);
        $this->deposit(40000, $this->far->id);
        $this->deposit(7000, null);

        $reconciliation = $this->reconciliationFor($this->branch->id, [
            ['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 40000],
        ]);

        foreach ([['accounting:reconcile'], ['accounting:reconcile', BranchAccess::VIEW_ALL]] as $permissions) {
            $this->actAsUserWith($permissions);

            $data = $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()->json('data');

            $this->assertSame(100000, $data['book_balance']);
            $this->assertSame([$this->bankLineOf($own)], array_values(array_filter(array_column($data['lines'], 'journal_line_id'))));
            // The far branch's ₱400.00 is never suggested for the statement
            // line of the same amount.
            $this->assertNotContains('possible', array_column($data['lines'], 'match'));
        }
    }

    public function test_an_all_branches_reconciliation_counts_every_line(): void
    {
        $this->deposit(100000, $this->branch->id);
        $this->deposit(40000, $this->far->id);
        $this->deposit(7000, null);

        $reconciliation = $this->reconciliationFor(null);

        $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")
            ->assertOk()
            ->assertJsonPath('data.book_balance', 147000)
            ->assertJsonCount(3, 'data.lines');
    }

    public function test_a_line_from_another_branch_or_no_branch_cannot_be_matched(): void
    {
        $farJournal = $this->deposit(40000, $this->far->id);
        $noBranch = $this->deposit(40000, null, '2026-09-11');

        $reconciliation = $this->reconciliationFor($this->branch->id, [
            ['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 40000],
        ]);
        $statementLine = $this->statementLine($reconciliation);

        foreach ([$this->bankLineOf($farJournal), $this->bankLineOf($noBranch)] as $journalLineId) {
            $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", [
                'matches' => [['line_id' => $statementLine->id, 'journal_line_id' => $journalLineId]],
            ])->assertUnprocessable()->assertJsonValidationErrors('matches');
        }

        $this->assertNull($statementLine->refresh()->matched_journal_line_id);
    }

    public function test_a_branch_reconciler_matches_and_records_within_their_branch(): void
    {
        $deposit = $this->deposit(250000, $this->branch->id);
        $this->deposit(250000, $this->far->id);

        $this->actAsUserWith(['accounting:reconcile', 'expenses:create', 'cash_accounts:transfer', 'journals:create', 'journals:post']);

        $id = $this->postJson('/api/accounting/reconciliations', $this->payload($this->branch->id, [
            'statement_balance' => 236200,
            'lines' => [
                ['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 250000],
                ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000],
                ['date' => '2026-09-13', 'description' => 'Cash deposited', 'amount' => 1000],
                ['date' => '2026-09-14', 'description' => 'Unknown credit', 'amount' => 200],
            ],
        ]))->assertCreated()->json('data.id');
        $reconciliation = AccountingReconciliation::query()->findOrFail($id);

        $this->postJson("/api/accounting/reconciliations/{$id}/match", ['accept_suggestions' => true])->assertOk();
        $this->assertSame($this->bankLineOf($deposit), $this->statementLine($reconciliation)->matched_journal_line_id);

        $this->act('expense', $reconciliation, $this->statementLine($reconciliation, 1), [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertCreated();
        $transfer = $this->act('transfer', $reconciliation, $this->statementLine($reconciliation, 2), [
            'counter_account_id' => $this->account('1010'),
        ])->assertCreated()->json('created.journal_id');
        $answer = $this->act('suspense', $reconciliation, $this->statementLine($reconciliation, 3))->assertCreated();

        // Every new entry is in the reconciliation's branch, so it counts in
        // this reconciliation's books.
        $expense = AccountingExpense::query()->sole();
        $this->assertSame($this->branch->id, $expense->branch_id);
        foreach ([$expense->journal_id, $transfer, $answer->json('created.journal_id')] as $journalId) {
            $this->assertSame($this->branch->id, AccountingJournal::query()->findOrFail($journalId)->branch_id);
        }

        $answer->assertJsonPath('data.book_balance', 236200)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.is_reconciled', true);
    }

    public function test_two_starts_at_once_answer_the_same_422_as_a_second_start(): void
    {
        // The second request passes the duplicate check before the first
        // commits; the unique index then refuses it, and it must read the same.
        AccountingReconciliation::creating(function (AccountingReconciliation $reconciliation): void {
            if (DB::table('accounting_reconciliations')->where('accounting_account_id', $reconciliation->accounting_account_id)->exists()) {
                return;
            }

            DB::table('accounting_reconciliations')->insert([
                'accounting_account_id' => $reconciliation->accounting_account_id,
                'branch_id' => $reconciliation->branch_id,
                'period' => $reconciliation->period,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'statement_balance' => 0,
            ]);
        });

        $this->postJson('/api/accounting/reconciliations', $this->payload($this->branch->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');

        // The rival row was written inside this request's transaction here,
        // so it rolled back with it; nothing of this request was kept.
        $this->assertSame(0, AccountingReconciliation::query()->count());
    }

    public function test_a_deadlock_on_start_answers_409_in_words_with_nothing_saved(): void
    {
        AccountingReconciliation::creating(function (): void {
            throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        });

        $this->postJson('/api/accounting/reconciliations', $this->payload($this->branch->id))
            ->assertConflict()
            ->assertJsonPath('message', 'Someone else was starting a reconciliation at the same moment. Nothing was saved; try again.');

        $this->assertSame(0, AccountingReconciliation::query()->count());
    }

    public function test_the_migration_will_not_roll_back_over_a_period_reconciled_twice(): void
    {
        $this->reconciliationFor($this->branch->id);
        $this->reconciliationFor(null);

        $migration = require database_path('migrations/2026_11_11_400000_add_branch_id_to_accounting_reconciliations.php');

        try {
            $migration->down();
            $this->fail('down() went ahead over a period reconciled twice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1040 Bank Accounts has more than one reconciliation for September 2026', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('accounting_reconciliations', 'branch_id'));
    }

    public function test_the_list_filter_validates_its_branch_and_takes_all_for_all_branches(): void
    {
        $own = $this->reconciliationFor($this->branch->id);
        $all = $this->reconciliationFor(null);

        $this->getJson('/api/accounting/reconciliations?branch_id=main')->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->assertSame(
            [$all->id],
            array_column($this->getJson('/api/accounting/reconciliations?branch_id=all')->assertOk()->json('data'), 'id'),
        );
        $this->assertSame(
            [$own->id],
            array_column($this->getJson("/api/accounting/reconciliations?branch_id={$this->branch->id}")->assertOk()->json('data'), 'id'),
        );

        $this->actAsUserWith(['accounting:reconcile']);
        $this->getJson('/api/accounting/reconciliations?branch_id=all')->assertForbidden();
        $this->getJson('/api/accounting/reconciliations?branch_id=main')->assertUnprocessable();
    }

    public function test_a_line_matched_on_one_side_is_claimed_for_the_other(): void
    {
        $deposit = $this->deposit(40000, $this->branch->id);
        $statement = [['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 40000]];
        $branchSide = $this->reconciliationFor($this->branch->id, $statement);
        $allSide = $this->reconciliationFor(null, $statement);
        $line = $this->bankLineOf($deposit);

        foreach ([[$branchSide, $allSide], [$allSide, $branchSide]] as [$first, $second]) {
            $this->postJson("/api/accounting/reconciliations/{$first->id}/match", [
                'matches' => [['line_id' => $this->statementLine($first)->id, 'journal_line_id' => $line]],
            ])->assertOk();

            $row = collect($this->getJson("/api/accounting/reconciliations/{$second->id}")->assertOk()->json('data.lines'))
                ->firstWhere('journal_line_id', $line);
            $this->assertNotNull($row, 'The claimed line left the other worksheet.');
            $this->assertNotSame('possible', $row['match']);
            $this->assertFalse($row['matchable']);

            $this->postJson("/api/accounting/reconciliations/{$second->id}/match", [
                'matches' => [['line_id' => $this->statementLine($second)->id, 'journal_line_id' => $line]],
            ])->assertUnprocessable()->assertJsonValidationErrors('matches');

            $this->postJson("/api/accounting/reconciliations/{$first->id}/match", [
                'matches' => [['line_id' => $this->statementLine($first)->id, 'journal_line_id' => null]],
            ])->assertOk();
        }
    }
}
