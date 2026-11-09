<?php

namespace Tests\Feature\Accounting\Statements;

use App\Services\Accounting\BalanceSheetBuilder;
use App\Services\Accounting\IncomeStatementBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The worked examples of the browser's `statements.test.ts`, ported with the
 * arithmetic they pin (`buildBalanceSheet`, `buildIncomeStatement`). Pure: the
 * rows are trial-balance rows in centavos, as TrialBalanceBuilder returns them.
 */
class StatementArithmeticTest extends TestCase
{
    /** @return array{account_id: int, account_code: string, account_name: string, type: string, debit: int, credit: int} */
    private static function row(string $code, string $name, string $type, int $debit, int $credit): array
    {
        return [
            'account_id' => (int) $code,
            'account_code' => $code,
            'account_name' => $name,
            'type' => $type,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }

    /** The worked example from the spec, in centavos. */
    private static function rows(): array
    {
        return [
            self::row('1010', 'Cash on Hand', 'asset', 52000000, 0),
            self::row('1040', 'Bank Accounts', 'asset', 125000000, 0),
            self::row('1110', 'Loans Receivable', 'asset', 485000000, 0),
            self::row('1200', 'Allowance for Credit Losses', 'asset', 0, 15000000),
            self::row('2010', 'Accounts Payable', 'liability', 0, 30000000),
            self::row('3010', 'Capital', 'equity', 0, 552000000),
            self::row('4010', 'Interest Income', 'income', 0, 85000000),
            self::row('4020', 'Penalty Income', 'income', 0, 5000000),
            self::row('5010', 'Salaries and Wages', 'expense', 20000000, 0),
            self::row('5020', 'Rent', 'expense', 5000000, 0),
        ];
    }

    /** A co-op trading since 2024 with books never closed: ₱3,000,000 lifetime, ₱750,000 this year. */
    private static function opening2026(): array
    {
        return [
            self::row('1010', 'Cash on Hand', 'asset', 500000000, 0),
            self::row('3010', 'Capital', 'equity', 0, 275000000),
            self::row('4010', 'Interest Income', 'income', 0, 300000000),
            self::row('5010', 'Salaries and Wages', 'expense', 75000000, 0),
        ];
    }

    private static function closing2026(): array
    {
        return [
            self::row('1010', 'Cash on Hand', 'asset', 575000000, 0),
            self::row('3010', 'Capital', 'equity', 0, 275000000),
            self::row('4010', 'Interest Income', 'income', 0, 390000000),
            self::row('5010', 'Salaries and Wages', 'expense', 90000000, 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function line(array $sheet, string $kind): ?array
    {
        foreach ($sheet['equity'] as $line) {
            if ($line['kind'] === $kind) {
                return $line;
            }
        }

        return null;
    }

    public function test_the_fixtures_balance(): void
    {
        foreach ([self::rows(), self::opening2026(), self::closing2026()] as $rows) {
            $this->assertSame(array_sum(array_column($rows, 'debit')), array_sum(array_column($rows, 'credit')));
        }
    }

    // ── Income statement ──

    public function test_it_totals_income_and_expenses_and_nets_them(): void
    {
        $is = IncomeStatementBuilder::fromRows(self::rows(), '2026-09-01', '2026-09-30');

        $this->assertSame(90000000, $is['total_income']);
        $this->assertSame(25000000, $is['total_expenses']);
        $this->assertSame(65000000, $is['net_income']);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $is['period']);
    }

    public function test_only_income_and_expense_accounts_reach_the_income_statement(): void
    {
        $is = IncomeStatementBuilder::fromRows(self::rows(), '2026-09-01', '2026-09-30');

        $this->assertSame(['4010', '4020'], array_column($is['income'], 'account_code'));
        $this->assertSame(['5010', '5020'], array_column($is['expenses'], 'account_code'));
        $this->assertSame(['account_id', 'account_code', 'account_name', 'amount'], array_keys($is['income'][0]));
    }

    public function test_a_loss_comes_out_negative_rather_than_flipped(): void
    {
        $is = IncomeStatementBuilder::fromRows([
            self::row('4010', 'Interest Income', 'income', 0, 1000),
            self::row('5010', 'Salaries', 'expense', 5000, 0),
        ], '2026-09-01', '2026-09-30');

        $this->assertSame(-4000, $is['net_income']);
    }

    public function test_income_lines_are_netted_in_their_normal_direction(): void
    {
        $is = IncomeStatementBuilder::fromRows([self::row('4010', 'Interest Income', 'income', 2000, 10000)], '2026-09-01', '2026-09-30');

        $this->assertSame(8000, $is['total_income']);
    }

    public function test_an_empty_book_nets_to_zero_and_zero_lines_are_dropped(): void
    {
        $this->assertSame(0, IncomeStatementBuilder::fromRows([], '2026-09-01', '2026-09-30')['net_income']);

        $is = IncomeStatementBuilder::fromRows([self::row('4010', 'Interest Income', 'income', 500, 500)], '2026-09-01', '2026-09-30');
        $this->assertSame([], $is['income']);
    }

    public function test_income_statement_lines_are_in_code_order(): void
    {
        $is = IncomeStatementBuilder::fromRows([
            self::row('5020', 'Rent', 'expense', 100, 0),
            self::row('4020', 'Penalty Income', 'income', 0, 100),
            self::row('5010', 'Salaries', 'expense', 100, 0),
            self::row('4010', 'Interest Income', 'income', 0, 100),
        ], '2026-09-01', '2026-09-30');

        $this->assertSame(['4010', '4020'], array_column($is['income'], 'account_code'));
        $this->assertSame(['5010', '5020'], array_column($is['expenses'], 'account_code'));
    }

    // ── Balance sheet ──

    public function test_assets_equal_liabilities_plus_equity_once_earnings_are_carried_in(): void
    {
        $bs = BalanceSheetBuilder::fromTrialBalances(self::rows(), [], '2026-09-30');

        $this->assertSame(647000000, $bs['total_assets']);
        $this->assertSame(30000000, $bs['total_liabilities']);
        $this->assertSame(65000000, $bs['current_year_earnings']);
        $this->assertSame(617000000, $bs['total_equity']);
        $this->assertSame(647000000, $bs['total_liabilities_and_equity']);
        $this->assertSame(0, $bs['difference']);
        $this->assertTrue($bs['is_balanced']);
    }

    public function test_a_contra_asset_subtracts_from_assets(): void
    {
        $bs = BalanceSheetBuilder::fromTrialBalances(self::rows(), [], '2026-09-30');
        $allowance = collect($bs['assets'])->firstWhere('account_code', '1200');

        $this->assertSame(-15000000, $allowance['amount']);
        $this->assertSame('account', $allowance['kind']);
        $this->assertSame(1200, $allowance['account_id']);
    }

    public function test_a_loss_reduces_equity(): void
    {
        $bs = BalanceSheetBuilder::fromTrialBalances([
            self::row('1010', 'Cash', 'asset', 6000, 0),
            self::row('3010', 'Capital', 'equity', 0, 10000),
            self::row('5010', 'Salaries', 'expense', 4000, 0),
        ], [], '2026-09-30');

        $this->assertSame(-4000, $bs['current_year_earnings']);
        $this->assertSame(6000, $bs['total_equity']);
        $this->assertTrue($bs['is_balanced']);
    }

    public function test_income_and_expense_accounts_are_not_balance_sheet_lines(): void
    {
        $bs = BalanceSheetBuilder::fromTrialBalances(self::rows(), [], '2026-09-30');
        $codes = array_column([...$bs['assets'], ...$bs['liabilities'], ...$bs['equity']], 'account_code');

        $this->assertNotContains('4010', $codes);
        $this->assertNotContains('5010', $codes);
    }

    public function test_the_earnings_line_is_derived_with_no_account_of_its_own(): void
    {
        $bs = BalanceSheetBuilder::fromTrialBalances(self::rows(), [], '2026-09-30');
        $line = self::line($bs, 'current_year_earnings');

        $this->assertSame([
            'account_id' => null,
            'account_code' => null,
            'account_name' => 'Current Year Earnings',
            'amount' => 65000000,
            'kind' => 'current_year_earnings',
        ], $line);
        $this->assertNotContains('3040', array_column($bs['equity'], 'account_code'));
        $this->assertNotContains('3045', array_column($bs['equity'], 'account_code'));
    }

    public function test_books_that_do_not_balance_are_reported_not_corrected(): void
    {
        $bs = BalanceSheetBuilder::fromTrialBalances([
            self::row('1010', 'Cash', 'asset', 10000, 0),
            self::row('3010', 'Capital', 'equity', 0, 9000),
        ], [], '2026-09-30');

        $this->assertFalse($bs['is_balanced']);
        $this->assertSame(1000, $bs['difference']);
    }

    public function test_an_empty_book_balances_at_zero(): void
    {
        $bs = BalanceSheetBuilder::fromTrialBalances([], [], '2026-09-30');

        $this->assertSame(0, $bs['total_assets']);
        $this->assertSame([], $bs['equity']);
        $this->assertTrue($bs['is_balanced']);
    }

    public function test_given_the_opening_balance_the_sheet_reports_the_year_not_the_lifetime(): void
    {
        $sheet = BalanceSheetBuilder::fromTrialBalances(self::closing2026(), self::opening2026(), '2026-09-15');
        $is = IncomeStatementBuilder::fromRows(self::subtract(self::closing2026(), self::opening2026()), '2026-01-01', '2026-09-15');

        $this->assertSame(75000000, $sheet['current_year_earnings']);
        $this->assertSame($is['net_income'], $sheet['current_year_earnings']);
        $this->assertSame(225000000, $sheet['prior_period_earnings']);
        $this->assertSame(300000000, $sheet['prior_period_earnings'] + $sheet['current_year_earnings']);
        $this->assertSame(0, $sheet['difference']);
        $this->assertSame('period', $sheet['earnings_basis']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-09-15'], $sheet['earnings_period']);
    }

    public function test_the_two_earnings_lines_are_separately_visible_and_named(): void
    {
        $sheet = BalanceSheetBuilder::fromTrialBalances(self::closing2026(), self::opening2026(), '2026-09-15');

        $this->assertSame('Current Year Earnings', self::line($sheet, 'current_year_earnings')['account_name']);
        $this->assertSame(75000000, self::line($sheet, 'current_year_earnings')['amount']);
        $this->assertSame('Prior Period Earnings (not yet closed)', self::line($sheet, 'prior_period_earnings')['account_name']);
        $this->assertSame(225000000, self::line($sheet, 'prior_period_earnings')['amount']);
        $this->assertSame(['account', 'prior_period_earnings', 'current_year_earnings'], array_column($sheet['equity'], 'kind'));
    }

    public function test_a_first_year_of_trading_has_no_prior_earnings_line(): void
    {
        $sheet = BalanceSheetBuilder::fromTrialBalances(self::closing2026(), [self::row('3010', 'Capital', 'equity', 0, 275000000)], '2026-09-15');

        $this->assertSame(0, $sheet['prior_period_earnings']);
        $this->assertNull(self::line($sheet, 'prior_period_earnings'));
        $this->assertSame(300000000, $sheet['current_year_earnings']);
    }

    public function test_total_equity_is_posted_equity_plus_income_less_expense_of_the_same_rows(): void
    {
        $this->assertSame(617000000, BalanceSheetBuilder::totalEquity(self::rows()));
        $this->assertSame(
            BalanceSheetBuilder::fromTrialBalances(self::closing2026(), self::opening2026(), '2026-09-15')['total_equity'],
            BalanceSheetBuilder::totalEquity(self::closing2026()),
        );
        $this->assertSame(0, BalanceSheetBuilder::totalEquity([]));
    }

    /** `subtractTrialBalances`, for the agreement check. */
    private static function subtract(array $closing, array $opening): array
    {
        $before = collect($opening)->keyBy('account_id');
        $rows = [];

        foreach ($closing as $row) {
            $was = $before->pull($row['account_id']);
            $net = $row['debit'] - $row['credit'] - ($was === null ? 0 : $was['debit'] - $was['credit']);
            $rows[] = array_merge($row, ['debit' => max($net, 0), 'credit' => max(-$net, 0)]);
        }

        foreach ($before as $row) {
            $net = -($row['debit'] - $row['credit']);
            $rows[] = array_merge($row, ['debit' => max($net, 0), 'credit' => max(-$net, 0)]);
        }

        return $rows;
    }
}
