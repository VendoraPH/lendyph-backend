<?php

namespace Tests\Unit;

use App\Services\Accounting\GeneralLedgerBuilder;
use App\Services\Accounting\TrialBalanceBuilder;
use Tests\TestCase;

/**
 * The port of `src/lib/accounting/trial-balance.test.ts`.
 *
 * The placement rules are the part worth testing without a database: netting,
 * which column an account lands in, and dropping the accounts that did not
 * move. All three are pure arithmetic over pre-totalled accounts, and all three
 * are wrong in ways that still BALANCE — which is exactly why the assertions
 * below check where a figure went, not just that the columns add up.
 *
 * The frontend file is the other half of the same contract: both must produce
 * identical rows from identical input, because the balance sheet is a
 * regrouping of whichever one the screen happened to use.
 */
class AccountingTrialBalanceBuilderTest extends TestCase
{
    /**
     * @return array{account_id:int, account_code:string, account_name:string, type:string, normal_balance:string, is_group:bool, debit:int, credit:int}
     */
    private function balance(
        int $id,
        string $code,
        string $name,
        string $type,
        int $debit,
        int $credit,
        bool $isGroup = false,
    ): array {
        return [
            'account_id' => $id,
            'account_code' => $code,
            'account_name' => $name,
            'type' => $type,
            'normal_balance' => ($type === 'asset' || $type === 'expense') ? 'debit' : 'credit',
            'is_group' => $isGroup,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }

    private function cash(int $debit, int $credit): array
    {
        return $this->balance(1, '1010', 'Cash on Hand', 'asset', $debit, $credit);
    }

    private function loans(int $debit, int $credit): array
    {
        return $this->balance(2, '1110', 'Loans Receivable', 'asset', $debit, $credit);
    }

    private function payable(int $debit, int $credit): array
    {
        return $this->balance(3, '2010', 'Accounts Payable', 'liability', $debit, $credit);
    }

    private function capital(int $debit, int $credit): array
    {
        return $this->balance(4, '3010', 'Capital', 'equity', $debit, $credit);
    }

    private function interest(int $debit, int $credit): array
    {
        return $this->balance(5, '4010', 'Interest Income', 'income', $debit, $credit);
    }

    private function rent(int $debit, int $credit): array
    {
        return $this->balance(6, '5020', 'Rent', 'expense', $debit, $credit);
    }

    // ── buildTrialBalance ──

    public function test_a_balanced_set_of_books_reports_balanced(): void
    {
        $tb = TrialBalanceBuilder::fromBalances([
            $this->cash(100000000, 0),
            $this->loans(500000000, 0),
            $this->payable(0, 50000000),
            $this->capital(0, 500000000),
            $this->interest(0, 80000000),
            $this->rent(30000000, 0),
        ], '2026-09-30');

        $this->assertSame(630000000, $tb['total_debit']);
        $this->assertSame(630000000, $tb['total_credit']);
        $this->assertSame(0, $tb['difference']);
        $this->assertTrue($tb['is_balanced']);
        $this->assertSame('2026-09-30', $tb['as_of']);
    }

    public function test_each_account_is_netted_before_it_is_placed(): void
    {
        // Cash debited 500 and credited 200 shows as ONE 300 debit, not both.
        $tb = TrialBalanceBuilder::fromBalances([
            $this->cash(50000, 20000),
            $this->capital(0, 30000),
        ], '2026-09-30');

        $this->assertSame(30000, $tb['rows'][0]['debit']);
        $this->assertSame(0, $tb['rows'][0]['credit']);
    }

    public function test_an_account_that_swings_against_its_normal_side_lands_in_the_other_column(): void
    {
        // An overdrawn cash account is credit-balanced. Hiding that as a
        // negative debit would make the column totals lie.
        $tb = TrialBalanceBuilder::fromBalances([
            $this->cash(10000, 40000),
            $this->capital(30000, 0),
        ], '2026-09-30');

        $cash = $tb['rows'][0];

        $this->assertSame(0, $cash['debit']);
        $this->assertSame(30000, $cash['credit']);
        $this->assertTrue($tb['is_balanced']);
    }

    public function test_an_unbalanced_set_reports_the_exact_difference(): void
    {
        $tb = TrialBalanceBuilder::fromBalances([
            $this->cash(100000, 0),
            $this->capital(0, 95000),
        ], '2026-09-30');

        $this->assertSame(5000, $tb['difference']);
        $this->assertFalse($tb['is_balanced']);
    }

    public function test_accounts_with_no_movement_are_left_off(): void
    {
        $tb = TrialBalanceBuilder::fromBalances([
            $this->cash(100000, 0),
            $this->loans(0, 0),
            $this->capital(0, 100000),
        ], '2026-09-30');

        $this->assertCount(2, $tb['rows']);
        $this->assertSame(['1010', '3010'], array_column($tb['rows'], 'account_code'));
    }

    public function test_an_account_whose_debits_and_credits_cancel_exactly_is_left_off(): void
    {
        $tb = TrialBalanceBuilder::fromBalances([
            $this->cash(50000, 50000),
            $this->loans(100000, 0),
            $this->capital(0, 100000),
        ], '2026-09-30');

        $this->assertSame(['1110', '3010'], array_column($tb['rows'], 'account_code'));
    }

    public function test_group_headers_are_excluded_so_their_children_are_not_counted_twice(): void
    {
        $header = $this->balance(9, '1000', 'Assets', 'asset', 100000, 0, isGroup: true);

        $tb = TrialBalanceBuilder::fromBalances([
            $header,
            $this->cash(100000, 0),
            $this->capital(0, 100000),
        ], '2026-09-30');

        $this->assertCount(2, $tb['rows']);
        $this->assertSame(100000, $tb['total_debit']);
        $this->assertTrue($tb['is_balanced']);
    }

    public function test_rows_come_out_in_account_code_order(): void
    {
        $tb = TrialBalanceBuilder::fromBalances([
            $this->rent(1000, 0),
            $this->cash(1000, 0),
            $this->interest(0, 2000),
        ], '2026-09-30');

        $this->assertSame(['1010', '4010', '5020'], array_column($tb['rows'], 'account_code'));
    }

    public function test_an_empty_book_is_balanced_at_zero_rather_than_broken(): void
    {
        $tb = TrialBalanceBuilder::fromBalances([], '2026-09-30');

        $this->assertSame([], $tb['rows']);
        $this->assertTrue($tb['is_balanced']);
        $this->assertSame(0, $tb['total_debit']);
    }

    // ── runningBalances ──

    public function test_running_balance_accumulates_in_the_accounts_normal_direction(): void
    {
        $rows = GeneralLedgerBuilder::runningBalances('debit', [
            ['debit' => 100000, 'credit' => 0],
            ['debit' => 0, 'credit' => 30000],
            ['debit' => 50000, 'credit' => 0],
        ], 0);

        $this->assertSame([100000, 70000, 120000], $rows);
    }

    public function test_running_balance_on_a_credit_normal_account_grows_with_credits(): void
    {
        $rows = GeneralLedgerBuilder::runningBalances('credit', [
            ['debit' => 0, 'credit' => 100000],
            ['debit' => 40000, 'credit' => 0],
        ], 0);

        $this->assertSame([100000, 60000], $rows);
    }

    public function test_running_balance_starts_from_the_opening_balance(): void
    {
        $this->assertSame(
            [25000],
            GeneralLedgerBuilder::runningBalances('debit', [['debit' => 5000, 'credit' => 0]], 20000),
        );
    }

    public function test_an_empty_ledger_produces_no_rows(): void
    {
        $this->assertSame([], GeneralLedgerBuilder::runningBalances('debit', [], 1000));
    }
}
