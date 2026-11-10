<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The S2 re-review's Lows: a stale close is rejected as soon as it is found
 * (at the last or the first checker), the closing quote's date rule, the
 * alert on closing payouts with recipients narrowed in SQL, and reversals
 * refused while an interest run holding the account is unposted.
 */
class ReReviewFixesTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private const STALE = 'The balance changed since this close was requested. Make a new request.';

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

    private function closeBody(): array
    {
        return ['date' => '2026-11-10', 'reason' => 'Moving', 'payout_method' => 'cash'];
    }

    // ── 1. A stale close is rejected at once, freeing the account ──

    public function test_a_stale_close_is_rejected_in_its_own_write_and_a_new_close_can_be_requested(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $old = $this->money("/api/savings/accounts/{$account->id}/close", $this->closeBody())->assertStatus(202)->json('data.approval');
        $this->money("/api/savings/accounts/{$account->id}/deposits", ['amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$old['id']}/approve")->assertStatus(409)->assertJsonPath('message', self::STALE);

        $stale = SavingsApproval::query()->findOrFail($old['id']);
        $this->assertSame(['rejected', 'The balance changed since this close was requested.', null, null], [
            $stale->status, $stale->decision_reason, $stale->decided_by, $stale->live_key,
        ]);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'closing')->count());

        $this->actingAs($this->admin);
        $this->getJson("/api/savings/accounts/{$account->id}")->assertJsonPath('data.pending_amount', '0.00');
        $this->money("/api/savings/accounts/{$account->id}/close", $this->closeBody())->assertStatus(202)->assertJsonPath('data.approval.amount', '20100.00');
    }

    // ── 2. ...and caught at the first of two checkers ──

    public function test_the_first_of_two_checkers_catches_a_stale_close(): void
    {
        $account = $this->account();
        $this->fund($account, 6_000_000);
        $old = $this->money("/api/savings/accounts/{$account->id}/close", $this->closeBody())
            ->assertStatus(202)->assertJsonPath('data.approval.required_checkers', 2)->json('data.approval');
        $this->money("/api/savings/accounts/{$account->id}/deposits", ['amount' => '1.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$old['id']}/approve")->assertStatus(409)->assertJsonPath('message', self::STALE);

        $stale = SavingsApproval::query()->findOrFail($old['id']);
        $this->assertSame(['rejected', null], [$stale->status, $stale->first_checker_id]);
    }

    // ── 3. The quote's date can't be before the latest row ──

    public function test_the_closing_quote_blocks_a_date_before_the_latest_row(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);

        $blockers = $this->getJson("/api/savings/accounts/{$account->id}/closing-quote?date=2026-11-09")->assertOk()
            ->assertJsonPath('data.can_close', false)->json('data.blockers');
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Nov 10, 2026', $blockers[0]);

        $this->getJson("/api/savings/accounts/{$account->id}/closing-quote?date=2026-11-10")->assertJsonPath('data.can_close', true);
    }

    // ── 4. The alert: closing payouts too, transfers never, recipients by active role ──

    public function test_a_large_closing_payout_alerts_and_a_large_transfer_does_not(): void
    {
        $account = $this->account();
        $this->fund($account, 50_000_000);
        $watcher = $this->checker();
        $retired = $this->checker();
        $retired->roles()->update(['is_active' => false]);
        $this->actingAs($maker = $this->teller());

        $approval = $this->money("/api/savings/accounts/{$account->id}/close", $this->closeBody())->assertStatus(202)->json('data.approval');
        $this->actingAs($first = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();
        $this->actingAs($second = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $told = $this->alerted();
        $this->assertContains($watcher->id, $told);
        $this->assertContains($this->admin->id, $told);
        foreach ([$maker, $first, $second, $retired] as $user) {
            $this->assertNotContains($user->id, $told);
        }
        $body = json_decode((string) DB::table('notifications')->where('type', 'savings_large_transaction')->where('notifiable_id', $watcher->id)->value('data'), true)['body'];
        $this->assertStringContainsString('Closing payout of ₱500,000.00', $body);

        DB::table('notifications')->delete();
        $from = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $to = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->fund($from, 60_000_000);
        $this->actingAs($this->admin);
        $this->money("/api/savings/accounts/{$from->id}/transfers", ['to_account_id' => $to->id, 'amount' => '600000.00', 'date' => '2026-11-10'])->assertCreated();
        $this->assertSame([], $this->alerted());
    }

    // ── 5. No reversal while an interest run holding the account is unposted ──

    public function test_a_reversal_is_refused_while_a_draft_or_approved_run_holds_any_leg(): void
    {
        $account = $this->account();
        $deposit = $this->fund($account, 50_000);
        $run = $this->runHolding($account, 'draft');

        $this->money("/api/savings/transactions/{$deposit->id}/reverse", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.transaction.0', 'This account is in an interest run waiting to be posted. Post or reject that run first.');

        $run->update(['status' => 'rejected', 'live_key' => null]);
        $approval = $this->money("/api/savings/transactions/{$deposit->id}/reverse", ['reason' => 'x'])->assertStatus(202)->json('data.approval');
        $this->runHolding($account, 'approved');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'reversal')->count());
    }

    public function test_a_transfer_reversal_is_refused_when_the_other_legs_account_is_in_a_run(): void
    {
        $from = $this->account();
        $to = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->fund($from, 100_000);
        $legs = $this->money("/api/savings/accounts/{$from->id}/transfers", ['to_account_id' => $to->id, 'amount' => '10.00', 'date' => '2026-11-10'])->assertCreated()->json('data');
        $this->runHolding($from, 'draft');

        $this->money("/api/savings/transactions/{$legs['transfer_in']['id']}/reverse", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('transaction');
    }

    /**
     * S4's RunPoster re-checks a run against every account its product,
     * branch and period cover (RunBuilder::accounts()), lines or not, so an
     * account covered by an unposted run without a line of its own is held
     * too.
     */
    public function test_an_account_covered_by_an_unposted_run_without_a_line_is_held_too(): void
    {
        $account = $this->account(['opened_on' => '2026-10-15', 'last_member_activity_on' => '2026-10-15']);
        $deposit = $this->fund($account, 50_000);
        SavingsInterestRun::factory()->create([
            'product_id' => $account->product_id,
            'branch_id' => $account->branch_id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'draft',
        ]);

        $this->money("/api/savings/transactions/{$deposit->id}/reverse", ['reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->getJson("/api/savings/accounts/{$account->id}/closing-quote")->assertJsonPath('data.can_close', false);

        // An account opened after the run's period isn't covered by it.
        $later = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        SavingsInterestRun::factory()->create([
            'product_id' => $later->product_id, 'branch_id' => $later->branch_id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'draft',
        ]);
        $this->money("/api/savings/transactions/{$this->fund($later, 100)->id}/reverse", ['reason' => 'x'])->assertStatus(202);
    }

    private function runHolding(SavingsAccount $account, string $status): SavingsInterestRun
    {
        $run = SavingsInterestRun::factory()->create([
            'product_id' => $account->product_id,
            'branch_id' => $account->branch_id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => $status,
        ]);
        SavingsInterestRunLine::factory()->create(['run_id' => $run->id, 'account_id' => $account->id]);

        return $run;
    }

    /** @return list<int> */
    private function alerted(): array
    {
        return DB::table('notifications')->where('type', 'savings_large_transaction')->where('notifiable_type', User::class)
            ->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
    }
}
