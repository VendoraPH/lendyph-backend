<?php

namespace Tests\Unit\Savings;

use App\Exceptions\CannotPostToTheBooksException;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingRules;
use PHPUnit\Framework\TestCase;

/**
 * The five savings posting rules (design §4): each balances, never emits a
 * zero line, refuses a zero or negative amount and an entry that would debit
 * and credit the same role, and fails closed on an unmapped role.
 */
class SavingsPostingRulesTest extends TestCase
{
    private function map(array $without = []): AccountMap
    {
        return AccountMap::fromArray(array_diff_key([
            'cash' => 1010,
            'gcash' => 1020,
            'maya' => 1030,
            'bank' => 1040,
            'savings_deposits' => 2050,
            'time_deposits' => 2060,
            'interest_expense_deposits' => 5160,
            'withholding_tax_deposits' => 2210,
        ], array_flip($without)));
    }

    private function assertBalancedAndNonEmpty(array $posting): void
    {
        $debit = array_sum(array_column($posting['lines'], 'debit'));
        $credit = array_sum(array_column($posting['lines'], 'credit'));

        $this->assertSame($debit, $credit, 'The entry does not balance.');
        $this->assertGreaterThan(0, $debit, 'The entry records nothing.');

        foreach ($posting['lines'] as $index => $line) {
            $this->assertTrue($line['debit'] !== 0 || $line['credit'] !== 0, "Line {$index} carries no amount.");
            $this->assertTrue($line['debit'] === 0 || $line['credit'] === 0, "Line {$index} is both a debit and a credit.");
        }
    }

    /** @return list<array{0: int, 1: int, 2: int}> account, debit, credit */
    private function lines(array $posting): array
    {
        return array_map(static fn (array $line): array => [$line['account_id'], $line['debit'], $line['credit']], $posting['lines']);
    }

    private function assertRefused(callable $rule, string $contains = ''): void
    {
        try {
            $rule();
        } catch (CannotPostToTheBooksException $e) {
            if ($contains !== '') {
                $this->assertStringContainsString($contains, $e->getMessage());
            }
            $this->assertStringNotContainsString('_', $e->getMessage(), 'The message names a role by its key.');

            return;
        }

        $this->fail('The rule did not refuse.');
    }

    public function test_a_deposit_debits_the_settlement_account_and_credits_savings_deposits(): void
    {
        $posting = PostingRules::savingsDeposit(150_050, 'cash', $this->map());

        $this->assertSame('savings', $posting['source']);
        $this->assertSame([[1010, 150_050, 0], [2050, 0, 150_050]], $this->lines($posting));
        $this->assertBalancedAndNonEmpty($posting);
        $this->assertSame([[1040, 100, 0], [2050, 0, 100]], $this->lines(PostingRules::savingsDeposit(100, 'bank', $this->map())));
    }

    public function test_a_withdrawal_debits_savings_deposits_and_credits_the_settlement_account(): void
    {
        $posting = PostingRules::savingsWithdrawal(99_999, 'gcash', $this->map());

        $this->assertSame('savings', $posting['source']);
        $this->assertSame([[2050, 99_999, 0], [1020, 0, 99_999]], $this->lines($posting));
        $this->assertBalancedAndNonEmpty($posting);
    }

    public function test_interest_debits_the_expense_gross_and_splits_net_and_tax(): void
    {
        $posting = PostingRules::savingsInterest(10_000, 2_000, $this->map());

        $this->assertSame('savings_interest', $posting['source']);
        $this->assertSame([[5160, 10_000, 0], [2050, 0, 8_000], [2210, 0, 2_000]], $this->lines($posting));
        $this->assertBalancedAndNonEmpty($posting);
    }

    public function test_interest_with_no_tax_has_no_tax_line_and_needs_no_tax_account(): void
    {
        $posting = PostingRules::savingsInterest(1_234, 0, $this->map(['withholding_tax_deposits']));

        $this->assertSame([[5160, 1_234, 0], [2050, 0, 1_234]], $this->lines($posting));
        $this->assertBalancedAndNonEmpty($posting);
    }

    public function test_a_placement_moves_money_from_the_funding_account_to_time_deposits(): void
    {
        $fromCash = PostingRules::timeDepositPlacement(5_000_000, 'cash', $this->map());
        $fromSavings = PostingRules::timeDepositPlacement(5_000_000, 'savings_deposits', $this->map());

        $this->assertSame('time_deposit', $fromCash['source']);
        $this->assertSame([[1010, 5_000_000, 0], [2060, 0, 5_000_000]], $this->lines($fromCash));
        $this->assertSame([[2050, 5_000_000, 0], [2060, 0, 5_000_000]], $this->lines($fromSavings));
        $this->assertBalancedAndNonEmpty($fromCash);
        $this->assertBalancedAndNonEmpty($fromSavings);
    }

    public function test_a_settlement_paid_out_in_full(): void
    {
        $posting = PostingRules::timeDepositSettlement(5_000_000, 61_644, 12_329, 0, 5_049_315, 'cash', $this->map());

        $this->assertSame('time_deposit_settlement', $posting['source']);
        $this->assertSame(
            [[2060, 5_000_000, 0], [5160, 61_644, 0], [2210, 0, 12_329], [1010, 0, 5_049_315]],
            $this->lines($posting),
        );
        $this->assertBalancedAndNonEmpty($posting);
    }

    public function test_a_settlement_rolled_over_in_part_and_paid_into_savings(): void
    {
        $posting = PostingRules::timeDepositSettlement(5_000_000, 61_644, 0, 5_000_000, 61_644, 'savings_deposits', $this->map(['withholding_tax_deposits']));

        $this->assertSame(
            [[2060, 5_000_000, 0], [5160, 61_644, 0], [2060, 0, 5_000_000], [2050, 0, 61_644]],
            $this->lines($posting),
        );
        $this->assertBalancedAndNonEmpty($posting);
    }

    public function test_a_settlement_rolled_over_whole_needs_no_payout_account(): void
    {
        $posting = PostingRules::timeDepositSettlement(1_000_000, 5_000, 1_000, 1_004_000, 0, null, $this->map());

        $this->assertSame([[2060, 1_000_000, 0], [5160, 5_000, 0], [2060, 0, 1_004_000], [2210, 0, 1_000]], $this->lines($posting));
        $this->assertBalancedAndNonEmpty($posting);
    }

    public function test_zero_and_negative_amounts_are_refused(): void
    {
        $map = $this->map();

        $this->assertRefused(fn () => PostingRules::savingsDeposit(0, 'cash', $map), 'greater than zero');
        $this->assertRefused(fn () => PostingRules::savingsDeposit(-1, 'cash', $map));
        $this->assertRefused(fn () => PostingRules::savingsWithdrawal(0, 'cash', $map));
        $this->assertRefused(fn () => PostingRules::savingsInterest(0, 0, $map));
        $this->assertRefused(fn () => PostingRules::savingsInterest(100, -1, $map));
        $this->assertRefused(fn () => PostingRules::timeDepositPlacement(0, 'cash', $map));
        $this->assertRefused(fn () => PostingRules::timeDepositSettlement(0, 0, 0, 0, 0, null, $map));
        $this->assertRefused(fn () => PostingRules::timeDepositSettlement(100, -1, 0, 0, 99, 'cash', $map));
    }

    public function test_an_entry_that_would_post_both_sides_to_one_role_is_refused(): void
    {
        $map = $this->map();

        $this->assertRefused(fn () => PostingRules::savingsDeposit(100, 'savings_deposits', $map), 'not a settlement account');
        $this->assertRefused(fn () => PostingRules::savingsWithdrawal(100, 'savings_deposits', $map), 'not a settlement account');
        $this->assertRefused(fn () => PostingRules::timeDepositPlacement(100, 'time_deposits', $map));
        $this->assertRefused(fn () => PostingRules::timeDepositSettlement(100, 0, 0, 0, 100, 'time_deposits', $map));
    }

    public function test_tax_above_the_gross_interest_is_refused(): void
    {
        $this->assertRefused(fn () => PostingRules::savingsInterest(100, 101, $this->map()), 'tax');
        $this->assertRefused(fn () => PostingRules::timeDepositSettlement(100, 10, 11, 0, 99, 'cash', $this->map()), 'tax');
    }

    public function test_a_settlement_that_does_not_balance_is_refused(): void
    {
        $map = $this->map();

        $this->assertRefused(fn () => PostingRules::timeDepositSettlement(1_000_000, 5_000, 1_000, 0, 1_004_001, 'cash', $map), 'does not add up');
        $this->assertRefused(fn () => PostingRules::timeDepositSettlement(1_000_000, 5_000, 1_000, 500_000, 504_000, null, $map), 'payout');
    }

    public function test_each_rule_fails_closed_on_an_unmapped_role(): void
    {
        $this->assertRefused(fn () => PostingRules::savingsDeposit(100, 'cash', $this->map(['savings_deposits'])), 'Savings deposits');
        $this->assertRefused(fn () => PostingRules::savingsWithdrawal(100, 'maya', $this->map(['maya'])), 'Maya');
        $this->assertRefused(fn () => PostingRules::savingsInterest(100, 0, $this->map(['interest_expense_deposits'])), 'Interest expense on deposits');
        $this->assertRefused(fn () => PostingRules::savingsInterest(100, 10, $this->map(['withholding_tax_deposits'])), 'Withholding tax on deposits');
        $this->assertRefused(fn () => PostingRules::timeDepositPlacement(100, 'cash', $this->map(['time_deposits'])), 'Time deposits');
        $this->assertRefused(fn () => PostingRules::timeDepositSettlement(100, 10, 0, 0, 110, 'cash', $this->map(['interest_expense_deposits'])), 'Interest expense on deposits');
    }
}
