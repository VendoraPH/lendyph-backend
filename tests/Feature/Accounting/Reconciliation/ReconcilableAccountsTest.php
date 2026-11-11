<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingAccount;
use App\Models\Branch;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 3 follow-ups, item B3. Starting a reconciliation needs the accounts
 * it can be started on, and the chart of accounts needs `chart_of_accounts:view`,
 * so a role holding only `accounting:reconcile` couldn't start one.
 * `GET /accounting/reconciliations/accounts` lists, on `accounting:reconcile`
 * alone, exactly the accounts a reconciliation accepts: active, postable money
 * accounts (cash, bank, GCash, Maya, e-wallet), with no balances.
 */
class ReconcilableAccountsTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    private const URL = '/api/accounting/reconciliations/accounts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        $parent = $this->account('1000');
        AccountingAccount::create(['code' => '1041', 'name' => 'BDO Savings', 'type' => 'asset', 'parent_id' => $parent, 'cash_kind' => 'bank', 'is_active' => true, 'created_by' => $this->admin->id]);
        AccountingAccount::create(['code' => '1042', 'name' => 'Closed Bank', 'type' => 'asset', 'parent_id' => $parent, 'cash_kind' => 'bank', 'is_active' => false, 'created_by' => $this->admin->id]);
    }

    public function test_a_reconcile_only_role_gets_only_the_reconcilable_money_accounts(): void
    {
        $this->actAsUserWith(['accounting:reconcile']);

        $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => [
            ['id' => $this->account('1010'), 'code' => '1010', 'name' => 'Cash on Hand', 'cash_kind' => 'cash', 'type' => 'Cash'],
            ['id' => $this->account('1020'), 'code' => '1020', 'name' => 'GCash', 'cash_kind' => 'gcash', 'type' => 'GCash'],
            ['id' => $this->account('1030'), 'code' => '1030', 'name' => 'Maya', 'cash_kind' => 'maya', 'type' => 'Maya'],
            ['id' => $this->account('1040'), 'code' => '1040', 'name' => 'Bank Accounts', 'cash_kind' => 'bank', 'type' => 'Bank'],
            ['id' => AccountingAccount::query()->where('code', '1041')->value('id'), 'code' => '1041', 'name' => 'BDO Savings', 'cash_kind' => 'bank', 'type' => 'Bank'],
        ]]);
    }

    public function test_every_listed_account_starts_a_reconciliation(): void
    {
        $this->actAsUserWith(['accounting:reconcile']);

        foreach ($this->getJson(self::URL)->assertOk()->json('data') as $account) {
            $this->postJson('/api/accounting/reconciliations', [
                'account_id' => $account['id'],
                'branch_id' => $this->branch->id,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'statement_balance' => 0,
            ])->assertCreated();
        }
    }

    public function test_the_list_is_the_same_for_a_branch_user_and_view_all(): void
    {
        $this->actAsUserWith(['accounting:reconcile']);
        $branchUser = $this->getJson(self::URL)->assertOk()->json('data');

        $this->actAsUserWith(['accounting:reconcile', BranchAccess::VIEW_ALL]);
        $this->assertSame($branchUser, $this->getJson(self::URL)->assertOk()->json('data'));
    }

    public function test_a_branch_filter_outside_the_callers_branches_answers_403(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->actAsUserWith(['accounting:reconcile']);
        $all = $this->getJson(self::URL)->assertOk()->json('data');

        $this->getJson(self::URL.'?branch_id='.$far->id)->assertForbidden();
        $this->assertSame($all, $this->getJson(self::URL.'?branch_id='.$this->branch->id)->assertOk()->json('data'));
        $this->getJson(self::URL.'?branch_id=abc')->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->actAsUserWith(['accounting:reconcile', BranchAccess::VIEW_ALL]);
        $this->getJson(self::URL.'?branch_id='.$far->id)->assertOk();
    }

    public function test_it_needs_accounting_reconcile(): void
    {
        $this->actAsUserWith([]);
        $this->getJson(self::URL)->assertForbidden();

        $this->actAsUserWith(['chart_of_accounts:view', 'cash_accounts:view']);
        $this->getJson(self::URL)->assertForbidden();
    }
}
