<?php

namespace Tests\Feature\Accounting\Dashboard;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\ReceivablePosition;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Step 4 and the money review of #242: the dashboard uses the statements'
 * own code, so its equity, period and monthly profit can never disagree with
 * the balance sheet and the income statement.
 */
class DashboardStatementsAgreeTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function dashboard(string $query): array
    {
        return $this->getJson('/api/accounting/dashboard?'.$query)->assertOk()->json('data');
    }

    public function test_total_equity_is_the_balance_sheets_and_the_books_balance(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2025-03-01']);
        $this->postSimpleJournal('1010', '4010', 400000, ['date' => '2025-11-10']);
        $this->postSimpleJournal('5030', '2010', 150000, ['date' => '2026-02-02']);
        $this->postSimpleJournal('1010', '4010', 250000, ['date' => '2026-09-10']);
        $this->postSimpleJournal('5030', '1010', 30000, ['date' => '2026-09-11', 'branch_id' => null]);

        foreach (['', '&branch_id='.$this->branch->id] as $branch) {
            $dashboard = $this->dashboard('as_of=2026-09-30'.$branch);
            $sheet = $this->getJson('/api/accounting/statements/balance-sheet?as_of=2026-09-30'.$branch)->assertOk()->json('data');

            $this->assertSame($sheet['total_equity'], $dashboard['total_equity'], $branch);
            $this->assertSame($sheet['total_assets'], $dashboard['total_assets'], $branch);
            $this->assertSame($sheet['total_liabilities'], $dashboard['total_liabilities'], $branch);
            $this->assertSame($dashboard['total_liabilities'] + $dashboard['total_equity'], $dashboard['total_assets'], $branch);
        }

        // Posted equity plus earnings not yet closed.
        $this->assertSame(10000000 + 400000 - 150000 + 250000 - 30000, $this->dashboard('as_of=2026-09-30')['total_equity']);
    }

    public function test_period_and_months_equal_the_income_statement_on_both_sides_of_31_december(): void
    {
        $this->postSimpleJournal('1010', '4010', 120000, ['date' => '2025-12-31']);
        $this->postSimpleJournal('5030', '1010', 20000, ['date' => '2025-12-31']);
        $this->postSimpleJournal('1010', '4020', 70000, ['date' => '2026-01-01']);
        $this->postSimpleJournal('5030', '1010', 5000, ['date' => '2026-01-02']);

        $dashboard = $this->dashboard('as_of=2026-01-31&from=2025-12-01');
        $statement = fn (string $from, string $to) => $this->getJson("/api/accounting/statements/income-statement?from={$from}&to={$to}")
            ->assertOk()->json('data');

        $whole = $statement('2025-12-01', '2026-01-31');
        $this->assertSame(['from' => '2025-12-01', 'to' => '2026-01-31', 'income' => 190000, 'expenses' => 25000, 'net_income' => 165000], $dashboard['period']);
        $this->assertSame($whole['total_income'], $dashboard['period']['income']);
        $this->assertSame($whole['total_expenses'], $dashboard['period']['expenses']);
        $this->assertSame($whole['net_income'], $dashboard['period']['net_income']);

        $months = collect($dashboard['months'])->keyBy('month');
        foreach ([['2025-12', '2025-12-01', '2025-12-31', 120000, 20000], ['2026-01', '2026-01-01', '2026-01-31', 70000, 5000]] as [$month, $from, $to, $income, $expenses]) {
            $this->assertSame($income, $months[$month]['income'], $month);
            $this->assertSame($expenses, $months[$month]['expenses'], $month);
            $this->assertSame($statement($from, $to)['net_income'], $months[$month]['net_income'], $month);
        }

        // Month to date is January's statement.
        $this->assertSame(70000, $dashboard['income_mtd']);
        $this->assertSame(5000, $dashboard['expenses_mtd']);
        $this->assertSame(65000, $dashboard['net_income_mtd']);
    }

    public function test_a_contra_account_on_its_other_side_reads_negative_and_net_follows(): void
    {
        $this->postSimpleJournal('1110', '1010', 1000000, ['date' => '2026-09-01']);
        // More released from the allowance and the deferral than they held:
        // both swing to a debit balance.
        $this->postSimpleJournal('1200', '5140', 30000, ['date' => '2026-09-02']);
        $this->postSimpleJournal('1210', '4010', 12000, ['date' => '2026-09-03']);

        $dashboard = $this->dashboard('as_of=2026-09-30');

        $this->assertSame(1000000, $dashboard['loans_receivable_gross']);
        $this->assertSame(-30000, $dashboard['allowance_for_credit_losses']);
        $this->assertSame(-12000, $dashboard['unearned_restructure_income']);
        $this->assertSame(1000000 + 30000 + 12000, $dashboard['loans_receivable_net']);
    }

    public function test_a_receivables_role_mapped_under_the_root_heading_counts_that_account_alone(): void
    {
        $this->postSimpleJournal('1110', '1010', 1000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1170', '1010', 50000, ['date' => '2026-09-02']);

        // 1170 Other Receivables sits directly under 1000 Assets.
        AccountingAccountMapping::query()->where('role', 'loans_receivable')
            ->update(['accounting_account_id' => AccountingAccount::query()->where('code', '1170')->value('id')]);

        $this->assertSame(50000, ReceivablePosition::build('2026-09-30', null)['gross']);
        $this->assertSame(50000, $this->dashboard('as_of=2026-09-30')['loans_receivable_gross']);
    }

    public function test_a_user_with_no_branch_and_no_view_all_has_no_past_due(): void
    {
        $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]);
        $this->assertGreaterThan(0, $this->dashboard('as_of=2026-09-30')['past_due']['count']);

        $role = Role::firstOrCreate(['name' => 'no_branch_accounting_reader', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting:view']);
        $user = User::factory()->create(['branch_id' => null]);
        $user->assignRole($role);
        $this->actingAs($user);

        $pastDue = $this->dashboard('as_of=2026-09-30')['past_due'];

        $this->assertSame(0, $pastDue['amount']);
        $this->assertSame(0, $pastDue['count']);
    }

    public function test_the_ledger_refuses_a_to_before_from(): void
    {
        $account = $this->account('1010');

        $this->getJson("/api/accounting/general-ledger?account_id={$account}&from=2026-09-30&to=2026-09-01")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson("/api/accounting/general-ledger?account_id={$account}&from=2026-09-30&to=2026-09-30")->assertOk();
        $this->getJson("/api/accounting/general-ledger?account_id={$account}&to=2026-09-01")->assertOk();
    }
}
