<?php

namespace Tests\Feature\Accounting\YearEnd;

use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The statements around a year-end close. The income statement leaves the
 * closing entries (and their reversals) out, so the closed year reads as it
 * did; the balance sheet shows the result in net surplus instead of the
 * derived earnings lines; changes in equity counts a closing entry once.
 */
class YearEndStatementsTest extends TestCase
{
    use PostsJournals, SetupLendyPH, YearEndFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYearEnd();
        $this->postTheYear();
    }

    public function test_the_income_statement_of_the_closed_year_equals_the_one_before_the_close(): void
    {
        $before = $this->incomeStatements();

        $this->closed();

        $this->assertSame($before, $this->incomeStatements());
        $this->assertSame(self::YEAR_NET, $before['year']['net_income']);
    }

    public function test_the_balance_sheet_at_31_december_balances_with_the_result_in_net_surplus(): void
    {
        $before = $this->sheet('2025-12-31');
        $this->assertSame(self::YEAR_NET, $before['current_year_earnings']);

        $this->closed();

        $sheet = $this->sheet('2025-12-31');
        $this->assertTrue($sheet['is_balanced']);
        $this->assertSame(0, $sheet['difference']);
        $this->assertSame(0, $sheet['current_year_earnings']);
        $this->assertSame(0, $sheet['prior_period_earnings']);
        $this->assertSame(['account'], array_values(array_unique(array_column($sheet['equity'], 'kind'))), 'no derived earnings line');
        $this->assertSame(self::YEAR_NET, collect($sheet['equity'])->firstWhere('account_code', '3030')['amount']);
        $this->assertSame($before['total_equity'], $sheet['total_equity']);
        $this->assertSame($before['total_assets'], $sheet['total_assets']);

        // The next year: nothing carried as unclosed prior earnings.
        $next = $this->sheet('2026-06-30');
        $this->assertTrue($next['is_balanced']);
        $this->assertSame(0, $next['prior_period_earnings']);
        $this->assertSame(77700, $next['current_year_earnings']);
        $this->assertSame(self::YEAR_NET, collect($next['equity'])->firstWhere('account_code', '3030')['amount']);
    }

    public function test_each_branchs_balance_sheet_balances_after_the_close(): void
    {
        $this->closed();

        foreach ([$this->branch->id, $this->far->id] as $branchId) {
            $sheet = $this->sheet('2025-12-31', $branchId);
            $this->assertTrue($sheet['is_balanced']);
            $this->assertSame(0, $sheet['current_year_earnings']);
        }
    }

    public function test_the_dashboards_period_profit_and_trend_are_unchanged(): void
    {
        $before = $this->dashboard();

        $this->closed();

        $after = $this->dashboard();
        $this->assertSame($before['period'], $after['period']);
        $this->assertSame(self::YEAR_NET, $after['period']['net_income']);
        $this->assertSame(
            array_map(fn (array $m) => array_diff_key($m, ['cash' => true]), $before['months']),
            array_map(fn (array $m) => array_diff_key($m, ['cash' => true]), $after['months']),
        );
        $this->assertSame($before['net_income_mtd'], $after['net_income_mtd']);
    }

    public function test_equity_changes_count_the_closing_entry_once(): void
    {
        $before = $this->equityChanges();

        $this->closed();

        $after = $this->equityChanges();
        $earnings = collect($after['rows'])->firstWhere('kind', 'earnings');
        $retained = collect($after['rows'])->firstWhere('account_code', '3030');

        $this->assertSame(self::YEAR_NET, $earnings['additions'], 'the year\'s net income');
        $this->assertSame(self::YEAR_NET, $earnings['deductions'], 'what the closing entries moved, once');
        $this->assertSame(0, $earnings['ending']);
        $this->assertSame(self::MAIN_NET + self::NO_BRANCH_NET, $retained['additions']);
        $this->assertSame(-self::FAR_NET, $retained['deductions']);
        $this->assertSame(self::YEAR_NET, $retained['ending']);

        $this->assertSame($before['total_beginning'], $after['total_beginning']);
        $this->assertSame($before['total_ending'], $after['total_ending']);
        $this->assertSame($this->sheet('2025-12-31')['total_equity'], $after['total_ending']);
    }

    public function test_a_reopened_close_leaves_the_year_as_if_it_had_never_been_closed(): void
    {
        $before = [
            'income' => $this->incomeStatements(),
            'sheet' => $this->sheet('2025-12-31'),
            'equity' => $this->equityChanges(),
            'dashboard' => $this->dashboard()['period'],
        ];

        $this->reopenYear($this->closed()['id'])->assertOk();

        $this->assertSame($before['income'], $this->incomeStatements());
        $this->assertSame($before['sheet'], $this->sheet('2025-12-31'));
        $this->assertSame($before['dashboard'], $this->dashboard()['period']);

        $equity = $this->equityChanges();
        $this->assertSame($before['equity']['total_beginning'], $equity['total_beginning']);
        $this->assertSame($before['equity']['total_ending'], $equity['total_ending']);
        $this->assertSame(
            collect($before['equity']['rows'])->firstWhere('kind', 'earnings'),
            collect($equity['rows'])->firstWhere('kind', 'earnings'),
        );
        $this->assertSame(0, collect($equity['rows'])->firstWhere('account_code', '3030')['ending']);
        $this->assertSame($this->sheet('2025-12-31')['total_equity'], $equity['total_ending']);
    }

    /** @return array<string, array<string, mixed>> */
    private function incomeStatements(): array
    {
        $is = fn (string $from, string $to, string $extra = ''): array => $this
            ->getJson("/api/accounting/statements/income-statement?from={$from}&to={$to}{$extra}")
            ->assertOk()
            ->json('data');

        return [
            'year' => $is('2025-01-01', '2025-12-31'),
            'december' => $is('2025-12-01', '2025-12-31'),
            'last_day' => $is('2025-12-31', '2025-12-31'),
            'across_years' => $is('2025-07-01', '2026-06-30'),
            'main' => $is('2025-01-01', '2025-12-31', "&branch_id={$this->branch->id}"),
            'far' => $is('2025-01-01', '2025-12-31', "&branch_id={$this->far->id}"),
        ];
    }

    /** @return array<string, mixed> */
    private function sheet(string $asOf, ?int $branchId = null): array
    {
        $branch = $branchId === null ? '' : "&branch_id={$branchId}";

        return $this->getJson("/api/accounting/statements/balance-sheet?as_of={$asOf}{$branch}")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function equityChanges(): array
    {
        return $this->getJson('/api/accounting/statements/equity-changes?from=2025-01-01&to=2025-12-31')->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->getJson('/api/accounting/dashboard?as_of=2025-12-31&from=2025-01-01')->assertOk()->json('data');
    }
}
