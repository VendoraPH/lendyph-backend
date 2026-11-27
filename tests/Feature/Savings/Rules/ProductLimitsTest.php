<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Savings product rules at the counter (gap round S1): the minimum and
 * maximum deposit and withdrawal, the maximum balance, and the allow-deposits,
 * allow-withdrawals and allow-transfers switches, each refused in words (422
 * on `amount`) with ₱ amounts, and each limit passing when equal to it. The
 * maximum balance and allow-deposits never stop money the member didn't put
 * in: closing interest and reversals.
 */
class ProductLimitsTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rules(array $rules): void
    {
        $this->product->forceFill($rules)->save();
    }

    private function cash(string $amount): array
    {
        return ['amount' => $amount, 'date' => '2026-11-10', 'payment_method' => 'cash'];
    }

    private function deposit(SavingsAccount $account, string $amount)
    {
        return $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash($amount));
    }

    private function withdraw(SavingsAccount $account, string $amount)
    {
        return $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash($amount));
    }

    public function test_a_deposit_below_the_minimum_or_above_the_maximum_is_refused_and_equal_passes(): void
    {
        $this->rules(['min_deposit' => '100.00', 'max_deposit' => '5000.00']);
        $account = $this->account();

        $this->deposit($account, '99.99')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'A deposit to Regular Savings is at least ₱100.00.');
        $this->deposit($account, '5000.01')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'A deposit to Regular Savings is at most ₱5,000.00.');

        $this->deposit($account, '100.00')->assertCreated();
        $this->deposit($account, '5000.00')->assertCreated();
        $this->assertSame('5100.00', $this->balanceOf($account));
    }

    public function test_a_deposit_above_the_maximum_balance_is_refused_and_reaching_it_exactly_passes(): void
    {
        $this->rules(['max_balance' => '10000.00']);
        $account = $this->account();
        $this->fund($account, 900_000);

        $this->deposit($account, '1000.01')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', "That would take {$account->account_number} to ₱10,000.01, above Regular Savings's maximum balance of ₱10,000.00. It can take up to ₱1,000.00 more.");

        $this->deposit($account, '1000.00')->assertCreated();
        $this->assertSame('10000.00', $this->balanceOf($account));
    }

    public function test_a_product_that_takes_no_deposits_refuses_them(): void
    {
        $this->rules(['allow_deposits' => false]);
        $account = $this->account();

        $this->deposit($account, '100.00')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Regular Savings takes no deposits.');
    }

    public function test_a_withdrawal_below_the_minimum_or_above_the_maximum_is_refused_and_equal_passes(): void
    {
        $this->rules(['min_withdrawal' => '200.00', 'max_withdrawal' => '3000.00']);
        $account = $this->account();
        $this->fund($account, 1_000_000);

        $this->withdraw($account, '199.99')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'A withdrawal from Regular Savings is at least ₱200.00.');
        $this->withdraw($account, '3000.01')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'A withdrawal from Regular Savings is at most ₱3,000.00.');

        $this->withdraw($account, '200.00')->assertCreated();
        $this->withdraw($account, '3000.00')->assertCreated();
        $this->assertSame('6800.00', $this->balanceOf($account));
    }

    public function test_a_product_that_allows_no_withdrawals_refuses_them(): void
    {
        $this->rules(['allow_withdrawals' => false]);
        $account = $this->account();
        $this->fund($account, 100_000);

        $this->withdraw($account, '100.00')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Regular Savings doesn\'t allow withdrawals.');
    }

    public function test_an_approved_withdrawal_rechecks_the_rules_when_it_posts(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $this->actingAs($this->teller());
        $approval = $this->withdraw($account, '15000.00')->assertStatus(202)->json('data.approval.id');

        $this->rules(['max_withdrawal' => '14999.99']);
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'A withdrawal from Regular Savings is at most ₱14,999.99.');
        $this->assertSame('20000.00', $this->balanceOf($account));
    }

    public function test_a_transfer_checks_allow_transfers_and_the_maximum_withdrawal_on_the_source(): void
    {
        $from = $this->account();
        $to = $this->otherAccount();
        $this->fund($from, 500_000);

        $this->rules(['max_withdrawal' => '1000.00']);
        $this->transfer($from, $to, '1000.01')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'A transfer out of Regular Savings is at most ₱1,000.00, its maximum withdrawal.');
        $this->transfer($from, $to, '1000.00')->assertCreated();

        $this->rules(['allow_transfers' => false]);
        $this->transfer($from, $to, '10.00')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Regular Savings doesn\'t allow transfers to other accounts.');
    }

    public function test_a_transfer_checks_allow_deposits_and_the_maximum_balance_on_the_target(): void
    {
        $from = $this->account();
        $to = $this->otherAccount();
        $this->fund($from, 500_000);
        $to->product->forceFill(['max_balance' => '1000.00'])->save();

        $this->transfer($from, $to, '1000.01')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', "That would take {$to->account_number} to ₱1,000.01, above Special Savings's maximum balance of ₱1,000.00. It can take up to ₱1,000.00 more.");
        $this->transfer($from, $to, '1000.00')->assertCreated();

        $to->product->forceFill(['max_balance' => null, 'allow_deposits' => false])->save();
        $this->transfer($from, $to, '10.00')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', "{$to->account_number} is a Special Savings account, which takes no deposits, so it can't receive a transfer.");
    }

    public function test_a_reversal_that_credits_back_is_not_stopped_by_the_maximum_balance_or_allow_deposits(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $withdrawal = SavingsTransaction::query()->findOrFail($this->withdraw($account, '400.00')->assertCreated()->json('data.id'));
        $this->deposit($account, '400.00')->assertCreated();
        $this->rules(['max_balance' => '1000.00', 'allow_deposits' => false]);

        $approval = $this->money("/api/savings/transactions/{$withdrawal->id}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval.id');
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $this->assertSame('1400.00', $this->balanceOf($account));
    }

    public function test_closing_credits_interest_above_the_maximum_balance_and_pays_out(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000, '2026-11-01');
        $this->rules(['max_balance' => '5000.00', 'allow_deposits' => false]);

        $this->money("/api/savings/accounts/{$account->id}/close", ['date' => '2026-11-10', 'reason' => 'Member request', 'payout_method' => 'cash'])
            ->assertCreated();

        $this->assertTrue(SavingsTransaction::query()->where('account_id', $account->id)->where('type', 'interest')->exists());
        $this->assertSame('closed', $account->refresh()->status);
    }

    private function transfer(SavingsAccount $from, SavingsAccount $to, string $amount)
    {
        return $this->money("/api/savings/accounts/{$from->id}/transfers", ['to_account_id' => $to->id, 'amount' => $amount, 'date' => '2026-11-10']);
    }

    private function otherAccount(): SavingsAccount
    {
        $product = $this->product->replicate(['code'])->fill(['code' => 'SS-01', 'name' => 'Special Savings']);
        $product->save();

        return $this->account(['product_id' => $product->id])->load('product');
    }
}
