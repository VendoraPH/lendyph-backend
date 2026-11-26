<?php

namespace Tests\Feature\FinishRunRound7;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\User;
use App\Services\Accounting\ExpenseRecorder;
use App\Services\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7: an "all branches" reconciliation (no branch) belongs to
 * head office. A user without `branches:view_all` who reverses an expense
 * recorded from one of its statement lines would free that line, so the
 * reversal is refused in words: nothing is posted and the line stays matched,
 * and they ask head office. A branch reconciliation, and users holding
 * `branches:view_all`, are unchanged (round 6).
 */
class AllBranchesReconciliationReversalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private User $branchUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->branchUser = User::factory()->inBranches($this->branch)->create(['first_name' => 'Bea', 'last_name' => 'Cruz']);
        $this->branchUser->givePermissionTo([
            'expenses:view', 'expenses:create', 'expenses:update', 'journals:reverse',
            'accounting:view', 'accounting:reconcile',
        ]);
    }

    public function test_a_branch_user_cannot_free_a_line_on_an_all_branches_reconciliation(): void
    {
        [$reconciliation, $line] = $this->reconciliation(null);
        $expense = $this->expenseFrom($reconciliation, $line);
        $matched = $line->refresh()->matched_journal_line_id;
        $journals = AccountingJournal::query()->count();
        $this->assertNotNull($matched);

        try {
            app(ExpenseRecorder::class)->reverse($expense, 'Recorded in error', $this->branchUser->id);
            $this->fail('The reversal should be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['This expense is matched on head office\'s all-branches reconciliation of 1040 Bank Accounts for September 2026. Ask head office to reverse it.'],
                $e->errors()['expense'],
            );
        }

        $this->assertSame($journals, AccountingJournal::query()->count(), 'nothing was posted');
        $this->assertSame($matched, $line->refresh()->matched_journal_line_id, 'the line stays matched');
        $this->assertSame('posted', AccountingJournal::query()->findOrFail($expense->journal_id)->status);

        // Through the API the expense, which has no branch, isn't theirs to reach.
        $this->actingAs($this->branchUser)
            ->postJson("/api/accounting/expenses/{$expense->id}/reverse", ['reason' => 'Recorded in error'])
            ->assertNotFound();
        $this->assertSame($journals, AccountingJournal::query()->count());

        // Head office reverses it, as before, and the line is freed.
        $this->actingAs($this->admin)
            ->postJson("/api/accounting/expenses/{$expense->id}/reverse", ['reason' => 'Recorded in error'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    public function test_a_view_all_user_without_super_admin_still_frees_the_line(): void
    {
        $headOffice = User::factory()->inBranches($this->branch)->create();
        $headOffice->givePermissionTo(['expenses:view', 'expenses:update', 'journals:reverse', BranchAccess::VIEW_ALL]);
        [$reconciliation, $line] = $this->reconciliation(null);
        $expense = $this->expenseFrom($reconciliation, $line);

        $this->actingAs($headOffice)
            ->postJson("/api/accounting/expenses/{$expense->id}/reverse", ['reason' => 'Recorded in error'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertNull($line->refresh()->matched_journal_line_id);
    }

    public function test_a_branch_user_still_frees_a_line_on_their_own_branch_reconciliation(): void
    {
        [$reconciliation, $line] = $this->reconciliation($this->branch->id);
        $expense = $this->expenseFrom($reconciliation, $line);
        $this->assertSame($this->branch->id, $expense->branch_id);

        $this->actingAs($this->branchUser)
            ->postJson("/api/accounting/expenses/{$expense->id}/reverse", ['reason' => 'Recorded in error'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertNull($line->refresh()->matched_journal_line_id, 'the line is unmatched again');
    }

    /**
     * A September 2026 reconciliation of 1040, with one ₱150.00 money-out
     * statement line on Sep 12, for one branch or all branches (null).
     *
     * @return array{AccountingReconciliation, AccountingReconciliationLine}
     */
    private function reconciliation(?int $branchId): array
    {
        $id = $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'branch_id' => $branchId,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 0,
            'lines' => [['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000]],
        ])->assertCreated()->json('data.id');

        return [
            AccountingReconciliation::query()->findOrFail($id),
            AccountingReconciliationLine::query()->where('accounting_reconciliation_id', $id)->sole(),
        ];
    }

    private function expenseFrom(AccountingReconciliation $reconciliation, AccountingReconciliationLine $line): AccountingExpense
    {
        $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/lines/{$line->id}/expense", [
            'expense_account_id' => $this->account('5070'),
            'payee' => 'BDO',
        ])->assertCreated();

        return AccountingExpense::query()->where('reconciliation_line_id', $line->id)->sole();
    }
}
