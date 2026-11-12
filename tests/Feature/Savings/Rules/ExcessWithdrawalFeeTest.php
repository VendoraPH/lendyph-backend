<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\AccountingAccountMapping;
use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
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

    private function usedThisMonth(string $date = '2026-11-10', ?int $except = null): int
    {
        return app(ProductRules::class)->withdrawalsInMonth($this->savings->refresh(), $date, $except);
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

    public function test_pending_approvals_and_portal_requests_count_and_the_executing_approval_does_not(): void
    {
        $this->rules(['free_withdrawals_per_month' => 3]);
        $this->actingAs($this->teller());
        $approval = $this->withdraw('15000.00')->assertStatus(202)->json('data.approval.id');
        $this->assertSame(1, $this->usedThisMonth());
        $this->assertSame(0, $this->usedThisMonth('2026-11-10', $approval));

        SavingsHold::query()->create([
            'account_id' => $this->savings->id, 'type' => SavingsHold::TYPE_MEMBER_REQUEST, 'amount' => '100.00',
            'reason' => 'Member portal request', 'placed_on' => '2026-11-10', 'expires_on' => '2026-11-17', 'status' => 'active',
        ]);
        $this->assertSame(2, $this->usedThisMonth());
        // A request placed in another month counts in that month only.
        $this->assertSame(0, $this->usedThisMonth('2026-12-01'));
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

    public function test_a_fee_that_changed_since_the_request_answers_409_in_words(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '50.00']);
        $this->actingAs($this->teller());
        $approval = $this->withdraw('15000.00')->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '0.00')->json('data.approval.id');

        // Another withdrawal uses the free one first, so this one now carries the fee.
        $this->actingAs($this->admin);
        $this->withdraw('100.00')->assertCreated();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertConflict()
            ->assertJsonPath('message', 'The excess-withdrawal fee on this request is now ₱50.00, not the ₱0.00 it reserved. Reject it and make a new request.');
        $this->assertFalse(SavingsTransaction::query()->where('approval_id', $approval)->exists());
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
