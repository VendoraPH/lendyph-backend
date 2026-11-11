<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingExpense;
use App\Models\AccountingReconciliation;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Who may record a statement line as an expense, a transfer or a suspense
 * entry: `accounting:reconcile`, plus the permission of the record each action
 * writes (Accounting round C, 2026-11-09), on a reconciliation the caller can
 * see (owner answer 9, 2026-11-11: the account's branch, or any for
 * `branches:view_all`). These reconciliations are on accounts with no branch,
 * so the callers here hold `branches:view_all`; BranchReconciliationTest
 * covers branch users.
 */
class ReconciliationActionPermissionsTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    private AccountingReconciliation $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        $this->reconciliation = $this->reconciliationWith([
            ['date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function bodies(): array
    {
        return [
            'expense' => ['expense_account_id' => $this->account('5070'), 'payee' => 'BDO'],
            'transfer' => ['counter_account_id' => $this->account('1010')],
            'suspense' => [],
        ];
    }

    private const RECORD_PERMISSIONS = [
        'expense' => ['expenses:create'],
        'transfer' => ['cash_accounts:transfer'],
        'suspense' => ['journals:create', 'journals:post'],
    ];

    private function assertNothingWritten(int $journals): void
    {
        $this->assertSame($journals, $this->journalCount());
        $this->assertSame(0, AccountingExpense::query()->count());
        $this->assertNull($this->statementLine($this->reconciliation)->matched_journal_line_id);
    }

    public function test_every_action_needs_accounting_reconcile(): void
    {
        $journals = $this->journalCount();

        foreach ($this->bodies() as $action => $body) {
            $this->actAsUserWith([BranchAccess::VIEW_ALL, ...self::RECORD_PERMISSIONS[$action]]);

            $this->act($action, $this->reconciliation, $this->statementLine($this->reconciliation), $body)
                ->assertForbidden();
        }

        $this->assertNothingWritten($journals);
    }

    public function test_every_action_on_an_account_with_no_branch_needs_branches_view_all(): void
    {
        $journals = $this->journalCount();

        foreach ($this->bodies() as $action => $body) {
            $this->actAsUserWith(['accounting:reconcile', ...self::RECORD_PERMISSIONS[$action]]);

            // Only branches:view_all sees an account with no branch, so its
            // reconciliation answers like a missing one (owner answer 9).
            $this->act($action, $this->reconciliation, $this->statementLine($this->reconciliation), $body)
                ->assertNotFound();
        }

        $this->assertNothingWritten($journals);
    }

    public function test_each_action_needs_its_own_records_permission(): void
    {
        $journals = $this->journalCount();

        foreach ($this->bodies() as $action => $body) {
            $this->actAsUserWith(['accounting:reconcile', BranchAccess::VIEW_ALL]);

            $this->act($action, $this->reconciliation, $this->statementLine($this->reconciliation), $body)
                ->assertForbidden();
        }

        $this->assertNothingWritten($journals);
    }

    public function test_the_bookkeeper_can_record_an_expense_but_cannot_post_a_suspense_entry(): void
    {
        $this->actingAs($this->userWithRole('general_bookkeeper'));
        $line = $this->statementLine($this->reconciliation);
        $journals = $this->journalCount();

        // `journals:create` without `journals:post`: a suspense entry posts on
        // the spot, so drafting rights are not enough.
        $this->act('suspense', $this->reconciliation, $line)->assertForbidden();
        $this->assertSame($journals, $this->journalCount());

        $this->act('expense', $this->reconciliation, $line, $this->bodies()['expense'])->assertCreated();
    }

    public function test_each_action_succeeds_with_exactly_its_permissions(): void
    {
        foreach ($this->bodies() as $action => $body) {
            $reconciliation = $this->reconciliationWith([
                ['date' => '2026-09-12', 'description' => "Line for {$action}", 'amount' => -15000],
            ], match ($action) {
                'expense' => '1020',
                'transfer' => '1030',
                'suspense' => '1010',
            });

            $this->actAsUserWith(['accounting:reconcile', BranchAccess::VIEW_ALL, ...self::RECORD_PERMISSIONS[$action]]);

            $this->act($action, $reconciliation, $this->statementLine($reconciliation), $body)->assertCreated();

            $this->actingAs($this->admin);
        }
    }

    public function test_a_user_with_no_role_is_refused(): void
    {
        $user = $this->userWithNoRole();
        $user->givePermissionTo(BranchAccess::VIEW_ALL);
        $this->actingAs($user);

        // view_all only, so the reconciliation is visible and the answer is
        // the permission's; without it the hidden account answers 404.
        $this->act('suspense', $this->reconciliation, $this->statementLine($this->reconciliation))->assertForbidden();

        $this->actingAs($this->userWithNoRole());
        $this->act('suspense', $this->reconciliation, $this->statementLine($this->reconciliation))->assertNotFound();
    }
}
