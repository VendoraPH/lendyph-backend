<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA-B8 (check S2): reversing a deposit takes its money back out, so on an
 * account under a legal order's hold it is refused (422, in words, naming no
 * AMLA record) whenever it would take the balance below what's held, both
 * when it's asked for and, for a hold placed while it waited, when the
 * checker approves it. A reversal the balance above the hold covers goes
 * ahead.
 */
class AmlaOrderReversalTest extends TestCase
{
    use AmlaTestHelpers, SavingsAccountsTestHelpers, SetupLendyPH;

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

    private function placeOrder(int $accountId, string $amount): void
    {
        $officer = $this->amlaUser(['amla:sanctions', 'branches:view_all']);
        $this->actingAs($officer)->postJson('/api/amla/orders', [
            'reference' => 'AMLC Freeze Order '.Str::random(6), 'authority' => 'amlc', 'received_on' => '2026-11-10', 'borrower_id' => $this->member->id,
            'required_action' => 'Freeze the funds.', 'responsible_officer_id' => $officer->id,
            'holds' => [['account_id' => $accountId, 'amount' => $amount]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    private function requestReversal(SavingsTransaction $row, User $maker): TestResponse
    {
        return $this->actingAs($maker)->money("/api/savings/transactions/{$row->id}/reverse", ['reason' => 'Keyed twice']);
    }

    public function test_reversing_a_deposit_below_an_order_hold_is_refused_in_words(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000, '2026-11-09');
        $deposit = $this->fund($account, 50_000, '2026-11-09');
        $this->placeOrder($account->id, '1200.00');

        $message = $this->requestReversal($deposit, $this->teller())->assertUnprocessable()->json('errors.amount.0');
        $this->assertSame('₱500.00 is more than the ₱300.00 available on '.$account->account_number.' (balance less holds and requests waiting for approval).', $message);
        $this->assertStringNotContainsStringIgnoringCase('amla', $message);
        $this->assertSame(0, SavingsApproval::query()->count());
        $this->assertSame('1500.00', $this->balanceOf($account));
    }

    public function test_a_hold_placed_while_a_reversal_waits_refuses_its_approval(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000, '2026-11-09');
        $deposit = $this->fund($account, 50_000, '2026-11-09');
        $approval = $this->requestReversal($deposit, $this->teller())->assertStatus(202)->json('data.approval');

        $this->placeOrder($account->id, '1200.00');

        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approval['id']}/approve")
            ->assertUnprocessable()->assertJsonPath('errors.amount.0', fn (string $message) => str_contains($message, 'available on '.$account->account_number));
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'reversal')->count());
        $this->assertSame('1500.00', $this->balanceOf($account));
        $this->assertSame('active', SavingsHold::query()->where('type', 'legal')->sole()->status);
    }

    public function test_a_reversal_the_balance_above_the_hold_covers_goes_ahead(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000, '2026-11-09');
        $deposit = $this->fund($account, 50_000, '2026-11-09');
        $this->placeOrder($account->id, '1000.00');
        $this->fund($account, 50_000, '2026-11-10');

        $approval = $this->requestReversal($deposit, $this->teller())->assertStatus(202)->json('data.approval');
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();

        $this->assertSame('1500.00', $this->balanceOf($account));
        $this->assertSame('active', SavingsHold::query()->where('type', 'legal')->sole()->status);
    }
}
