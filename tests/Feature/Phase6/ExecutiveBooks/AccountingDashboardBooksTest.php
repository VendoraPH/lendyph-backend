<?php

namespace Tests\Feature\Phase6\ExecutiveBooks;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingOpeningBalance;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\IncomeStatementBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D2, ask 4: the accounting dashboard's interest, penalty and fee
 * income from posted journals on the accounts mapped to those posting roles
 * (cash basis), for the same period and branch as `net_income` and in each
 * month; `cash_total`; and the books' state.
 */
class AccountingDashboardBooksTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return array<string, mixed> */
    private function dashboard(string $query = 'as_of=2026-09-30'): array
    {
        return $this->getJson('/api/accounting/dashboard?'.$query)->assertOk()->json('data');
    }

    private function postIncome(): void
    {
        $this->postSimpleJournal('1010', '4010', 250000, ['date' => '2026-09-10']);
        $this->postSimpleJournal('1010', '4020', 12000, ['date' => '2026-09-11']);
        $this->postSimpleJournal('1010', '4030', 30000, ['date' => '2026-09-12']);
        $this->postSimpleJournal('1010', '4040', 5000, ['date' => '2026-09-12']);
        $this->postSimpleJournal('1010', '4080', 1500, ['date' => '2026-09-12']);
        // August, outside September's period.
        $this->postSimpleJournal('1010', '4010', 70000, ['date' => '2026-08-20']);
        $this->postSimpleJournal('5030', '1010', 40000, ['date' => '2026-09-15']);
    }

    public function test_the_period_carries_interest_penalty_and_fee_income_from_the_mapped_accounts(): void
    {
        $this->postIncome();

        $period = $this->dashboard()['period'];

        $this->assertSame(250000, $period['interest_income']);
        $this->assertSame(12000, $period['penalty_income']);
        $this->assertSame(36500, $period['fee_income']);
        $this->assertSame('cash', $period['income_basis']);
        $this->assertSame('Interest income (cash basis)', $period['interest_income_label']);
        $this->assertSame('Fee income (loan and savings fees)', $period['fee_income_label']);
        $this->assertSame([], $this->dashboard()['income_roles_shared']);
        $this->assertSame(298500 - 40000, $period['net_income']);
    }

    public function test_each_figure_equals_the_income_statement_line_of_its_account(): void
    {
        $this->postIncome();
        $statement = app(IncomeStatementBuilder::class)->build('2026-08-01', '2026-09-30');
        $line = fn (string $code): int => (int) collect($statement['income'])->firstWhere('account_code', $code)['amount'];

        $period = $this->dashboard('as_of=2026-09-30&from=2026-08-01')['period'];

        $this->assertSame($line('4010'), $period['interest_income']);
        $this->assertSame($line('4020'), $period['penalty_income']);
        $this->assertSame($line('4030') + $line('4040') + $line('4080'), $period['fee_income']);
        $this->assertSame($statement['net_income'], $period['net_income']);
    }

    public function test_interest_income_equals_the_executive_dashboard_card(): void
    {
        $other = Branch::factory()->create();
        $this->postIncome();
        $this->postSimpleJournal('1010', '4010', 99900, ['date' => '2026-09-20', 'branch_id' => $other->id]);

        foreach (['', "&branch_id={$this->branch->id}", "&branch_id={$other->id}"] as $branch) {
            $period = $this->dashboard('as_of=2026-09-30&from=2026-09-01'.$branch)['period'];
            $card = $this->getJson('/api/dashboard/executive/overview?preset=custom&date_from=2026-09-01&date_to=2026-09-30'.$branch)
                ->assertOk()->json('data.cards.interest_income');

            $this->assertSame('ready', $card['state'], $branch);
            $this->assertEquals($period['interest_income'] / 100, $card['value'], $branch);
            $this->assertSame($period['interest_income_label'], $card['label'], $branch);
        }
    }

    public function test_each_month_carries_the_same_figures(): void
    {
        $this->postIncome();

        $months = collect($this->dashboard()['months'])->keyBy('month');

        $this->assertSame(70000, $months['2026-08']['interest_income']);
        $this->assertSame(0, $months['2026-08']['penalty_income']);
        $this->assertSame(250000, $months['2026-09']['interest_income']);
        $this->assertSame(12000, $months['2026-09']['penalty_income']);
        $this->assertSame(36500, $months['2026-09']['fee_income']);
        $this->assertSame(0, $months['2026-07']['fee_income']);
    }

    public function test_an_account_mapped_to_two_roles_counts_once(): void
    {
        DB::table('accounting_account_mappings')->where('role', 'penalty_income')
            ->update(['accounting_account_id' => $this->account('4010')]);
        $this->postIncome();

        $period = $this->dashboard()['period'];

        $this->assertSame(250000, $period['interest_income']);
        $this->assertSame(0, $period['penalty_income'], 'Its account is the interest account, already counted.');
        $this->assertSame(
            [['role' => 'penalty_income', 'figure' => 'penalty_income', 'counted_in' => 'interest_income', 'account_id' => $this->account('4010')]],
            $this->dashboard()['income_roles_shared'],
            'Said, not hidden.',
        );
    }

    public function test_the_figures_follow_the_branch_scope(): void
    {
        $other = Branch::factory()->create();
        $this->postIncome();
        $this->postSimpleJournal('1010', '4010', 99900, ['date' => '2026-09-20', 'branch_id' => $other->id]);

        $this->assertSame(250000 + 99900, $this->dashboard()['period']['interest_income']);
        $this->assertSame(250000, $this->dashboard("as_of=2026-09-30&branch_id={$this->branch->id}")['period']['interest_income']);
        $this->assertSame(99900, $this->dashboard("as_of=2026-09-30&branch_id={$other->id}")['period']['interest_income']);

        $role = Role::create(['name' => 'ebd2_branch_books', 'guard_name' => 'web']);
        $role->givePermissionTo(['accounting:view']);
        $user = User::factory()->inBranches($other)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertSame(99900, $this->dashboard()['period']['interest_income']);
        $this->assertSame(99900, collect($this->dashboard()['months'])->firstWhere('month', '2026-09')['interest_income']);
    }

    public function test_cash_total_is_every_money_account(): void
    {
        $this->postSimpleJournal('1010', '3010', 500000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1040', '3010', 2000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1020', '3010', 150000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1030', '3010', 50000, ['date' => '2026-09-01']);

        $this->assertNull($this->dashboard()['cash_total'], 'A position, so not before the opening balances are finalised.');

        AccountingOpeningBalance::query()->create(['as_of' => '2026-08-31', 'created_by' => $this->admin->id])
            ->forceFill(['status' => AccountingOpeningBalance::STATUS_FINALISED, 'finalised_at' => now()])->save();
        $dashboard = $this->dashboard();

        $this->assertSame(2700000, $dashboard['cash_total']);
        $this->assertSame($dashboard['cash_on_hand'] + $dashboard['cash_in_bank'] + $dashboard['e_wallets'], $dashboard['cash_total']);
        $this->assertSame(array_sum(array_column($dashboard['cash_accounts'], 'balance')), $dashboard['cash_total']);
    }

    public function test_the_books_require_configuration_until_the_opening_balances_are_finalised(): void
    {
        $books = $this->dashboard()['books_state'];
        $this->assertSame('requires_configuration', $books['state']);
        $this->assertSame('Requires configuration', $books['label']);
        $this->assertSame('The opening balances have not been finalised yet.', $books['reason']);

        $draft = AccountingOpeningBalance::query()->create(['as_of' => '2026-08-31', 'created_by' => $this->admin->id]);
        $this->assertSame('requires_configuration', $this->dashboard()['books_state']['state'], 'A draft is not finalised.');

        $draft->forceFill(['status' => AccountingOpeningBalance::STATUS_FINALISED, 'finalised_by' => $this->admin->id, 'finalised_at' => now()])->save();
        $this->assertSame(
            ['state' => 'ready', 'label' => 'Ready', 'reason' => null],
            $this->dashboard()['books_state'],
        );
    }

    public function test_an_opening_balance_posted_from_the_command_line_counts_as_finalised(): void
    {
        $this->postSimpleJournal('1010', '3050', 100000, ['date' => '2026-08-31', 'source' => 'opening_balance', 'branch_id' => null]);

        $this->assertSame('ready', $this->dashboard()['books_state']['state']);
    }

    public function test_without_a_chart_the_books_are_not_set_up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        AccountingAccountMapping::query()->delete();
        AccountingAccount::query()->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $books = $this->dashboard()['books_state'];

        $this->assertSame('books_not_set_up', $books['state']);
        $this->assertSame('Books not set up', $books['label']);
    }
}
