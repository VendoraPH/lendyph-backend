<?php

namespace App\Services\Cda\Sources;

use App\Services\Accounting\BalanceSheetBuilder;
use App\Services\Accounting\CashFlowStatementBuilder;
use App\Services\Accounting\EquityChangesBuilder;
use App\Services\Accounting\IncomeStatementBuilder;
use App\Services\Cda\Reports\Rational;

/**
 * The financial statements for CDA reports (design §2), through the
 * existing builders, whole cooperative (no branch filter), so every figure
 * equals the Accounting screens' figure for the same date or range:
 *
 * - BalanceSheetBuilder: `fs.balance_sheet` (its lines), `fs.total_assets`,
 *   `fs.total_liabilities`, `fs.total_equity`, `fs.is_balanced`;
 * - IncomeStatementBuilder: `fs.income_statement`, `fs.total_income`,
 *   `fs.total_expenses`, `fs.net_surplus` (net income);
 * - CashFlowStatementBuilder and EquityChangesBuilder: their lines.
 *
 * Financial statements use closed years: until the period's year is closed
 * the figures are provisional (`meta.year_closed: false`).
 */
final class FsSource implements CdaSource
{
    public function __construct(
        private readonly BalanceSheetBuilder $balanceSheet,
        private readonly IncomeStatementBuilder $incomeStatement,
        private readonly CashFlowStatementBuilder $cashFlow,
        private readonly EquityChangesBuilder $equityChanges,
    ) {}

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        $closed = AccountingSource::yearClosed($context->year());

        return match ($key) {
            'fs.balance_sheet', 'fs.total_assets', 'fs.total_liabilities', 'fs.total_equity', 'fs.is_balanced' => $this->fromBalanceSheet($key, $context->date($params['as_of']), $context, $closed),
            'fs.income_statement', 'fs.total_income', 'fs.total_expenses', 'fs.net_surplus' => $this->fromIncomeStatement($key, $context->date($params['from']), $context->date($params['to']), $context, $closed),
            'fs.cash_flow' => $this->cashFlow($context->date($params['from']), $context->date($params['to']), $closed),
            'fs.equity_changes' => $this->equityChanges($context->date($params['from']), $context->date($params['to']), $closed),
            default => throw SourceUnavailable::unknownKey($key, 'fs'),
        };
    }

    private function fromBalanceSheet(string $key, string $asOf, SourceContext $context, bool $closed): SourceResult
    {
        $sheet = $context->remember("fs.sheet|{$asOf}", fn () => new SourceResult($this->balanceSheet->build($asOf), '', 'any'))->value;

        [$value, $what, $unit] = match ($key) {
            'fs.balance_sheet' => [$this->sheetRows($sheet), 'Balance sheet', 'table'],
            'fs.total_assets' => [self::money($sheet['total_assets']), 'Balance sheet, total assets', 'money'],
            'fs.total_liabilities' => [self::money($sheet['total_liabilities']), 'Balance sheet, total liabilities', 'money'],
            'fs.total_equity' => [self::money($sheet['total_equity']), 'Balance sheet, total equity', 'money'],
            'fs.is_balanced' => [(bool) $sheet['is_balanced'], 'Balance sheet balances', 'boolean'],
        };

        return $this->result($value, $what, $unit, $asOf, $closed);
    }

    private function fromIncomeStatement(string $key, string $from, string $to, SourceContext $context, bool $closed): SourceResult
    {
        $statement = $context->remember("fs.income|{$from}|{$to}", fn () => new SourceResult($this->incomeStatement->build($from, $to), '', 'any'))->value;

        [$value, $what, $unit] = match ($key) {
            'fs.income_statement' => [$this->incomeRows($statement), 'Income statement', 'table'],
            'fs.total_income' => [self::money($statement['total_income']), 'Income statement, total income', 'money'],
            'fs.total_expenses' => [self::money($statement['total_expenses']), 'Income statement, total expenses', 'money'],
            'fs.net_surplus' => [self::money($statement['net_income']), 'Income statement, net surplus', 'money'],
        };

        return $this->result($value, $what, $unit, $to, $closed);
    }

    private function cashFlow(string $from, string $to, bool $closed): SourceResult
    {
        $statement = $this->cashFlow->build($from, $to);
        $rows = [];

        foreach (['operating', 'investing', 'financing'] as $activity) {
            foreach ($statement[$activity]['lines'] ?? [] as $line) {
                $rows[] = ['section' => $statement[$activity]['label'], 'label' => (string) $line['label'], 'amount' => self::money((int) $line['amount'])];
            }

            $rows[] = ['section' => $statement[$activity]['label'], 'label' => 'Total', 'amount' => self::money((int) $statement[$activity]['total'])];
        }

        $rows[] = ['section' => 'Cash', 'label' => 'Opening cash', 'amount' => self::money((int) $statement['opening_cash'])];
        $rows[] = ['section' => 'Cash', 'label' => 'Net change', 'amount' => self::money((int) $statement['net_change'])];
        $rows[] = ['section' => 'Cash', 'label' => 'Closing cash', 'amount' => self::money((int) $statement['closing_cash'])];

        return $this->result($rows, 'Cash flow statement', 'table', $to, $closed);
    }

    private function equityChanges(string $from, string $to, bool $closed): SourceResult
    {
        $statement = $this->equityChanges->build($from, $to);
        $rows = array_map(fn (array $row) => [
            'label' => (string) $row['label'],
            'account_code' => $row['account_code'],
            'beginning' => self::money((int) $row['beginning']),
            'additions' => self::money((int) $row['additions']),
            'deductions' => self::money((int) $row['deductions']),
            'ending' => self::money((int) $row['ending']),
        ], $statement['rows']);

        return $this->result($rows, 'Changes in equity', 'table', $to, $closed);
    }

    /** @return list<array<string, mixed>> */
    private function sheetRows(array $sheet): array
    {
        $rows = [];

        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $section => $title) {
            foreach ($sheet[$section] as $line) {
                $rows[] = ['section' => $title, 'account_code' => $line['account_code'], 'account_name' => (string) $line['account_name'], 'amount' => self::money((int) $line['amount'])];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function incomeRows(array $statement): array
    {
        $rows = [];

        foreach (['income' => 'Income', 'expenses' => 'Expenses'] as $section => $title) {
            foreach ($statement[$section] as $line) {
                $rows[] = ['section' => $title, 'account_code' => $line['account_code'], 'account_name' => (string) $line['account_name'], 'amount' => self::money((int) $line['amount'])];
            }
        }

        return $rows;
    }

    private function result(mixed $value, string $what, string $unit, string $asOf, bool $closed): SourceResult
    {
        return new SourceResult($value, SourceResult::labelFor("Accounting › {$what} (".AccountingSource::basis($closed).')', $asOf), $unit, $asOf, ! $closed, ['year_closed' => $closed]);
    }

    private static function money(int $centavos): string
    {
        return Rational::fromCentavos($centavos)->toFixed(2);
    }
}
