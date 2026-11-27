<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The S2 review's findings (H1, M1, M2, L1–L7) and the large-transaction
 * alert decision.
 */
class ReviewFixesTest extends TestCase
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

    private function cash(string $amount, string $date = '2026-11-10'): array
    {
        return ['amount' => $amount, 'date' => $date, 'payment_method' => 'cash'];
    }

    // ── H1: an approved close pays out only what was approved ──

    public function test_h1_a_close_whose_balance_changed_since_the_request_is_409_and_pays_nothing(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $approval = $this->money("/api/savings/accounts/{$account->id}/close", ['date' => '2026-11-10', 'reason' => 'Moving', 'payout_method' => 'cash'])
            ->assertStatus(202)->assertJsonPath('data.approval.amount', '20000.00')->json('data.approval');

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100.00'))->assertCreated();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'The balance changed since this close was requested. Make a new request.');

        $this->assertSame(['active', '20100.00'], [$account->refresh()->status, $account->ledger_balance]);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'closing')->count());
        // The stale request is rejected at once (re-review Low 1), so it no longer blocks the account.
        $this->assertSame('rejected', SavingsApproval::query()->findOrFail($approval['id'])->status);
    }

    // ── M1: no money moves on a frozen account, reversals included ──

    public function test_m1_a_reversal_on_a_frozen_account_is_refused_at_request_and_at_approval(): void
    {
        $account = $this->account();
        $deposit = $this->fund($account, 50_000);
        $account->forceFill(['status' => 'frozen'])->save();

        $this->money("/api/savings/transactions/{$deposit->id}/reverse", ['reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('transaction');

        $account->forceFill(['status' => 'active'])->save();
        $approval = $this->money("/api/savings/transactions/{$deposit->id}/reverse", ['reason' => 'x'])->assertStatus(202)->json('data.approval');
        $account->forceFill(['status' => 'frozen'])->save();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'reversal')->count());
    }

    public function test_m1_a_transfer_reversal_with_either_leg_frozen_is_refused(): void
    {
        [$from, $to, $legs] = $this->transferOf('300.00');
        $to->forceFill(['status' => 'frozen'])->save();

        $this->money("/api/savings/transactions/{$legs['transfer_out']['id']}/reverse", ['reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->assertSame(0, SavingsApproval::query()->count());
    }

    // ── M2: a row already counted in credited interest can't be reversed alone ──

    public function test_m2_a_row_inside_a_credited_period_cannot_be_reversed(): void
    {
        $account = $this->account();
        $deposit = $this->fund($account, 50_000, '2026-11-05');
        $account->forceFill(['interest_credited_through' => '2026-11-05'])->save();

        $this->money("/api/savings/transactions/{$deposit->id}/reverse", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.transaction.0', 'Interest has already been credited on this. Reverse that interest run first, then this, then run the period again.');
    }

    public function test_m2_a_credited_period_on_either_transfer_leg_and_one_credited_after_the_request(): void
    {
        [$from, $to, $legs] = $this->transferOf('300.00');
        $from->forceFill(['interest_credited_through' => '2026-11-10'])->save();

        $this->money("/api/savings/transactions/{$legs['transfer_in']['id']}/reverse", ['reason' => 'x'])->assertUnprocessable();

        $from->forceFill(['interest_credited_through' => null])->save();
        $approval = $this->money("/api/savings/transactions/{$legs['transfer_in']['id']}/reverse", ['reason' => 'x'])->assertStatus(202)->json('data.approval');
        $to->forceFill(['interest_credited_through' => '2026-11-10'])->save();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'reversal')->count());
    }

    // ── The large-transaction alert ──

    public function test_the_large_transaction_alert_reaches_branch_checkers_but_never_the_actor(): void
    {
        $account = $this->account();
        $checker = $this->checker();
        $elsewhere = $this->userWith(['savings:view', 'savings:approve'], Branch::factory()->create());
        $inactive = tap($this->checker())->update(['status' => 'inactive']);
        $teller = $this->userWith(['savings:view', 'savings:transact', 'savings:approve']);
        $this->actingAs($teller);

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('499999.99'))->assertCreated();
        $this->assertSame([], $this->alerted());

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('500000.00'))->assertCreated();

        $told = $this->alerted();
        $this->assertContains($checker->id, $told);
        $this->assertContains($this->admin->id, $told);
        $this->assertNotContains($teller->id, $told);
        $this->assertNotContains($elsewhere->id, $told);
        $this->assertNotContains($inactive->id, $told);
        $this->assertSame(count($told), count(array_unique($told)));

        $row = DB::table('notifications')->where('type', 'savings_large_transaction')->where('notifiable_id', $checker->id)->sole();
        $data = json_decode($row->data, true);
        $this->assertStringContainsString('₱500,000.00', $data['body']);
        $this->assertSame("/savings/accounts/{$account->id}", $data['url']);
    }

    public function test_an_approved_large_withdrawal_alerts_but_not_its_maker_or_checkers(): void
    {
        $account = $this->account();
        $this->fund($account, 60_000_000);
        DB::table('notifications')->delete();
        $watcher = $this->checker();
        $maker = $this->userWith(['savings:view', 'savings:transact', 'savings:approve']);
        $this->actingAs($maker);

        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('500000.00'))->assertStatus(202)->json('data.approval');
        $this->assertSame([], $this->alerted());

        $this->actingAs($first = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();
        $this->actingAs($second = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $told = $this->alerted();
        $this->assertContains($watcher->id, $told);
        $this->assertNotContains($maker->id, $told);
        $this->assertNotContains($second->id, $told);
        $this->assertNotContains($first->id, $told);
    }

    // ── L1: request-time date check against the account's latest row ──

    public function test_l1_a_withdrawal_request_or_close_dated_before_the_latest_row_is_422(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);

        $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('15000.00', '2026-11-09'))
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->money("/api/savings/accounts/{$account->id}/close", ['date' => '2026-11-09', 'reason' => 'x', 'payout_method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertSame(0, SavingsApproval::query()->count());
    }

    // ── L2: a pending reversal on the other leg of a transfer blocks closing ──

    public function test_l2_a_pending_transfer_reversal_blocks_closing_the_paying_account(): void
    {
        [$from, $to, $legs] = $this->transferOf('300.00');
        $this->money("/api/savings/transactions/{$legs['transfer_out']['id']}/reverse", ['reason' => 'x'])->assertStatus(202);

        $this->getJson("/api/savings/accounts/{$from->id}/closing-quote")->assertJsonPath('data.can_close', false);
        $this->money("/api/savings/accounts/{$from->id}/close", ['date' => '2026-11-10', 'reason' => 'x', 'payout_method' => 'cash'])->assertUnprocessable();
    }

    // ── L3: unfreezing restores dormancy ──

    public function test_l3_unfreezing_a_dormant_account_restores_dormant(): void
    {
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-01']);

        $this->postJson("/api/savings/accounts/{$account->id}/status", ['status' => 'frozen', 'reason' => 'Dispute'])->assertOk();
        $this->postJson("/api/savings/accounts/{$account->id}/status", ['status' => 'active', 'reason' => 'Resolved'])
            ->assertOk()->assertJsonPath('data.status', 'dormant')->assertJsonPath('data.dormant_since', '2026-11-01');
    }

    // ── L5: placing a hold is idempotent ──

    public function test_l5_placing_a_hold_needs_a_key_and_a_replay_places_one(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $body = ['type' => 'manual', 'amount' => '100.00', 'reason' => 'Check'];

        $this->postJson("/api/savings/accounts/{$account->id}/holds", $body)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $key = 'c6d9a0b1-2e3f-4a5b-8c7d-9e0f1a2b3c4d';
        $first = $this->money("/api/savings/accounts/{$account->id}/holds", $body, $key)->assertCreated();
        $this->money("/api/savings/accounts/{$account->id}/holds", $body, $key)->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, SavingsHold::query()->count());
    }

    // ── L6: the closing quote for a date ──

    public function test_l6_the_closing_quote_takes_a_date_validated_like_the_close(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000, '2026-11-01');
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Asia/Manila'));

        // Closing on Nov 10 credits Nov 1–9: nine days, ₱4.50.
        $this->getJson("/api/savings/accounts/{$account->id}/closing-quote?date=2026-11-10")->assertOk()
            ->assertJsonPath('data.accrued_interest', '4.50')->assertJsonPath('data.payout_amount', '5004.50');
        $this->getJson("/api/savings/accounts/{$account->id}/closing-quote")->assertJsonPath('data.accrued_interest', '5.00');
        $this->getJson("/api/savings/accounts/{$account->id}/closing-quote?date=2026-11-09")->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson("/api/savings/accounts/{$account->id}/closing-quote?date=2026-11-12")->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    // ── L7: cancel needs savings:transact as well as being the maker ──

    public function test_l7_cancel_needs_savings_transact_and_the_maker(): void
    {
        $account = $this->account();
        $viewer = $this->userWith(['savings:view']);
        $mine = SavingsApproval::factory()->create(['account_id' => $account->id, 'requested_by' => $viewer->id]);

        $this->actingAs($viewer);
        $this->postJson("/api/savings/approvals/{$mine->id}/cancel")->assertForbidden();
        $this->getJson('/api/savings/approvals')->assertOk()->assertJsonPath('meta.total', 1);

        $maker = $this->userWith(['savings:view', 'savings:transact']);
        $theirs = SavingsApproval::factory()->create(['account_id' => $account->id, 'requested_by' => $maker->id]);
        $this->actingAs($this->userWith(['savings:view', 'savings:transact']));
        $this->postJson("/api/savings/approvals/{$theirs->id}/cancel")->assertForbidden();

        $this->actingAs($maker);
        $this->postJson("/api/savings/approvals/{$theirs->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    /** @return array{0: SavingsAccount, 1: SavingsAccount, 2: array{transfer_out: array<string, mixed>, transfer_in: array<string, mixed>}} */
    private function transferOf(string $amount): array
    {
        $from = $this->account();
        $to = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->fund($from, 100_000);
        $legs = $this->money("/api/savings/accounts/{$from->id}/transfers", ['to_account_id' => $to->id, 'amount' => $amount, 'date' => '2026-11-10'])
            ->assertCreated()->json('data');

        return [$from, $to, $legs];
    }

    /** @return list<int> who got a large-transaction alert */
    private function alerted(): array
    {
        return DB::table('notifications')->where('type', 'savings_large_transaction')
            ->where('notifiable_type', User::class)->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
    }
}
