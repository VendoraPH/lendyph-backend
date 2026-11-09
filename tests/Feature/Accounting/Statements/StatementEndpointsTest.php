<?php

namespace Tests\Feature\Accounting\Statements;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\BalanceSheetBuilder;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * GET /accounting/statements/balance-sheet and /income-statement: the server's
 * statements, built from the same posted and reversed lines as the trial
 * balance, in integer centavos, on `accounting:view` and the accounting branch
 * rule.
 */
class StatementEndpointsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    /** Last year's trading, this year's trading, and a capital injection. */
    private function postTheYear(): void
    {
        $this->postSimpleJournal('1010', '3010', 50000000, ['date' => '2025-03-01']);
        $this->postSimpleJournal('1010', '4010', 30000000, ['date' => '2025-06-01']);
        $this->postSimpleJournal('5010', '1010', 7500000, ['date' => '2025-07-01']);

        $this->postSimpleJournal('1110', '1010', 20000000, ['date' => '2026-02-01']);
        $this->postSimpleJournal('1010', '4010', 9000000, ['date' => '2026-03-01']);
        $this->postSimpleJournal('1010', '4020', 1000050, ['date' => '2026-04-01']);
        $this->postSimpleJournal('5020', '1010', 1500000, ['date' => '2026-05-01']);
        $this->postSimpleJournal('5140', '1200', 400000, ['date' => '2026-05-15']);
        // After as_of: on neither statement.
        $this->postSimpleJournal('1010', '4010', 99900, ['date' => '2026-10-01']);
    }

    private function sheet(string $query = 'as_of=2026-09-30'): array
    {
        return $this->getJson("/api/accounting/statements/balance-sheet?{$query}")->assertOk()->json('data');
    }

    private function incomeStatement(string $from = '2026-01-01', string $to = '2026-09-30', string $extra = ''): array
    {
        return $this->getJson("/api/accounting/statements/income-statement?from={$from}&to={$to}{$extra}")->assertOk()->json('data');
    }

    public function test_the_balance_sheet_has_the_contract_shape_and_balances(): void
    {
        $this->postTheYear();

        $sheet = $this->sheet();

        $this->assertSame([
            'as_of', 'assets', 'liabilities', 'equity', 'total_assets', 'total_liabilities', 'total_equity',
            'total_liabilities_and_equity', 'current_year_earnings', 'prior_period_earnings', 'earnings_basis',
            'earnings_period', 'difference', 'is_balanced',
        ], array_keys($sheet));
        $this->assertSame('2026-09-30', $sheet['as_of']);
        $this->assertSame('period', $sheet['earnings_basis']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-09-30'], $sheet['earnings_period']);

        $this->assertSame(['account_id', 'account_code', 'account_name', 'amount', 'kind'], array_keys($sheet['assets'][0]));
        $this->assertSame(-400000, collect($sheet['assets'])->firstWhere('account_code', '1200')['amount'], 'a contra line is negative');

        // Cash 500,000 + 300,000 - 75,000 - 200,000 + 90,000 + 10,000.50 - 15,000 = 610,000.50.
        $this->assertSame(61000050 + 20000000 - 400000, $sheet['total_assets']);
        $this->assertSame(0, $sheet['total_liabilities']);
        $this->assertSame($sheet['total_assets'], $sheet['total_liabilities_and_equity']);
        $this->assertSame(0, $sheet['difference']);
        $this->assertTrue($sheet['is_balanced']);

        // Prior: 300,000 - 75,000. Current: 90,000 + 10,000.50 - 15,000 - 4,000.
        $this->assertSame(22500000, $sheet['prior_period_earnings']);
        $this->assertSame(8100050, $sheet['current_year_earnings']);
        $this->assertSame(
            ['account', 'prior_period_earnings', 'current_year_earnings'],
            array_column($sheet['equity'], 'kind'),
        );
        $derived = collect($sheet['equity'])->where('kind', '!=', 'account');
        $this->assertSame([null, null], $derived->pluck('account_id')->values()->all());
        $this->assertSame([null, null], $derived->pluck('account_code')->values()->all());
    }

    public function test_total_equity_is_the_shared_figure_of_the_trial_balance_rows(): void
    {
        $this->postTheYear();

        $rows = app(TrialBalanceBuilder::class)->build('2026-09-30')['rows'];

        $this->assertSame(BalanceSheetBuilder::totalEquity($rows), $this->sheet()['total_equity']);
        $this->assertSame(50000000 + 22500000 + 8100050, $this->sheet()['total_equity']);
    }

    public function test_current_year_earnings_equal_the_income_statements_net_income_for_the_year_to_date(): void
    {
        $this->postTheYear();

        $this->assertSame($this->incomeStatement()['net_income'], $this->sheet()['current_year_earnings']);
    }

    public function test_the_balance_sheet_defaults_to_today(): void
    {
        $sheet = $this->sheet('');

        $this->assertSame(now()->toDateString(), $sheet['as_of']);
        $this->assertSame(now()->format('Y').'-01-01', $sheet['earnings_period']['from']);
    }

    public function test_the_income_statement_has_the_contract_shape(): void
    {
        $this->postTheYear();

        $is = $this->incomeStatement();

        $this->assertSame(['period', 'income', 'expenses', 'total_income', 'total_expenses', 'net_income'], array_keys($is));
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-09-30'], $is['period']);
        $this->assertSame(['4010', '4020'], array_column($is['income'], 'account_code'));
        $this->assertSame(['5020', '5140'], array_column($is['expenses'], 'account_code'));
        $this->assertSame(['account_id', 'account_code', 'account_name', 'amount'], array_keys($is['income'][0]));
        $this->assertSame(10000050, $is['total_income']);
        $this->assertSame(1900000, $is['total_expenses']);
        $this->assertSame(8100050, $is['net_income']);
    }

    /** It equals the movement between two trial balances, the browser's old arithmetic. */
    public function test_the_income_statement_equals_the_difference_of_two_trial_balances(): void
    {
        $this->postTheYear();
        $this->postSimpleJournal('4010', '1010', 250000, ['date' => '2026-06-01']); // a refund debited to income

        $tb = app(TrialBalanceBuilder::class);
        $closing = collect($tb->build('2026-06-30')['rows'])->keyBy('account_id');
        $opening = collect($tb->build('2026-02-28')['rows'])->keyBy('account_id');

        $expected = [];
        foreach ($closing->keys()->merge($opening->keys())->unique() as $id) {
            $row = $closing->get($id) ?? $opening->get($id);
            if (! in_array($row['type'], ['income', 'expense'], true)) {
                continue;
            }
            $net = ($closing->get($id)['debit'] ?? 0) - ($closing->get($id)['credit'] ?? 0)
                - (($opening->get($id)['debit'] ?? 0) - ($opening->get($id)['credit'] ?? 0));
            $expected[$row['account_code']] = $row['type'] === 'expense' ? $net : -$net;
        }
        ksort($expected);

        $is = $this->incomeStatement('2026-03-01', '2026-06-30');
        $actual = collect([...$is['income'], ...$is['expenses']])->pluck('amount', 'account_code')->all();
        ksort($actual);

        $this->assertSame(array_filter($expected), $actual);
        $this->assertSame(9000000 - 250000, collect($is['income'])->firstWhere('account_code', '4010')['amount']);
    }

    public function test_a_loss_is_a_negative_net_income(): void
    {
        $this->postSimpleJournal('5010', '1010', 500000, ['date' => '2026-09-05']);

        $this->assertSame(-500000, $this->incomeStatement('2026-09-01', '2026-09-30')['net_income']);
    }

    public function test_a_reversed_entry_counts_with_its_reversal(): void
    {
        $journal = $this->postSimpleJournal('1010', '4010', 300000, ['date' => '2026-09-05']);
        app(JournalPoster::class)->reverse($journal, '2026-09-06', 'Wrong', $this->admin->id);

        $is = $this->incomeStatement('2026-09-01', '2026-09-30');

        $this->assertSame([], $is['income']);
        $this->assertSame(0, $is['net_income']);
        $this->assertSame(300000, $this->incomeStatement('2026-09-05', '2026-09-05')['total_income']);
    }

    public function test_drafts_are_on_neither_statement(): void
    {
        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 100000, 'credit' => 0],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 100000],
        ], ['date' => '2026-09-05']);

        $this->assertSame(0, $this->incomeStatement('2026-09-01', '2026-09-30')['total_income']);
        $this->assertSame(0, $this->sheet()['total_assets']);
    }

    public function test_the_income_statement_validates_its_range(): void
    {
        $this->getJson('/api/accounting/statements/income-statement')
            ->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/accounting/statements/income-statement?from=2026-09-30&to=2026-09-01')
            ->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/accounting/statements/income-statement?from=2026-09-01T00:00:00&to=2026-09-30')
            ->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/accounting/statements/balance-sheet?as_of=yesterday')
            ->assertStatus(422)->assertJsonValidationErrors('as_of');
    }

    public function test_both_need_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole());

        $this->getJson('/api/accounting/statements/balance-sheet?as_of=2026-09-30')->assertForbidden();
        $this->getJson('/api/accounting/statements/income-statement?from=2026-09-01&to=2026-09-30')->assertForbidden();
    }

    /** A branch user sees their branches only; another branch is 403; no-branch journals need view_all. */
    public function test_both_follow_the_accounting_branch_rule(): void
    {
        foreach ([[$this->branch->id, 10000], [$this->far->id, 20000], [null, 40000]] as [$branchId, $centavos]) {
            $this->postSimpleJournal('1010', '4010', $centavos, ['date' => '2026-09-05', 'branch_id' => $branchId]);
        }

        $sheet = '/api/accounting/statements/balance-sheet?as_of=2026-09-30';
        $is = '/api/accounting/statements/income-statement?from=2026-09-01&to=2026-09-30';

        $this->assertSame(70000, $this->getJson($sheet)->json('data.total_assets'));
        $this->assertSame(70000, $this->getJson($is)->json('data.net_income'));
        $adminMain = [
            $this->getJson("{$sheet}&branch_id={$this->branch->id}")->assertOk()->json('data'),
            $this->getJson("{$is}&branch_id={$this->branch->id}")->assertOk()->json('data'),
        ];
        $this->assertSame(10000, $adminMain[0]['total_assets']);

        $this->actingAs($this->branchUser());

        $this->assertSame($adminMain[0], $this->getJson($sheet)->assertOk()->json('data'));
        $this->assertSame($adminMain[1], $this->getJson($is)->assertOk()->json('data'));
        $this->getJson("{$sheet}&branch_id={$this->far->id}")->assertForbidden();
        $this->getJson("{$is}&branch_id={$this->far->id}")->assertForbidden();
    }

    private function branchUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'branch_statements_reader', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting:view']);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        return $user;
    }
}
