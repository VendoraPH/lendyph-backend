<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsIdempotencyKey;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Deposits and withdrawals (design §2, §8 "Accounts"): the row, its journal
 * and `balance_after`; the status rules; withdrawals limited to the
 * available balance; tiers 2 and 3 waiting for checkers who are never the
 * maker; idempotent replays; and a closed period refusing everything.
 */
class DepositAndWithdrawalTest extends TestCase
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

    private function cash(string $amount, array $overrides = []): array
    {
        return ['amount' => $amount, 'date' => '2026-11-10', 'payment_method' => 'cash', 'reference' => 'OR-77', 'remarks' => 'Counter', ...$overrides];
    }

    public function test_a_deposit_writes_the_row_the_journal_and_the_balance_after(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $this->actingAs($teller = $this->teller());

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('250.50', ['payment_method' => 'gcash']))
            ->assertCreated()
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.credit', '250.50')
            ->assertJsonPath('data.debit', '0.00')
            ->assertJsonPath('data.balance_after', '1250.50')
            ->assertJsonPath('data.method', 'gcash')
            ->assertJsonPath('data.external_reference', 'OR-77')
            ->assertJsonPath('data.remarks', 'Counter')
            ->assertJsonPath('data.value_date', '2026-11-10')
            ->assertJsonPath('data.account_number', $account->account_number)
            ->assertJsonPath('data.reversed_by_transaction_id', null)
            ->assertJsonPath('data.pending_reversal_approval_id', null)
            ->assertJsonPath('data.created_by', ['id' => $teller->id, 'name' => $teller->full_name]);

        $row = SavingsTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame([['1020', 25_050, 0], ['2050', 0, 25_050]], $this->journalLines($this->journalFor($row)));
        $this->assertSame('1250.50', $this->balanceOf($account));
    }

    public function test_a_deposit_reactivates_a_dormant_account_with_an_audit_row(): void
    {
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-05']);

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100'))->assertCreated();

        $account->refresh();
        $this->assertSame(['active', null], [$account->status, $account->dormant_since]);
        $this->assertTrue(AuditLog::query()->where('action', 'savings_account_reactivated')->where('auditable_id', $account->id)->exists());
    }

    public function test_frozen_and_closed_accounts_take_no_deposit(): void
    {
        foreach (['frozen' => [], 'closed' => ['closed_on' => '2026-11-09', 'open_key' => null]] as $status => $extra) {
            $account = $this->account(['status' => $status, ...$extra, 'product_id' => SavingsProduct::factory()->create()->id]);
            $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100'))->assertUnprocessable();
        }

        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    public function test_a_withdrawal_up_to_tier_one_posts_and_journals(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);

        $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('10000.00', ['payment_method' => 'bank_transfer']))
            ->assertCreated()
            ->assertJsonPath('data.type', 'withdrawal')
            ->assertJsonPath('data.debit', '10000.00')
            ->assertJsonPath('data.balance_after', '10000.00');

        $row = SavingsTransaction::query()->where('type', 'withdrawal')->sole();
        $this->assertSame([['2050', 1_000_000, 0], ['1040', 0, 1_000_000]], $this->journalLines($this->journalFor($row)));
    }

    public function test_a_withdrawal_above_the_available_balance_is_422(): void
    {
        $account = $this->account();
        $this->product->update(['maintaining_balance' => '100.00']);
        $this->fund($account, 100_000);
        SavingsHold::factory()->create(['account_id' => $account->id, 'amount' => '200.00']);

        // ₱1,000.00 less a ₱200.00 hold less ₱100.00 maintaining: ₱700.00.
        $this->getJson("/api/savings/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.available_balance', '700.00')
            ->assertJsonPath('data.hold_amount', '200.00');

        $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('700.01'))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('700.00'))->assertCreated();
    }

    public function test_dormant_frozen_and_closed_accounts_take_no_withdrawal(): void
    {
        foreach (['dormant' => [], 'frozen' => [], 'closed' => ['closed_on' => '2026-11-09', 'open_key' => null]] as $status => $extra) {
            $account = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
            $this->fund($account, 100_000);
            $account->forceFill(['status' => $status, ...$extra])->save();

            $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('10'))->assertUnprocessable();
        }

        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_tier_two_answers_202_and_the_maker_can_never_approve(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $this->actingAs($maker = $this->teller());

        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('10000.01'))
            ->assertStatus(202)
            ->assertJsonPath('data.approval.kind', 'withdrawal')
            ->assertJsonPath('data.approval.status', 'pending')
            ->assertJsonPath('data.approval.amount', '10000.01')
            ->assertJsonPath('data.approval.required_checkers', 1)
            ->assertJsonPath('data.approval.account_number', $account->account_number)
            ->assertJsonPath('data.approval.borrower_name', 'Maria Santos')
            ->assertJsonPath('data.approval.requested_by', ['id' => $maker->id, 'name' => $maker->full_name])
            ->json('data.approval');

        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        // The request reserves its amount at once.
        $this->getJson("/api/savings/accounts/{$account->id}")->assertJsonPath('data.pending_amount', '10000.01')
            ->assertJsonPath('data.available_balance', '19999.99');

        $makerWhoCanApprove = $this->userWith(['savings:view', 'savings:transact', 'savings:approve']);
        $this->actingAs($makerWhoCanApprove);
        $second = $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('10000.02'))->assertStatus(202)->json('data.approval');
        $this->postJson("/api/savings/approvals/{$second['id']}/approve")->assertForbidden();

        $this->actingAs($checker = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by', ['id' => $checker->id, 'name' => $checker->full_name]);

        $row = SavingsTransaction::query()->where('type', 'withdrawal')->sole();
        $this->assertSame(['10000.01', $approval['id'], $maker->id], [$row->debit, $row->approval_id, $row->created_by]);
        $this->assertSame($row->id, SavingsApproval::query()->findOrFail($approval['id'])->result_transaction_id);
        $this->assertNotNull($this->journalFor($row));
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertStatus(409);
    }

    public function test_tier_two_executes_when_its_own_reservation_is_all_that_stands_in_the_way(): void
    {
        $account = $this->account();
        $this->fund($account, 1_500_000);

        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('15000.00'))->assertStatus(202)->json('data.approval');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('0.00', $this->balanceOf($account));
    }

    public function test_tier_three_needs_two_different_checkers(): void
    {
        $account = $this->account();
        $this->fund($account, 6_000_000);

        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('50000.01'))
            ->assertStatus(202)->assertJsonPath('data.approval.required_checkers', 2)->json('data.approval');

        $this->actingAs($first = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.first_checker', ['id' => $first->id, 'name' => $first->full_name]);
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertForbidden();
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_approval_rechecks_the_money_under_the_lock(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('15000.00'))->assertStatus(202)->json('data.approval');

        // A legal hold placed afterwards takes the money the request counted on.
        SavingsHold::factory()->create(['account_id' => $account->id, 'type' => 'legal', 'amount' => '10000.00']);

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertUnprocessable();
        $this->assertSame('pending', SavingsApproval::query()->findOrFail($approval['id'])->status);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_an_approval_past_its_expiry_is_409_even_before_the_nightly_job(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('15000.00'))->assertStatus(202)->json('data.approval');

        $this->actingAs($this->checker());
        $this->on('2026-11-11', fn () => $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertStatus(409));
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_an_idempotent_replay_posts_once_and_the_same_key_with_another_body_is_422(): void
    {
        $account = $this->account();
        $key = 'a3d8a1c2-7b6e-4c1d-9f2a-5b8e1c0d9e11';

        $first = $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100'), $key)->assertCreated();
        $replay = $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100'), $key)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, SavingsTransaction::query()->count());
        $this->assertSame(1, AccountingJournal::query()->where('postable_type', SavingsTransaction::class)->count());

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('200'), $key)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame('100.00', $this->balanceOf($account));
    }

    public function test_a_closed_period_is_422_and_writes_nothing(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse('2026-11-01')) + [
            'status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->admin->id,
        ]);
        $before = [SavingsTransaction::query()->count(), AccountingJournal::query()->count(), SavingsIdempotencyKey::query()->count()];

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100'))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('100'))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", $this->cash('15000'))->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertSame($before, [SavingsTransaction::query()->count(), AccountingJournal::query()->count(), SavingsIdempotencyKey::query()->count()]);
        $this->assertSame(0, SavingsApproval::query()->count());
        $this->assertSame('20000.00', $this->balanceOf($account));
    }

    public function test_dates_outside_today_and_yesterday_are_refused(): void
    {
        $account = $this->account();

        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100', ['date' => '2026-11-11']))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100', ['date' => '2026-11-08']))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->money("/api/savings/accounts/{$account->id}/deposits", $this->cash('100', ['date' => '2026-11-09']))->assertCreated();
    }
}
