<?php

namespace Tests\Feature\Accounting\Dashboard;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The accounting branch rule on the dashboard's new figures and the general
 * ledger's meta: a branch user's figures leave out other branches and journals
 * with no branch (those are for `branches:view_all` only), another branch
 * answers 403, and every figure needs `accounting:view`.
 */
class DashboardBranchScopeTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        foreach ([[$this->branch->id, 1], [$this->far->id, 2], [null, 4]] as [$branchId, $factor]) {
            $this->postSimpleJournal('1010', '4010', 10000 * $factor, ['date' => '2026-09-02', 'branch_id' => $branchId]);
            $this->postSimpleJournal('5030', '1010', 1000 * $factor, ['date' => '2026-09-03', 'branch_id' => $branchId]);
            $this->postSimpleJournal('1110', '1010', 3000 * $factor, ['date' => '2026-09-04', 'branch_id' => $branchId]);
            $this->postSimpleJournal('1180', '1110', 100 * $factor, ['date' => '2026-09-05', 'branch_id' => $branchId]);
            $this->postSimpleJournal('1110', '1210', 200 * $factor, ['date' => '2026-09-06', 'branch_id' => $branchId]);
        }
    }

    private function branchUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'branch_accounting_reader', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting:view']);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_a_branch_users_new_figures_leave_out_other_branches_and_unbranched_journals(): void
    {
        $this->actingAs($this->branchUser());

        $dashboard = $this->getJson('/api/accounting/dashboard?as_of=2026-09-30')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30', 'income' => 10000, 'expenses' => 1000, 'net_income' => 9000], $dashboard['period']);
        $this->assertSame(10000 - 1000 - 3000, collect($dashboard['cash_accounts'])->firstWhere('account_code', '1010')['balance']);
        $this->assertSame(3000 - 100 + 200, $dashboard['loans_receivable_gross']);
        $this->assertSame(200, $dashboard['unearned_restructure_income']);
        $this->assertSame(3000 - 100, $dashboard['loans_receivable_net']);
        $this->assertSame(100, $dashboard['adjustments_clearing']);

        $september = collect($dashboard['months'])->firstWhere('month', '2026-09');
        $this->assertSame(['month' => '2026-09', 'income' => 10000, 'expenses' => 1000, 'net_income' => 9000, 'cash' => 6000], $september);

        $this->assertCount(5, $dashboard['recent_journals']);
        $this->assertSame([$this->branch->name], array_values(array_unique(array_column($dashboard['recent_journals'], 'branch_name'))));
    }

    public function test_view_all_sees_every_branch_and_unbranched_journals(): void
    {
        $dashboard = $this->getJson('/api/accounting/dashboard?as_of=2026-09-30')->assertOk()->json('data');

        $this->assertSame(70000, $dashboard['period']['income']);
        $this->assertSame(700, $dashboard['adjustments_clearing']);
        $this->assertSame(10, count($dashboard['recent_journals']));
        $this->assertContains(null, array_column($dashboard['recent_journals'], 'branch_name'));

        $far = $this->getJson('/api/accounting/dashboard?as_of=2026-09-30&branch_id='.$this->far->id)->assertOk()->json('data');
        $this->assertSame(20000, $far['period']['income']);
        $this->assertSame(['Far Branch'], array_values(array_unique(array_column($far['recent_journals'], 'branch_name'))));
    }

    public function test_the_ledger_meta_follows_the_branch_scope(): void
    {
        $this->actingAs($this->branchUser());

        $meta = $this->getJson('/api/accounting/general-ledger?account_id='.$this->account('1010').'&from=2026-09-03&to=2026-09-30')
            ->assertOk()->json('meta');

        $this->assertSame(10000, $meta['opening_balance']);
        $this->assertSame(0, $meta['total_debit']);
        $this->assertSame(4000, $meta['total_credit']);
        $this->assertSame(6000, $meta['closing_balance']);
    }

    public function test_another_branch_answers_403(): void
    {
        $this->actingAs($this->branchUser());

        $this->getJson('/api/accounting/dashboard?as_of=2026-09-30&branch_id='.$this->far->id)->assertForbidden();
        $this->getJson('/api/accounting/general-ledger?account_id='.$this->account('1010').'&source=expense&branch_id='.$this->far->id)->assertForbidden();
    }

    public function test_both_need_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole());

        $this->getJson('/api/accounting/dashboard?as_of=2026-09-30&from=2026-09-01')->assertForbidden();
        $this->getJson('/api/accounting/general-ledger?account_id='.$this->account('1010').'&source=expense')->assertForbidden();
    }
}
