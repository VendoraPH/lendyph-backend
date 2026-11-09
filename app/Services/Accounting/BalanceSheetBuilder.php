<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;

/**
 * What the organisation owns, owes and is worth on a date, in integer
 * centavos: the server port of the browser's old `buildBalanceSheet`
 * (`src/lib/accounting/statements.ts`), which this replaces.
 *
 * ## Built from the trial balance, twice
 *
 * The closing trial balance at `as_of`, and the opening one at the close of
 * the day before 1 January of as_of's year (calendar fiscal year, Phase 2
 * decision 11). Both come from TrialBalanceBuilder, so every figure here
 * descends from the same posted and reversed lines as the trial balance.
 *
 * ## The two derived earnings lines
 *
 * Income and expense accounts are not balance sheet lines, but their net
 * result has to be, or assets never equal liabilities plus equity. A trial
 * balance is cumulative, so the result splits in two:
 *
 * - prior period earnings: the net income of the opening rows, earned before
 *   this year and not yet closed into equity;
 * - current year earnings: the cumulative net income less the prior figure,
 *   which is the income statement's net income for 1 January to as_of.
 *
 * Each is its own equity line with `account_id` and `account_code` null and
 * its own `kind`, never a made-up code: a real account could carry any code,
 * and two lines with one code can't be told apart. A line of 0 is left out.
 * The split is exhaustive, so total equity is posted equity plus the
 * cumulative net income: {@see self::totalEquity()}, the one equity figure
 * the dashboard reads as well.
 *
 * A closing entry moves income and expense into equity, so once the books are
 * closed the derived lines shrink by exactly what the closing entry posted.
 * Nothing is counted twice.
 */
final class BalanceSheetBuilder
{
    public const PRIOR_PERIOD_EARNINGS = 'Prior Period Earnings (not yet closed)';

    public const CURRENT_YEAR_EARNINGS = 'Current Year Earnings';

    public function __construct(private TrialBalanceBuilder $trialBalance) {}

    /**
     * @param  int|list<int>|null  $branchId
     * @return array<string, mixed> `BalanceSheet` in src/types/accounting.ts
     */
    public function build(string $asOf, int|array|null $branchId = null): array
    {
        $openingDate = CarbonImmutable::parse(self::yearStart($asOf))->subDay()->toDateString();

        return self::fromTrialBalances(
            $this->trialBalance->build($asOf, $branchId)['rows'],
            $this->trialBalance->build($openingDate, $branchId)['rows'],
            $asOf,
        );
    }

    /**
     * The sheet from a closing and an opening set of trial balance rows. Pure.
     *
     * @param  list<array{account_id: int, account_code: string, account_name: string, type: string, debit: int, credit: int}>  $closing
     * @param  list<array{account_id: int, account_code: string, account_name: string, type: string, debit: int, credit: int}>  $opening
     * @return array<string, mixed>
     */
    public static function fromTrialBalances(array $closing, array $opening, string $asOf): array
    {
        $assets = self::linesOf($closing, 'asset');
        $liabilities = self::linesOf($closing, 'liability');
        $equity = self::linesOf($closing, 'equity');

        $priorPeriodEarnings = self::netIncomeOf($opening);
        $currentYearEarnings = self::netIncomeOf($closing) - $priorPeriodEarnings;

        foreach ([
            'prior_period_earnings' => [self::PRIOR_PERIOD_EARNINGS, $priorPeriodEarnings],
            'current_year_earnings' => [self::CURRENT_YEAR_EARNINGS, $currentYearEarnings],
        ] as $kind => [$name, $amount]) {
            if ($amount === 0) {
                continue;
            }

            $equity[] = [
                'account_id' => null,
                'account_code' => null,
                'account_name' => $name,
                'amount' => $amount,
                'kind' => $kind,
            ];
        }

        $totalAssets = Money::sum(array_column($assets, 'amount'));
        $totalLiabilities = Money::sum(array_column($liabilities, 'amount'));
        $totalEquity = Money::sum(array_column($equity, 'amount'));
        $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;
        $difference = $totalAssets - $totalLiabilitiesAndEquity;

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'current_year_earnings' => $currentYearEarnings,
            'prior_period_earnings' => $priorPeriodEarnings,
            'earnings_basis' => 'period',
            'earnings_period' => ['from' => self::yearStart($asOf), 'to' => $asOf],
            'difference' => $difference,
            'is_balanced' => $difference === 0,
        ];
    }

    /**
     * Total equity of a set of trial balance rows: posted equity plus income
     * minus expense. The balance sheet's `total_equity` is this figure, and so
     * is the dashboard's.
     *
     * @param  list<array{type: string, debit: int, credit: int}>  $trialBalanceRows
     */
    public static function totalEquity(array $trialBalanceRows): int
    {
        $postedEquity = 0;

        foreach ($trialBalanceRows as $row) {
            if ($row['type'] === 'equity') {
                $postedEquity += self::netFor($row);
            }
        }

        return $postedEquity + self::netIncomeOf($trialBalanceRows);
    }

    /**
     * A row netted into the direction its section reads: debit minus credit
     * for assets and expenses, credit minus debit for liabilities, equity and
     * income. So a contra account (1200, 1210, 1490) comes out negative and
     * subtracts from its section.
     *
     * @param  array{type: string, debit: int, credit: int}  $row
     */
    public static function netFor(array $row): int
    {
        $debitNatured = $row['type'] === 'asset' || $row['type'] === 'expense';

        return $debitNatured
            ? (int) $row['debit'] - (int) $row['credit']
            : (int) $row['credit'] - (int) $row['debit'];
    }

    /**
     * One section's account lines, in the rows' (code) order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{account_id: int, account_code: string, account_name: string, amount: int, kind: string}>
     */
    private static function linesOf(array $rows, string $type): array
    {
        $lines = [];

        foreach ($rows as $row) {
            if ($row['type'] !== $type) {
                continue;
            }

            $lines[] = [
                'account_id' => (int) $row['account_id'],
                'account_code' => (string) $row['account_code'],
                'account_name' => (string) $row['account_name'],
                'amount' => self::netFor($row),
                'kind' => 'account',
            ];
        }

        return $lines;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function netIncomeOf(array $rows): int
    {
        $income = 0;
        $expenses = 0;

        foreach ($rows as $row) {
            if ($row['type'] === 'income') {
                $income += self::netFor($row);
            } elseif ($row['type'] === 'expense') {
                $expenses += self::netFor($row);
            }
        }

        return $income - $expenses;
    }

    private static function yearStart(string $date): string
    {
        return substr($date, 0, 4).'-01-01';
    }
}
