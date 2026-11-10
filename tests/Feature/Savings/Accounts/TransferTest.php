<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Transfers between a member's own accounts in one branch (design §2,
 * decision 9): two linked legs, no journal (both sit on 2050), no approval
 * tier, and all or nothing.
 */
class TransferTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $from;

    private SavingsAccount $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->from = $this->account();
        $this->to = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->fund($this->from, 8_000_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function transfer(array $overrides = [], ?SavingsAccount $from = null): TestResponse
    {
        return $this->money('/api/savings/accounts/'.($from ?? $this->from)->id.'/transfers', [
            'to_account_id' => $this->to->id,
            'amount' => '60000.00',
            'date' => '2026-11-10',
            'remarks' => 'To time deposit savings',
            ...$overrides,
        ]);
    }

    public function test_a_transfer_writes_two_linked_legs_with_no_journal_and_no_tier(): void
    {
        $response = $this->transfer()->assertCreated()
            ->assertJsonPath('data.transfer_out.type', 'transfer_out')
            ->assertJsonPath('data.transfer_out.debit', '60000.00')
            ->assertJsonPath('data.transfer_out.balance_after', '20000.00')
            ->assertJsonPath('data.transfer_out.method', 'savings')
            ->assertJsonPath('data.transfer_in.type', 'transfer_in')
            ->assertJsonPath('data.transfer_in.credit', '60000.00')
            ->assertJsonPath('data.transfer_in.balance_after', '60000.00')
            ->assertJsonPath('data.transfer_in.account_number', $this->to->account_number);

        $out = $response->json('data.transfer_out');
        $in = $response->json('data.transfer_in');
        $this->assertSame($out['id'], $in['counterpart_transaction_id']);
        $this->assertSame($in['id'], $out['counterpart_transaction_id']);
        $this->assertSame(0, AccountingJournal::query()->where('postable_type', SavingsTransaction::class)->whereIn('postable_id', [$out['id'], $in['id']])->count());
        $this->assertSame(['20000.00', '60000.00'], [$this->balanceOf($this->from), $this->balanceOf($this->to)]);
    }

    public function test_another_members_or_another_branchs_account_or_the_same_account_is_refused(): void
    {
        $someoneElse = SavingsAccount::factory()->create(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id, 'product_id' => $this->product->id]);
        $this->transfer(['to_account_id' => $someoneElse->id])->assertUnprocessable()->assertJsonValidationErrors('to_account_id');

        $elsewhere = $this->account(['branch_id' => Branch::factory()->create()->id, 'product_id' => SavingsProduct::factory()->create()->id]);
        $this->transfer(['to_account_id' => $elsewhere->id])->assertUnprocessable()->assertJsonValidationErrors('to_account_id');

        $this->transfer(['to_account_id' => $this->from->id])->assertUnprocessable()->assertJsonValidationErrors('to_account_id');

        $this->assertSame(1, SavingsTransaction::query()->count());
    }

    public function test_it_is_all_or_nothing(): void
    {
        // The receiving account already has a row dated today, so a leg dated
        // yesterday can't post there; the paying leg must not post either.
        $this->fund($this->to, 100);

        $this->transfer(['date' => '2026-11-09', 'amount' => '10.00'])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertSame(0, SavingsTransaction::query()->whereIn('type', ['transfer_in', 'transfer_out'])->count());
        $this->assertSame(['80000.00', '1.00'], [$this->balanceOf($this->from), $this->balanceOf($this->to)]);
    }

    public function test_the_available_balance_limits_it_and_a_frozen_or_closed_target_is_refused(): void
    {
        $this->transfer(['amount' => '80000.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->to->forceFill(['status' => 'frozen'])->save();
        $this->transfer()->assertUnprocessable()->assertJsonValidationErrors('to_account_id');

        $this->to->forceFill(['status' => 'closed', 'closed_on' => '2026-11-09', 'open_key' => null])->save();
        $this->transfer()->assertUnprocessable()->assertJsonValidationErrors('to_account_id');

        $this->assertSame(1, SavingsTransaction::query()->count());
    }

    public function test_a_transfer_into_a_dormant_account_reactivates_it(): void
    {
        $this->to->forceFill(['status' => 'dormant', 'dormant_since' => '2026-11-01'])->save();

        $this->transfer(['amount' => '10.00'])->assertCreated();

        $this->assertSame('active', $this->to->refresh()->status);
    }
}
