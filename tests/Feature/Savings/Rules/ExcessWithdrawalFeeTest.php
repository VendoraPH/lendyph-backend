<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\AccountingAccountMapping;
use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\ProductRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Free withdrawals a month and the excess-withdrawal fee (gap round S1):
 *
 * - the count is posted withdrawals in the row date's Manila month, plus
 *   pending withdrawal approvals and members' open portal requests, less
 *   reversed withdrawals and the approval being carried out;
 * - beyond it, a product with no fee refuses (422), and one with a fee posts
 *   a linked `fee` row (Dr 2050 / Cr 4080) in the same write;
 * - a pending approval reserves its fee, the fee is recomputed at posting
 *   (409 when it changed), and reversing the withdrawal reverses its fee.
 */
class ExcessWithdrawalFeeTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account(['opened_on' => '2026-10-01']);
        $this->fund($this->savings, 10_000_000);
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

    private function withdraw(string $amount, string $date = '2026-11-10')
    {
        return $this->money("/api/savings/accounts/{$this->savings->id}/withdrawals", ['amount' => $amount, 'date' => $date, 'payment_method' => 'cash']);
    }

    private function usedThisMonth(string $date = '2026-11-10'): int
    {
        return app(ProductRules::class)->withdrawalsInMonth($this->savings->refresh(), $date);
    }

    public function test_beyond_the_free_count_a_product_with_no_fee_refuses_in_words(): void
    {
        $this->rules(['free_withdrawals_per_month' => 2]);

        $this->withdraw('100.00')->assertCreated();
        $this->withdraw('100.00')->assertCreated();
        $this->withdraw('100.00')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', "Regular Savings allows 2 free withdrawals a month, and {$this->savings->account_number} has used them for November 2026. The product charges no fee for more, so this withdrawal is refused.");
    }

    public function test_beyond_the_free_count_the_fee_posts_as_a_linked_row_with_its_journal(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00']);

        $first = $this->withdraw('100.00')->assertCreated();
        $this->assertNull($first->json('data.fee_amount'));

        $second = $this->withdraw('100.00')->assertCreated()->assertJsonPath('data.fee_amount', '25.00');
        $withdrawal = SavingsTransaction::query()->findOrFail($second->json('data.id'));
        $fee = SavingsTransaction::query()->where('fee_for_transaction_id', $withdrawal->id)->sole();

        $this->assertSame(['fee', '25.00', '0.00', '99775.00'], [$fee->type, $fee->debit, $fee->credit, $fee->balance_after]);
        $this->assertSame($fee->id, $second->json('data.fee_transaction_id'));
        $this->assertSame([['2050', 2_500, 0], ['4080', 0, 2_500]], $this->journalLines($this->journalFor($fee)));
        $this->assertSame('99775.00', $this->balanceOf($this->savings));
    }

    public function test_the_available_balance_must_cover_the_amount_and_the_fee(): void
    {
        $this->rules(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '25.00']);
        $this->savings = $this->account(['product_id' => $this->product->id, 'opened_on' => '2026-11-01', 'borrower_id' => $this->otherMember()->id]);
        $this->fund($this->savings, 100_000);

        $this->withdraw('975.01')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', "₱1,000.01 is more than the ₱1,000.00 available on {$this->savings->account_number} (balance less holds, requests waiting for approval and the maintaining balance).");
        $this->withdraw('975.00')->assertCreated();
        $this->assertSame('0.00', $this->balanceOf($this->savings));
    }

    public function test_the_count_follows_the_rows_manila_month_and_yesterdays_date(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1]);
        $this->savings = $this->account(['opened_on' => '2026-10-01', 'borrower_id' => $this->otherMember()->id]);
        $this->fund($this->savings, 100_000, '2026-10-30');

        // Nov 1: a withdrawal dated yesterday (Oct 31) counts in October.
        $this->on('2026-11-01', function (): void {
            $this->withdraw('100.00', '2026-10-31')->assertCreated();
            $this->assertSame(1, $this->usedThisMonth('2026-10-31'));
            $this->assertSame(0, $this->usedThisMonth('2026-11-01'));
            $this->withdraw('100.00', '2026-11-01')->assertCreated();
        });

        $this->assertSame(1, $this->usedThisMonth('2026-11-30'));
        $this->assertSame(0, $this->usedThisMonth('2026-12-01'));
    }

    public function test_a_reversed_withdrawal_no_longer_counts(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1]);
        $row = $this->withdraw('100.00')->assertCreated()->json('data.id');
        $this->withdraw('100.00')->assertUnprocessable();

        $approval = $this->money("/api/savings/transactions/{$row}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval.id');
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $this->assertSame(0, $this->usedThisMonth());
    }

    public function test_a_pending_approval_counts_once_and_approving_counts_nothing_more(): void
    {
        $this->rules(['free_withdrawals_per_month' => 3]);
        $this->actingAs($this->teller());
        $approval = $this->withdraw('15000.00')->assertStatus(202)->json('data.approval.id');
        $this->assertSame(1, $this->usedThisMonth());
        $this->assertSame('2026-11-10', SavingsApproval::query()->findOrFail($approval)->counted_on->toDateString());

        // A request counts in the month it was made only (portal requests: PortalRulesTest).
        $this->assertSame(0, $this->usedThisMonth('2026-12-01'));

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();
        $this->assertSame(1, $this->usedThisMonth(), 'The approved withdrawal replaces its request in the count.');
        $posted = SavingsTransaction::query()->where('approval_id', $approval)->where('type', 'withdrawal')->sole();
        $this->assertSame('2026-11-10', $posted->counted_on->toDateString());
    }

    public function test_a_pending_approval_reserves_its_fee_and_posts_it_when_approved(): void
    {
        $this->rules(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '50.00']);
        $this->actingAs($this->teller());

        $approval = $this->withdraw('15000.00')->assertStatus(202)
            ->assertJsonPath('data.approval.fee_amount', '50.00')
            ->json('data.approval.id');
        $this->assertSame('50.00', (string) SavingsApproval::query()->findOrFail($approval)->fee_amount);
        $this->getJson("/api/savings/accounts/{$this->savings->id}")
            ->assertJsonPath('data.pending_amount', '15050.00')
            ->assertJsonPath('data.available_balance', '84950.00');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $withdrawal = SavingsTransaction::query()->where('approval_id', $approval)->where('type', 'withdrawal')->sole();
        $fee = SavingsTransaction::query()->where('fee_for_transaction_id', $withdrawal->id)->sole();
        $this->assertSame(['50.00', $approval], [$fee->debit, $fee->approval_id]);
        $this->assertSame('84950.00', $this->balanceOf($this->savings));
    }

    public function test_the_fee_is_decided_when_requested_so_a_later_withdrawal_never_charges_it_twice(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00']);

        // 10:00 approval A takes the free withdrawal (₱0.00 fee).
        $this->actingAs($this->teller());
        $approval = $this->withdraw('15000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '0.00')->json('data.approval.id');

        // 11:00 direct withdrawal W comes after A, so W pays the fee.
        $this->travelTo(Carbon::parse('2026-11-10 11:00:00', 'Asia/Manila'));
        $this->actingAs($this->admin);
        $this->withdraw('100.00')->assertCreated()->assertJsonPath('data.fee_amount', '25.00');

        // 12:00 the checker approves A: it is charged what it was decided at, ₱0.00.
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'Asia/Manila'));
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $withdrawal = SavingsTransaction::query()->where('approval_id', $approval)->where('type', 'withdrawal')->sole();
        $this->assertFalse(SavingsTransaction::query()->where('fee_for_transaction_id', $withdrawal->id)->exists());
        $this->assertSame(1, SavingsTransaction::query()->where('account_id', $this->savings->id)->where('type', 'fee')->count());
        $this->assertSame('84875.00', $this->balanceOf($this->savings));
    }

    /**
     * Two free withdrawals, one posted; approval A (₱0.00) then approval B
     * (counting A, so ₱25.00). Returns [A, B].
     *
     * @return array{0: int, 1: int}
     */
    private function twoPendingAfterOnePosted(): array
    {
        $this->rules(['free_withdrawals_per_month' => 2, 'excess_withdrawal_fee' => '25.00']);
        $this->withdraw('100.00')->assertCreated();
        $this->actingAs($this->maker = $this->teller());

        $this->travelTo(Carbon::parse('2026-11-10 10:05:00', 'Asia/Manila'));
        $a = $this->withdraw('15000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '0.00')->json('data.approval.id');
        $this->travelTo(Carbon::parse('2026-11-10 10:10:00', 'Asia/Manila'));
        $b = $this->withdraw('16000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '25.00')->json('data.approval.id');

        return [$a, $b];
    }

    private function approvedFee(int $approval): ?string
    {
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();
        $withdrawal = SavingsTransaction::query()->where('approval_id', $approval)->where('type', 'withdrawal')->sole();

        return SavingsTransaction::query()->where('fee_for_transaction_id', $withdrawal->id)->value('debit');
    }

    public function test_a_rejected_earlier_request_lowers_a_later_requests_fee_at_payout(): void
    {
        [$a, $b] = $this->twoPendingAfterOnePosted();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$a}/reject", ['reason' => 'Not needed'])->assertOk();

        $this->assertNull($this->approvedFee($b), 'Only two withdrawals were made, both free.');
        $this->assertSame('83900.00', $this->balanceOf($this->savings));
    }

    public function test_an_expired_earlier_request_lowers_a_later_requests_fee_at_payout(): void
    {
        [$a, $b] = $this->twoPendingAfterOnePosted();
        SavingsApproval::query()->whereKey($a)->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($this->approvedFee($b));
    }

    public function test_an_earlier_withdrawal_reversed_while_a_request_waits_lowers_its_fee(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00']);
        $first = $this->withdraw('100.00')->assertCreated()->json('data.id');
        $this->actingAs($this->teller());
        $this->travelTo(Carbon::parse('2026-11-10 10:05:00', 'Asia/Manila'));
        $b = $this->withdraw('15000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '25.00')->json('data.approval.id');

        $reversal = $this->money("/api/savings/transactions/{$first}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval.id');
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$reversal}/approve")->assertOk();

        $this->assertNull($this->approvedFee($b));
        $this->assertSame('85000.00', $this->balanceOf($this->savings));
    }

    public function test_a_later_request_falling_away_never_raises_or_lowers_an_earlier_ones_fee(): void
    {
        [$a, $b] = $this->twoPendingAfterOnePosted();

        $this->actingAs($this->maker);
        $this->postJson("/api/savings/approvals/{$b}/cancel", ['reason' => 'Changed mind'])->assertOk();

        $this->assertNull($this->approvedFee($a));
    }

    public function test_a_request_decided_within_the_free_count_ignores_a_changed_fee_but_not_a_changed_free_count(): void
    {
        $this->rules(['free_withdrawals_per_month' => 2, 'excess_withdrawal_fee' => '25.00']);
        $this->actingAs($this->teller());
        $first = $this->withdraw('15000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '0.00')->json('data.approval.id');
        $this->travelTo(Carbon::parse('2026-11-10 10:05:00', 'Asia/Manila'));
        $second = $this->withdraw('15000.00')->assertStatus(202)->json('data.approval.id');

        $this->rules(['excess_withdrawal_fee' => '40.00']);
        $this->assertNull($this->approvedFee($first));

        $this->rules(['free_withdrawals_per_month' => 3]);
        $this->postJson("/api/savings/approvals/{$second}/approve")->assertConflict();
    }

    public function test_a_withdrawal_posted_in_the_same_second_still_counts_before_an_approval(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00']);

        // 10:00:00: a counter withdrawal (free), then approval B in the same second (₱25.00).
        $this->withdraw('100.00')->assertCreated()->assertJsonPath('data.fee_amount', null);
        $this->actingAs($this->teller());
        $b = $this->withdraw('15000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '25.00')->json('data.approval.id');

        $this->assertSame('25.00', $this->approvedFee($b), 'The withdrawal in the same second used the free one.');
        $this->assertSame('84875.00', $this->balanceOf($this->savings));
    }

    public function test_a_free_count_or_fee_changed_since_the_request_answers_409_in_words(): void
    {
        $this->rules(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '50.00']);
        $this->actingAs($this->teller());
        $approval = $this->withdraw('15000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '50.00')->json('data.approval.id');

        $this->rules(['excess_withdrawal_fee' => '75.00']);
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertConflict()
            ->assertJsonPath('message', 'Regular Savings\'s free withdrawals or excess-withdrawal fee changed since this request was made (it reserved a ₱50.00 fee). Reject it and make a new request.');
        $this->assertFalse(SavingsTransaction::query()->where('approval_id', $approval)->exists());

        // The same rule as when requested posts the reserved fee.
        $this->rules(['excess_withdrawal_fee' => '50.00']);
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();
        $this->assertSame('84950.00', $this->balanceOf($this->savings));
    }

    public function test_a_reversal_of_a_withdrawal_and_its_fee_is_tiered_on_both_and_shows_the_fee(): void
    {
        $this->rules(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '25.00']);
        $this->actingAs($this->teller());
        $request = $this->withdraw('49990.00')->assertStatus(202)->json('data.approval.id');
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$request}/approve")->assertOk();
        $withdrawal = SavingsTransaction::query()->where('approval_id', $request)->where('type', 'withdrawal')->sole();

        // ₱49,990.00 plus the ₱25.00 fee is ₱50,015.00: above tier 2, so two checkers.
        $this->actingAs($this->teller());
        $this->money("/api/savings/transactions/{$withdrawal->id}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)
            ->assertJsonPath('data.approval.amount', '49990.00')
            ->assertJsonPath('data.approval.fee_amount', '25.00')
            ->assertJsonPath('data.approval.required_checkers', 2);
    }

    public function test_the_gl_control_reads_nil_after_a_fee_and_after_reversing_it(): void
    {
        $this->rules(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '25.00']);
        $withdrawal = SavingsTransaction::query()->findOrFail($this->withdraw('100.00')->assertCreated()->json('data.id'));

        $this->getJson('/api/reports/savings/gl-control')->assertOk()
            ->assertJsonPath('data.totals.difference', '0.00')
            ->assertJsonPath('data.totals.subledger_balance', '99875.00');

        $approval = $this->money("/api/savings/transactions/{$withdrawal->id}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval.id');
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $this->actingAs($this->admin);
        $this->getJson('/api/reports/savings/gl-control')->assertOk()
            ->assertJsonPath('data.totals.difference', '0.00')
            ->assertJsonPath('data.totals.subledger_balance', '100000.00');
    }

    public function test_reversing_a_withdrawal_reverses_its_fee_and_the_fee_alone_is_refused(): void
    {
        $this->rules(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '25.00']);
        $withdrawal = SavingsTransaction::query()->findOrFail($this->withdraw('100.00')->assertCreated()->json('data.id'));
        $fee = SavingsTransaction::query()->where('fee_for_transaction_id', $withdrawal->id)->sole();

        $this->money("/api/savings/transactions/{$fee->id}/reverse", ['reason' => 'Wrong'])->assertUnprocessable()
            ->assertJsonPath('errors.transaction.0', 'An excess-withdrawal fee is reversed only with its withdrawal. Reverse the withdrawal instead.');

        $approval = $this->money("/api/savings/transactions/{$withdrawal->id}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval.id');
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $this->assertSame('100000.00', $this->balanceOf($this->savings));
        $this->assertSame(2, SavingsTransaction::query()->whereIn('reverses_transaction_id', [$withdrawal->id, $fee->id])->count());
        $this->assertSame('reversed', $this->journalFor($fee)->status);
        $this->assertSame('reversed', $this->journalFor($withdrawal)->status);
    }

    private function otherMember(): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
    }

    public function test_a_product_with_a_fee_cant_be_turned_on_while_other_fee_income_is_unmapped(): void
    {
        DB::table('accounting_account_mappings')->where('role', 'other_fee_income')->delete();
        $this->product->forceFill(['is_active' => false, 'excess_withdrawal_fee' => '25.00', 'free_withdrawals_per_month' => 2])->save();

        $this->putJson("/api/savings/products/{$this->product->id}", ['is_active' => true])->assertUnprocessable()
            ->assertJsonPath('errors.is_active.0', 'The product can\'t be turned on yet: the '.AccountingAccountMapping::labelList(['other_fee_income'])
                .' posting role has no account. Set up savings in the books from Savings Settings first.');

        $this->product->forceFill(['excess_withdrawal_fee' => null])->save();
        $this->putJson("/api/savings/products/{$this->product->id}", ['is_active' => true])->assertOk();
    }
}
