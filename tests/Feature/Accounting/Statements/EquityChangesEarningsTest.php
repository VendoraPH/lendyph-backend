<?php

namespace Tests\Feature\Accounting\Statements;

use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * One equity figure: the statement of changes in equity carries an "Earnings
 * not yet closed" row, so its closing total is the balance sheet's total
 * equity on the same date, whether or not the books were closed.
 */
class EquityChangesEarningsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function changes(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return $this->getJson("/api/accounting/statements/equity-changes?from={$from}&to={$to}")->assertOk()->json('data');
    }

    private function totalEquity(string $asOf = '2026-09-30'): int
    {
        return $this->getJson("/api/accounting/statements/balance-sheet?as_of={$asOf}")->assertOk()->json('data.total_equity');
    }

    private function earningsRow(array $report): ?array
    {
        return collect($report['rows'])->firstWhere('kind', 'earnings');
    }

    private function postTrading(): void
    {
        $this->postSimpleJournal('1010', '3010', 50000000, ['date' => '2026-08-01']);
        $this->postSimpleJournal('1010', '4010', 2000000, ['date' => '2026-08-15']);
        $this->postSimpleJournal('5010', '1010', 500000, ['date' => '2026-08-20']);
        $this->postSimpleJournal('1010', '4010', 3000050, ['date' => '2026-09-10']);
        $this->postSimpleJournal('5020', '1010', 1000000, ['date' => '2026-09-20']);
    }

    public function test_the_earnings_row_carries_income_less_expense_with_no_account(): void
    {
        $this->postTrading();

        $report = $this->changes();

        $this->assertSame([
            'label' => 'Earnings not yet closed',
            'account_id' => null,
            'account_code' => null,
            'kind' => 'earnings',
            'beginning' => 1500000,
            'additions' => 2000050,
            'deductions' => 0,
            'ending' => 3500050,
        ], $this->earningsRow($report));
        $this->assertSame('account', $report['rows'][0]['kind']);
        $this->assertSame(50000000 + 1500000, $report['total_beginning']);
        $this->assertSame(50000000 + 3500050, $report['total_ending']);
    }

    public function test_the_closing_total_equals_the_balance_sheets_total_equity(): void
    {
        $this->postTrading();

        $this->assertSame($this->totalEquity(), $this->changes()['total_ending']);
        $this->assertSame($this->totalEquity('2026-08-31'), $this->changes()['total_beginning']);
    }

    public function test_a_loss_for_the_period_is_a_deduction(): void
    {
        $this->postSimpleJournal('1010', '3010', 1000000, ['date' => '2026-08-01']);
        $this->postSimpleJournal('5010', '1010', 400000, ['date' => '2026-09-05']);

        $row = $this->earningsRow($this->changes());

        $this->assertSame(0, $row['beginning']);
        $this->assertSame(0, $row['additions']);
        $this->assertSame(400000, $row['deductions']);
        $this->assertSame(-400000, $row['ending']);
    }

    /** A closing entry moves income into equity in the same period: no peso counts twice. */
    public function test_a_hand_posted_closing_entry_is_never_counted_twice(): void
    {
        $this->postTrading();
        // Close the year to date: income 5,000,050, expenses 1,500,000.
        $this->postJournal([
            ['account_id' => $this->account('4010'), 'debit' => 5000050, 'credit' => 0],
            ['account_id' => $this->account('5010'), 'debit' => 0, 'credit' => 500000],
            ['account_id' => $this->account('5020'), 'debit' => 0, 'credit' => 1000000],
            ['account_id' => $this->account('3030'), 'debit' => 0, 'credit' => 3500050],
        ], ['date' => '2026-09-30', 'source' => 'manual']);

        $report = $this->changes();
        $earnings = $this->earningsRow($report);
        $retained = collect($report['rows'])->firstWhere('account_code', '3030');

        $this->assertSame(3500050, $retained['additions']);
        $this->assertSame(1500000, $earnings['beginning']);
        // September's net income in, the year's result closed out.
        $this->assertSame(2000050, $earnings['additions']);
        $this->assertSame(3500050, $earnings['deductions']);
        $this->assertSame(0, $earnings['ending']);
        $this->assertSame(50000000 + 3500050, $report['total_ending']);
        $this->assertSame($this->totalEquity(), $report['total_ending']);
    }

    public function test_a_closed_loss_reads_as_an_addition_back_to_earnings(): void
    {
        $this->postSimpleJournal('1010', '3010', 1000000, ['date' => '2026-08-01']);
        $this->postSimpleJournal('5010', '1010', 400000, ['date' => '2026-09-05']);
        $this->postSimpleJournal('3030', '5010', 400000, ['date' => '2026-09-30']);

        $report = $this->changes();
        $earnings = $this->earningsRow($report);

        $this->assertSame(0, $earnings['beginning']);
        $this->assertSame(400000, $earnings['additions'], 'the closed loss');
        $this->assertSame(400000, $earnings['deductions'], 'the loss for the period');
        $this->assertSame(0, $earnings['ending']);
        $this->assertSame(400000, collect($report['rows'])->firstWhere('account_code', '3030')['deductions']);
        $this->assertSame($this->totalEquity(), $report['total_ending']);
    }

    public function test_an_ordinary_posting_is_never_read_as_a_closing_entry(): void
    {
        // Share capital and income in one entry, with cash: not a closing.
        $this->postJournal([
            ['account_id' => $this->account('1010'), 'debit' => 300000, 'credit' => 0],
            ['account_id' => $this->account('3060'), 'debit' => 0, 'credit' => 100000],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 200000],
        ], ['date' => '2026-09-10']);

        $earnings = $this->earningsRow($this->changes());

        $this->assertSame(200000, $earnings['additions']);
        $this->assertSame(0, $earnings['deductions']);
    }

    public function test_with_no_income_or_expense_there_is_no_earnings_row(): void
    {
        $this->postSimpleJournal('1010', '3010', 1000000, ['date' => '2026-09-05']);

        $report = $this->changes();

        $this->assertNull($this->earningsRow($report));
        $this->assertSame($this->totalEquity(), $report['total_ending']);
    }

    public function test_every_account_row_says_it_is_an_account(): void
    {
        $this->postTrading();

        $kinds = collect($this->changes()['rows'])->where('account_id', '!=', null)->pluck('kind')->unique()->values()->all();

        $this->assertSame(['account'], $kinds);
    }
}
