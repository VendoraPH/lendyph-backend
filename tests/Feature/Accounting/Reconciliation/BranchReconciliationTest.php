<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingAccount;
use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\Branch;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Bank reconciliation per branch (owner answer 9, 2026-11-11): each cash or
 * bank account belongs to a branch, branch staff with `accounting:reconcile`
 * see and reconcile only their branches' accounts, `branches:view_all` sees
 * all, and another branch's reconciliation answers 404 like a missing one. A
 * reconciliation works with the journal lines of its account's branch only.
 */
class BranchReconciliationTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    private Branch $far;

    private int $mainBank;

    private int $farBank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        $this->mainBank = $this->account('1040');
        $this->putJson("/api/accounting/accounts/{$this->mainBank}", ['branch_id' => $this->branch->id])->assertOk();

        $this->farBank = (int) $this->postJson('/api/accounting/accounts', [
            'code' => '1045',
            'name' => 'Far Branch Bank',
            'type' => 'asset',
            'parent_id' => $this->account('1000'),
            'cash_kind' => 'bank',
            'branch_id' => $this->far->id,
        ])->assertCreated()->json('data.id');
    }

    /** A September 2026 reconciliation on $accountId, started by the admin. */
    private function reconciliationOn(int $accountId, array $lines = [], int $statementBalance = 0): AccountingReconciliation
    {
        $this->actingAs($this->admin);

        $id = $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $accountId,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => $statementBalance,
            'lines' => $lines,
        ])->assertCreated()->json('data.id');

        return AccountingReconciliation::query()->findOrFail($id);
    }

    /** A deposit of $centavos into $accountId, in $branchId's books (null: no branch). */
    private function depositInto(int $accountId, int $centavos, ?int $branchId, string $date = '2026-09-10'): AccountingJournal
    {
        return $this->postJournal([
            ['account_id' => $accountId, 'debit' => $centavos, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => $centavos],
        ], ['date' => $date, 'branch_id' => $branchId, 'description' => 'Deposit']);
    }

    private function lineOn(AccountingJournal $journal, int $accountId): int
    {
        return (int) AccountingJournalLine::query()
            ->where('accounting_journal_id', $journal->id)
            ->where('accounting_account_id', $accountId)
            ->value('id');
    }

    // ── Who sees which reconciliation ──

    public function test_a_branch_reconciler_lists_only_their_branchs_reconciliations(): void
    {
        $own = $this->reconciliationOn($this->mainBank);
        $this->reconciliationOn($this->farBank);
        $this->reconciliationOn($this->account('1010'));

        $this->actAsUserWith(['accounting:reconcile']);

        $response = $this->getJson('/api/accounting/reconciliations')->assertOk();
        $this->assertSame([$own->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));

        $this->getJson("/api/accounting/reconciliations?branch_id={$this->branch->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/accounting/reconciliations?branch_id={$this->far->id}")->assertForbidden();
    }

    public function test_view_all_sees_every_branchs_reconciliations_and_can_filter_by_branch(): void
    {
        $main = $this->reconciliationOn($this->mainBank);
        $far = $this->reconciliationOn($this->farBank);
        $none = $this->reconciliationOn($this->account('1010'));

        $this->actAsUserWith(['accounting:reconcile', BranchAccess::VIEW_ALL]);

        $ids = array_column($this->getJson('/api/accounting/reconciliations')->assertOk()->json('data'), 'id');
        sort($ids);
        $this->assertSame([$main->id, $far->id, $none->id], $ids);

        $this->assertSame(
            [$far->id],
            array_column($this->getJson("/api/accounting/reconciliations?branch_id={$this->far->id}")->json('data'), 'id'),
        );

        $this->getJson("/api/accounting/reconciliations/{$far->id}")->assertOk();
        $this->getJson("/api/accounting/reconciliations/{$none->id}")->assertOk();
    }

    public function test_each_reconciliation_names_its_accounts_branch(): void
    {
        $main = $this->reconciliationOn($this->mainBank);
        $none = $this->reconciliationOn($this->account('1010'));

        $this->getJson("/api/accounting/reconciliations/{$main->id}")
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_name', $this->branch->name);

        $this->getJson("/api/accounting/reconciliations/{$none->id}")
            ->assertOk()
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonPath('data.branch_name', null);
    }

    public function test_another_branchs_reconciliation_answers_404_on_every_endpoint(): void
    {
        $line = ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000];
        $far = $this->reconciliationOn($this->farBank, [$line]);
        $none = $this->reconciliationOn($this->account('1010'), [$line]);

        $this->actAsUserWith([
            'accounting:reconcile', 'expenses:create', 'cash_accounts:transfer', 'journals:create', 'journals:post',
        ]);

        foreach ([$far, $none] as $hidden) {
            $statementLine = $this->statementLine($hidden);

            $this->getJson("/api/accounting/reconciliations/{$hidden->id}")->assertNotFound();
            $this->postJson("/api/accounting/reconciliations/{$hidden->id}/match", ['accept_suggestions' => true])->assertNotFound();
            $this->act('expense', $hidden, $statementLine, ['expense_account_id' => $this->account('5070'), 'payee' => 'BDO'])->assertNotFound();
            $this->act('transfer', $hidden, $statementLine, ['counter_account_id' => $this->mainBank])->assertNotFound();
            $this->act('suspense', $hidden, $statementLine)->assertNotFound();
        }

        $this->assertSame(0, AccountingExpense::query()->count());
    }

    public function test_starting_one_on_another_branchs_account_reads_like_a_missing_account(): void
    {
        $this->actAsUserWith(['accounting:reconcile']);

        $payload = ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'statement_balance' => 0];

        $missing = $this->postJson('/api/accounting/reconciliations', [...$payload, 'account_id' => 999999])
            ->assertUnprocessable()
            ->json('errors.account_id');

        foreach ([$this->farBank, $this->account('1010')] as $hidden) {
            $this->assertSame(
                $missing,
                $this->postJson('/api/accounting/reconciliations', [...$payload, 'account_id' => $hidden])
                    ->assertUnprocessable()
                    ->json('errors.account_id'),
            );
        }

        $this->postJson('/api/accounting/reconciliations', [...$payload, 'account_id' => $this->mainBank])->assertCreated();

        $this->assertSame(1, AccountingReconciliation::query()->count());
    }

    public function test_a_branch_reconciler_can_start_match_and_act_on_their_own_account(): void
    {
        $journal = $this->depositInto($this->mainBank, 250000, $this->branch->id);

        $user = $this->actAsUserWith(['accounting:reconcile', 'expenses:create']);

        $data = $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->mainBank,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 235000,
            'lines' => [
                ['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 250000],
                ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000],
            ],
        ])->assertCreated()->json('data');

        $reconciliation = AccountingReconciliation::query()->findOrFail($data['id']);

        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", ['accept_suggestions' => true])
            ->assertOk();
        $this->assertSame($this->lineOn($journal, $this->mainBank), $this->statementLine($reconciliation)->matched_journal_line_id);

        $answer = $this->act('expense', $reconciliation, $this->statementLine($reconciliation, 1), [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertCreated();

        // The expense is in the account's branch, so it counts in this
        // reconciliation's books.
        $expense = AccountingExpense::query()->sole();
        $this->assertSame($this->branch->id, $expense->branch_id);
        $this->assertSame($this->branch->id, AccountingJournal::query()->findOrFail($expense->journal_id)->branch_id);
        $answer->assertJsonPath('data.book_balance', 235000)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.is_reconciled', true);

        $this->assertSame($user->id, $reconciliation->fresh()->created_by);
    }

    // ── Matching within the branch ──

    public function test_the_worksheet_and_book_balance_hold_only_the_accounts_branchs_lines(): void
    {
        $own = $this->depositInto($this->mainBank, 100000, $this->branch->id);
        $this->depositInto($this->mainBank, 40000, $this->far->id);
        $this->depositInto($this->mainBank, 7000, null);

        $reconciliation = $this->reconciliationOn($this->mainBank, [
            ['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 40000],
        ]);

        foreach ([['accounting:reconcile'], ['accounting:reconcile', BranchAccess::VIEW_ALL]] as $permissions) {
            $this->actAsUserWith($permissions);

            $data = $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")->assertOk()->json('data');

            // One figure for every reader: the account's branch only, never
            // another branch's lines and never lines with no branch.
            $this->assertSame(100000, $data['book_balance']);
            $journalLines = array_values(array_filter(array_column($data['lines'], 'journal_line_id')));
            $this->assertSame([$this->lineOn($own, $this->mainBank)], $journalLines);
            // The far branch's ₱400.00 deposit is never suggested for the
            // statement line of the same amount.
            $this->assertNotContains('possible', array_column($data['lines'], 'match'));
        }
    }

    public function test_a_line_in_another_branchs_books_cannot_be_matched(): void
    {
        $farJournal = $this->depositInto($this->mainBank, 40000, $this->far->id);
        $noBranch = $this->depositInto($this->mainBank, 40000, null, '2026-09-11');

        $reconciliation = $this->reconciliationOn($this->mainBank, [
            ['date' => '2026-09-10', 'description' => 'Deposit', 'amount' => 40000],
        ]);
        $statementLine = $this->statementLine($reconciliation);

        foreach ([$this->lineOn($farJournal, $this->mainBank), $this->lineOn($noBranch, $this->mainBank)] as $journalLineId) {
            $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/match", [
                'matches' => [['line_id' => $statementLine->id, 'journal_line_id' => $journalLineId]],
            ])->assertUnprocessable()->assertJsonValidationErrors('matches');
        }

        $this->assertNull($statementLine->refresh()->matched_journal_line_id);
    }

    public function test_an_account_with_no_branch_keeps_every_line_for_view_all(): void
    {
        $this->depositInto($this->account('1010'), 100000, $this->branch->id);
        $this->depositInto($this->account('1010'), 40000, $this->far->id);
        $this->depositInto($this->account('1010'), 7000, null);

        $reconciliation = $this->reconciliationOn($this->account('1010'));

        $this->getJson("/api/accounting/reconciliations/{$reconciliation->id}")
            ->assertOk()
            ->assertJsonPath('data.book_balance', 147000)
            ->assertJsonCount(3, 'data.lines');
    }

    public function test_actions_post_in_the_accounts_branch_and_a_transfer_stays_inside_it(): void
    {
        $mainCash = $this->account('1010');
        $this->putJson("/api/accounting/accounts/{$mainCash}", ['branch_id' => $this->branch->id])->assertOk();

        $reconciliation = $this->reconciliationOn($this->mainBank, [
            ['date' => '2026-09-12', 'description' => 'Cash deposited', 'amount' => 50000],
            ['date' => '2026-09-13', 'description' => 'Unknown credit', 'amount' => 1200],
        ]);

        $this->act('transfer', $reconciliation, $this->statementLine($reconciliation), ['counter_account_id' => $this->farBank])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counter_account_id');

        $transfer = $this->act('transfer', $reconciliation, $this->statementLine($reconciliation), ['counter_account_id' => $mainCash])
            ->assertCreated()
            ->json('created.journal_id');
        $this->assertSame($this->branch->id, AccountingJournal::query()->findOrFail($transfer)->branch_id);

        $suspense = $this->act('suspense', $reconciliation, $this->statementLine($reconciliation, 1))
            ->assertCreated()
            ->assertJsonPath('data.book_balance', 51200)
            ->json('created.journal_id');
        $this->assertSame($this->branch->id, AccountingJournal::query()->findOrFail($suspense)->branch_id);
    }

    // ── The accounts themselves ──

    public function test_another_branchs_money_account_answers_404_and_is_left_off_the_lists(): void
    {
        $this->actAsUserWith(['chart_of_accounts:view', 'chart_of_accounts:update', 'chart_of_accounts:delete', 'cash_accounts:view']);

        foreach ([$this->farBank, $this->account('1010')] as $hidden) {
            $this->getJson("/api/accounting/accounts/{$hidden}")->assertNotFound();
            $this->putJson("/api/accounting/accounts/{$hidden}", ['name' => 'Renamed'])->assertNotFound();
            $this->deleteJson("/api/accounting/accounts/{$hidden}")->assertNotFound();
        }

        $this->getJson("/api/accounting/accounts/{$this->mainBank}")
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_name', $this->branch->name);
        // An account that holds no money belongs to no branch, and every
        // branch sees it.
        $this->getJson('/api/accounting/accounts/'.$this->account('5070'))->assertOk()->assertJsonPath('data.branch_id', null);

        $chart = array_column($this->getJson('/api/accounting/accounts?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($this->mainBank, $chart);
        $this->assertContains($this->account('5070'), $chart);
        $this->assertNotContains($this->farBank, $chart);
        $this->assertNotContains($this->account('1010'), $chart);

        $cash = $this->getJson('/api/accounting/cash-accounts')->assertOk();
        $this->assertSame([$this->mainBank], array_column($cash->json('data'), 'id'));
        $this->assertSame(1, $cash->json('meta.total'));

        $this->assertSame('Far Branch Bank', AccountingAccount::query()->findOrFail($this->farBank)->name);
    }

    public function test_view_all_sees_every_money_account(): void
    {
        $this->actAsUserWith(['chart_of_accounts:view', 'cash_accounts:view', BranchAccess::VIEW_ALL]);

        $this->getJson("/api/accounting/accounts/{$this->farBank}")->assertOk()->assertJsonPath('data.branch_id', $this->far->id);

        $cash = array_column($this->getJson('/api/accounting/cash-accounts?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($this->farBank, $cash);
        $this->assertContains($this->mainBank, $cash);
        $this->assertContains($this->account('1010'), $cash);
    }

    public function test_the_cash_totals_cover_only_the_accounts_the_caller_sees(): void
    {
        $this->depositInto($this->mainBank, 100000, $this->branch->id);
        $this->depositInto($this->farBank, 40000, $this->branch->id);

        $this->actAsUserWith(['cash_accounts:view']);

        $this->getJson('/api/accounting/cash-accounts')
            ->assertOk()
            ->assertJsonPath('meta.totals.balance', 100000)
            ->assertJsonPath('meta.totals.by_kind.bank', 100000);
    }

    public function test_a_new_money_account_needs_a_branch_the_caller_can_see(): void
    {
        $base = ['type' => 'asset', 'parent_id' => $this->account('1000'), 'cash_kind' => 'bank'];

        $this->postJson('/api/accounting/accounts', [...$base, 'code' => '1046', 'name' => 'No Branch Bank'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->postJson('/api/accounting/accounts', ['code' => '1047', 'name' => 'Prepaid', 'type' => 'asset', 'parent_id' => $this->account('1000'), 'branch_id' => $this->branch->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->actAsUserWith(['chart_of_accounts:create']);

        $this->postJson('/api/accounting/accounts', [...$base, 'code' => '1048', 'name' => 'Far Bank Two', 'branch_id' => $this->far->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->postJson('/api/accounting/accounts', [...$base, 'code' => '1049', 'name' => 'Main Bank Two', 'branch_id' => $this->branch->id])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branch->id);

        $this->assertFalse(AccountingAccount::query()->whereIn('code', ['1046', '1047', '1048'])->exists());
    }

    public function test_an_accounts_branch_is_kept_once_it_has_a_reconciliation(): void
    {
        $this->putJson("/api/accounting/accounts/{$this->farBank}", ['branch_id' => $this->branch->id])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->id);
        $this->putJson("/api/accounting/accounts/{$this->farBank}", ['branch_id' => $this->far->id])->assertOk();

        $this->putJson("/api/accounting/accounts/{$this->farBank}", ['branch_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->reconciliationOn($this->farBank);

        $this->putJson("/api/accounting/accounts/{$this->farBank}", ['branch_id' => $this->branch->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        // Sending the stored branch back, as a whole-object save does, is not a change.
        $this->putJson("/api/accounting/accounts/{$this->farBank}", ['name' => 'Far Bank (renamed)', 'branch_id' => $this->far->id])->assertOk();

        $this->assertSame($this->far->id, AccountingAccount::query()->findOrFail($this->farBank)->branch_id);
    }
}
