<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\SavingsSetting;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\AvailableBalance;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Available balance = ledger − active holds − pending outflow approvals −
 * maintaining balance, floored at ₱0.00; and the approval tiers, the
 * pending approval row and the maker-checker guard.
 */
class AvailableBalanceAndApprovalGateTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $ledger, string $maintaining = '0.00'): SavingsAccount
    {
        $product = SavingsProduct::factory()->create(['maintaining_balance' => $maintaining]);

        return SavingsAccount::factory()->for($product, 'product')->create(['ledger_balance' => $ledger]);
    }

    public function test_it_takes_holds_pending_outflows_and_the_maintaining_balance_off_the_ledger(): void
    {
        $account = $this->account('1000.00', '50.00');

        SavingsHold::factory()->for($account, 'account')->create(['amount' => '200.00']);
        SavingsHold::factory()->for($account, 'account')->create(['amount' => '75.00', 'expires_on' => '2026-11-10']);
        SavingsHold::factory()->for($account, 'account')->create(['amount' => '999.00', 'expires_on' => '2026-11-09']);
        SavingsHold::factory()->for($account, 'account')->create(['amount' => '999.00', 'status' => 'released']);

        SavingsApproval::factory()->for($account, 'account')->create(['kind' => 'withdrawal', 'amount' => '100.00']);
        SavingsApproval::factory()->for($account, 'account')->create(['kind' => 'withdrawal', 'amount' => '999.00', 'status' => 'rejected']);
        SavingsApproval::factory()->for($account, 'account')->create(['kind' => 'withdrawal', 'amount' => '999.00', 'expires_at' => now()->subMinute()]);

        $deposit = SavingsTransaction::factory()->for($account, 'account')->create(['type' => 'deposit', 'credit' => '25.00', 'debit' => '0.00']);
        $withdrawal = SavingsTransaction::factory()->for($account, 'account')->create(['type' => 'withdrawal', 'credit' => '0.00', 'debit' => '30.00']);
        SavingsApproval::factory()->for($account, 'account')->create(['kind' => 'reversal', 'amount' => '25.00', 'target_transaction_id' => $deposit->id]);
        SavingsApproval::factory()->for($account, 'account')->create(['kind' => 'reversal', 'amount' => '30.00', 'target_transaction_id' => $withdrawal->id]);

        $breakdown = app(AvailableBalance::class)->breakdown($account);

        $this->assertSame([
            'ledger' => 100_000,
            'holds' => 27_500,
            'pending' => 12_500,
            'maintaining' => 5_000,
            'available' => 55_000,
        ], $breakdown);
        $this->assertSame(55_000, app(AvailableBalance::class)->for($account));
    }

    public function test_it_never_reads_below_zero(): void
    {
        $account = $this->account('100.00', '500.00');

        $this->assertSame(0, app(AvailableBalance::class)->for($account));
    }

    public function test_many_accounts_read_the_same_as_one_by_one(): void
    {
        $first = $this->account('1000.00', '100.00');
        $second = $this->account('300.00');
        SavingsHold::factory()->for($second, 'account')->create(['amount' => '50.00']);

        $many = app(AvailableBalance::class)->forMany(collect([$first, $second]));

        $this->assertSame(app(AvailableBalance::class)->breakdown($first), $many[$first->id]);
        $this->assertSame(app(AvailableBalance::class)->breakdown($second), $many[$second->id]);
        $this->assertSame(25_000, $many[$second->id]['available']);
    }

    public function test_the_tiers_read_the_settings_at_their_edges(): void
    {
        $gate = app(ApprovalGate::class);

        $this->assertSame(ApprovalGate::TIER_DIRECT, $gate->tierFor(1_000_000));
        $this->assertSame(ApprovalGate::TIER_SINGLE, $gate->tierFor(1_000_001));
        $this->assertSame(ApprovalGate::TIER_SINGLE, $gate->tierFor(5_000_000));
        $this->assertSame(ApprovalGate::TIER_DUAL, $gate->tierFor(5_000_001));

        $this->assertSame(0, $gate->requiredCheckers(1_000_000));
        $this->assertSame(1, $gate->requiredCheckers(1_000_001));
        $this->assertSame(2, $gate->requiredCheckers(5_000_001));

        SavingsSetting::current()->update(['tier1_limit' => '20000.00', 'tier3_checkers' => 3]);
        $this->assertSame(ApprovalGate::TIER_DIRECT, $gate->tierFor(1_000_001));
        $this->assertSame(3, $gate->requiredCheckers(5_000_001));
    }

    public function test_open_writes_a_pending_approval_that_expires_at_the_end_of_the_business_day(): void
    {
        $account = $this->account('100000.00');

        $approval = app(ApprovalGate::class)->open('withdrawal', 5_000_001, [
            'account_id' => $account->id,
            'payload' => ['method' => 'cash'],
        ], $this->admin->id);

        $this->assertSame('pending', $approval->status);
        $this->assertSame('50000.01', $approval->amount);
        $this->assertSame(2, $approval->required_checkers);
        $this->assertSame($this->admin->id, $approval->requested_by);
        $this->assertSame('2026-11-10 23:59:59', $approval->expires_at->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame(['method' => 'cash'], $approval->payload);

        $small = app(ApprovalGate::class)->open('reversal', 100, ['account_id' => $account->id], $this->admin->id);
        $this->assertSame(1, $small->required_checkers, 'An approval opened below tier 1 still needs one checker.');
    }

    public function test_the_maker_and_a_repeat_checker_are_refused_with_403(): void
    {
        $account = $this->account('100000.00');
        $approval = app(ApprovalGate::class)->open('withdrawal', 5_000_001, ['account_id' => $account->id], $this->admin->id);
        $checker = User::factory()->create(['branch_id' => $this->branch->id]);

        foreach ([$this->admin, null] as $who) {
            if ($who === null) {
                $approval->update(['first_checker_id' => $checker->id]);
                $who = $checker;
            }

            try {
                app(ApprovalGate::class)->assertCheckerAllowed($approval, $who);
                $this->fail('The checker was allowed.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        app(ApprovalGate::class)->assertCheckerAllowed($approval, User::factory()->create(['branch_id' => $this->branch->id]));
    }

    public function test_executors_are_found_by_convention(): void
    {
        $this->assertSame('App\\Services\\Savings\\Approvals\\TdPayoutExecutor', ApprovalGate::executorClass('td_payout'));
        $this->assertSame('App\\Services\\Savings\\Approvals\\WithdrawalExecutor', ApprovalGate::executorClass('withdrawal'));

        $this->expectException(LogicException::class);
        app(ApprovalGate::class)->executor('not_a_kind');
    }
}
