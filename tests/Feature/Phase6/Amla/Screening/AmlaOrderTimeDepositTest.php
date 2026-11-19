<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Models\AmlaOrderHold;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use App\Services\Amla\Screening\Orders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Savings\TimeDeposits\TimeDepositFixtures;
use Tests\TestCase;

/**
 * Time deposits under a legal order (AMLA-B5 review decision): while a member
 * is under an active order, a time deposit can't be ended early or paid out
 * of the cooperative (cash, bank, GCash, Maya). A rollover, or a payout into
 * the member's own savings, stays allowed. The refusal never names AMLA. An
 * order needs no savings holds.
 */
class AmlaOrderTimeDepositTest extends TestCase
{
    use TimeDepositFixtures;

    private TimeDeposit $deposit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
        $this->deposit = TimeDeposit::query()->findOrFail($this->place()->assertCreated()->json('data.id'));
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    private function underOrder(): int
    {
        return app(Orders::class)->place($this->admin, [
            'reference' => 'Court order 7', 'authority' => 'court', 'received_on' => now()->toDateString(),
            'borrower_id' => $this->member->id, 'required_action' => 'Freeze the time deposit.', 'holds' => [],
        ])->id;
    }

    public function test_pre_termination_is_refused_in_words_while_the_member_is_under_an_order(): void
    {
        $this->underOrder();

        $message = $this->idem("/api/savings/time-deposits/{$this->deposit->id}/preterminate", [
            'date' => now()->toDateString(), 'reason' => 'Member needs the money for tuition.', 'payout_method' => 'cash',
        ])->assertUnprocessable()->json('errors.time_deposit.0');

        $this->assertSame("{$this->deposit->certificate_number} belongs to a member whose funds are under a legal order, so it can't be pre-terminated until the order is lifted.", $message);
        $this->assertStringNotContainsStringIgnoringCase('amla', $message);
        $this->assertSame('active', $this->deposit->fresh()->status);
    }

    public function test_a_payout_out_of_the_cooperative_is_refused_but_a_rollover_is_allowed(): void
    {
        $this->underOrder();
        Carbon::setTestNow(Carbon::parse('2027-02-08 10:00:00', 'Asia/Manila'));

        foreach (['cash', 'bank_transfer', 'gcash'] as $method) {
            $this->idem("/api/savings/time-deposits/{$this->deposit->id}/settle", ['date' => '2027-02-08', 'action' => 'payout', 'payout_method' => $method])
                ->assertUnprocessable()
                ->assertJsonPath('errors.payout_method.0', "{$this->deposit->certificate_number} belongs to a member whose funds are under a legal order, so it can only roll over or be paid into the member's own savings until the order is lifted.");
        }

        $this->idem("/api/savings/time-deposits/{$this->deposit->id}/settle", ['date' => '2027-02-08', 'action' => 'rollover_all'])->assertSuccessful();
        $this->assertSame('rolled_over', $this->deposit->fresh()->status);
    }

    public function test_without_an_order_a_time_deposit_can_still_be_pre_terminated(): void
    {
        $this->idem("/api/savings/time-deposits/{$this->deposit->id}/preterminate", [
            'date' => now()->toDateString(), 'reason' => 'Member needs the money for tuition.', 'payout_method' => 'cash',
        ])->assertSuccessful();
    }

    public function test_the_nightly_run_pays_a_matured_deposit_into_savings_under_an_order_and_that_money_stays_held(): void
    {
        $savings = $this->savingsAccount();
        $deposit = TimeDeposit::query()->findOrFail($this->place(['payout_account_id' => $savings->id])->assertCreated()->json('data.id'));
        $orderId = $this->underOrder();

        Carbon::setTestNow(Carbon::parse('2027-02-08 10:00:00', 'Asia/Manila'));
        Artisan::call('savings:mature-time-deposits');

        $this->assertSame('withdrawn', $deposit->fresh()->status, 'the member\'s own instruction still runs');
        $credited = (string) $deposit->fresh()->settled_amount;
        $hold = $this->orderHoldOn($savings, $orderId);
        $this->assertSame($credited, (string) $hold->amount);
        $this->assertNull($hold->created_by, 'placed by the nightly run');

        $this->idem("/api/savings/accounts/{$savings->id}/withdrawals", ['amount' => $credited, 'date' => '2027-02-08', 'payment_method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->assertSame($credited, (string) $savings->fresh()->ledger_balance);
    }

    public function test_a_staff_settlement_into_savings_under_an_order_holds_the_money_against_withdrawals_and_transfers(): void
    {
        $savings = $this->savingsAccount();
        $other = SavingsAccount::factory()->create([
            'borrower_id' => $this->member->id, 'product_id' => SavingsProduct::factory()->create()->id,
            'branch_id' => $this->member->branch_id, 'opened_on' => now()->toDateString(),
        ]);
        $orderId = $this->underOrder();
        Carbon::setTestNow(Carbon::parse('2027-02-08 10:00:00', 'Asia/Manila'));

        $this->idem("/api/savings/time-deposits/{$this->deposit->id}/settle", [
            'date' => '2027-02-08', 'action' => 'payout', 'payout_method' => 'savings', 'payout_account_id' => $savings->id,
        ])->assertSuccessful();

        $credited = (string) $this->deposit->fresh()->settled_amount;
        $this->assertSame($credited, (string) $this->orderHoldOn($savings, $orderId)->amount);
        $this->assertSame($this->admin->id, $this->orderHoldOn($savings, $orderId)->created_by);

        $this->idem("/api/savings/accounts/{$savings->id}/withdrawals", ['amount' => '1.00', 'date' => '2027-02-08', 'payment_method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->idem("/api/savings/accounts/{$savings->id}/transfers", ['to_account_id' => $other->id, 'amount' => '1.00', 'date' => '2027-02-08'])
            ->assertUnprocessable();
        $this->assertSame('0.00', (string) $other->fresh()->ledger_balance);
    }

    public function test_without_an_order_a_payout_into_savings_places_no_hold(): void
    {
        $savings = $this->savingsAccount();
        Carbon::setTestNow(Carbon::parse('2027-02-08 10:00:00', 'Asia/Manila'));

        $this->idem("/api/savings/time-deposits/{$this->deposit->id}/settle", [
            'date' => '2027-02-08', 'action' => 'payout', 'payout_method' => 'savings', 'payout_account_id' => $savings->id,
        ])->assertSuccessful();

        $this->assertSame(0, SavingsHold::query()->where('account_id', $savings->id)->count());
    }

    private function orderHoldOn(SavingsAccount $account, int $orderId): SavingsHold
    {
        $hold = SavingsHold::query()->where('account_id', $account->id)->where('type', 'legal')->where('status', 'active')->sole();
        $this->assertSame($orderId, AmlaOrderHold::query()->where('savings_hold_id', $hold->id)->sole()->order_id, 'linked to the member\'s order, so only its release lifts it');

        return $hold;
    }
}
