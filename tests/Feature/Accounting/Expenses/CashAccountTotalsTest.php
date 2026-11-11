<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingAccount;
use App\Models\Branch;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The Cash & Bank list's `meta.totals` (Phase 2, Accounting 10): the headline
 * "Total across all money accounts" and the per-kind totals cover every money
 * account, not the page, in the same branch scope as each row's balance.
 */
class CashAccountTotalsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    public function test_totals_cover_more_accounts_than_one_page_by_kind(): void
    {
        $secondBank = $this->moneyAccount('1050', 'Second Bank', 'bank');
        $wallet = $this->moneyAccount('1060', 'Petty Wallet', 'wallet');
        $this->moneyAccount('1070', 'Closed Wallet', 'wallet', ['is_active' => false]);

        $this->postSimpleJournal('1010', '3010', 500000);
        $this->postSimpleJournal('1020', '3010', 7891);
        $this->postSimpleJournal('1040', '3010', 2000000);
        $this->postJournal([
            ['account_id' => $secondBank, 'debit' => 123456, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 123456],
        ]);
        // An overdrawn wallet counts as a negative, never clamped.
        $this->postJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $wallet, 'debit' => 0, 'credit' => 100],
        ]);

        $first = $this->getJson('/api/accounting/cash-accounts?per_page=2')->assertOk();
        $this->assertCount(2, $first->json('data'));

        $balances = [];
        for ($page = 1; $page <= $first->json('meta.last_page'); $page++) {
            $balances = array_merge($balances, array_column(
                $this->getJson("/api/accounting/cash-accounts?per_page=2&page={$page}")->json('data'),
                'balance',
            ));
        }

        $totals = $first->json('meta.totals');
        $this->assertSame(array_sum($balances), $totals['balance']);
        $this->assertSame(500000 + 7891 + 2000000 + 123456 - 100, $totals['balance']);
        $this->assertSame([
            'cash' => 500000,
            'bank' => 2000000 + 123456,
            'gcash' => 7891,
            'maya' => 0,
            'wallet' => -100,
        ], $totals['by_kind']);
    }

    public function test_totals_follow_the_callers_branch_scope(): void
    {
        $this->postSimpleJournal('1010', '3010', 1000, ['branch_id' => $this->branch->id]);
        $this->postSimpleJournal('1010', '3010', 20000, ['branch_id' => $this->far->id]);
        $this->postSimpleJournal('1040', '3010', 300000, ['branch_id' => null]);
        // Cash & Bank lists a money account to its own branch's staff (owner
        // answer 9, 2026-11-11), so the branch user sees 1010 once it has one.
        AccountingAccount::query()->whereKey($this->account('1010'))->update(['branch_id' => $this->branch->id]);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $user->givePermissionTo('cash_accounts:view');
        $this->actingAs($user);

        $totals = $this->getJson('/api/accounting/cash-accounts')->assertOk()->json('meta.totals');
        $this->assertSame(1000, $totals['balance']);
        $this->assertSame(['cash' => 1000, 'bank' => 0, 'gcash' => 0, 'maya' => 0, 'wallet' => 0], $totals['by_kind']);
        $this->getJson("/api/accounting/cash-accounts?branch_id={$this->far->id}")->assertForbidden();

        $this->actingAs($this->admin);
        $this->assertSame(321000, $this->getJson('/api/accounting/cash-accounts')->json('meta.totals.balance'));
        $this->assertSame(20000, $this->getJson("/api/accounting/cash-accounts?branch_id={$this->far->id}")->json('meta.totals.balance'));
    }

    public function test_an_empty_chart_answers_zero_totals(): void
    {
        AccountingAccount::query()->whereNotNull('cash_kind')->update(['cash_kind' => null]);

        $this->assertSame(
            ['balance' => 0, 'by_kind' => ['cash' => 0, 'bank' => 0, 'gcash' => 0, 'maya' => 0, 'wallet' => 0]],
            $this->getJson('/api/accounting/cash-accounts')->assertOk()->json('meta.totals'),
        );
    }

    /** @param  array<string, mixed>  $overrides */
    private function moneyAccount(string $code, string $name, string $kind, array $overrides = []): int
    {
        $parent = AccountingAccount::query()->where('code', '1010')->value('parent_id');

        return (int) AccountingAccount::create(array_merge([
            'code' => $code,
            'name' => $name,
            'type' => 'asset',
            'normal_balance' => 'debit',
            'parent_id' => $parent,
            'cash_kind' => $kind,
            'is_group' => false,
            'is_contra' => false,
            'is_active' => true,
        ], $overrides))->id;
    }
}
