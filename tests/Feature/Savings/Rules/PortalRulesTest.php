<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\Branch;
use App\Models\PortalRequest;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Services\Savings\ProductRules;
use Illuminate\Support\Carbon;
use Tests\Feature\Portal\Requests\PortalRequestsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The product rules in the Member Portal (gap round S1): a new-account
 * request follows the product's eligibility (and the portal lists only the
 * products the member may ask for), and a withdrawal request follows the
 * withdrawal rules and the monthly free count, its fee included in what must
 * be available.
 */
class PortalRulesTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    public function test_a_new_account_request_follows_the_products_eligibility(): void
    {
        $this->product->forceFill(['portal_account_requests' => true, 'eligible_branch_ids' => [Branch::factory()->create()->id]])->save();
        $body = ['type' => PortalRequest::TYPE_SAVINGS_OPENING, 'details' => ['product_id' => $this->product->id, 'initial_deposit' => '500.00', 'terms_accepted' => true]];

        $this->member('GET', '/api/portal/requests/options')->assertOk()->assertJsonCount(0, 'data.opening_products');
        $refused = $this->submit($body)->assertUnprocessable();
        $this->assertSame("Regular Savings is not offered in {$this->branch->name}.", $refused->json('errors')['details.product_id'][0]);

        $this->product->forceFill(['eligible_branch_ids' => null])->save();
        $this->member('GET', '/api/portal/requests/options')->assertOk()->assertJsonPath('data.opening_products.0.id', $this->product->id);
        $this->submit($body)->assertCreated();
    }

    public function test_a_withdrawal_request_follows_the_withdrawal_rules_and_the_free_count(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->product->forceFill(['max_withdrawal' => '1000.00', 'free_withdrawals_per_month' => 1])->save();

        $refused = $this->submit($this->withdrawalBody($account, '1000.01'))->assertUnprocessable();
        $this->assertSame('A withdrawal from Regular Savings is at most ₱1,000.00.', $refused->json('errors')['details.amount'][0]);

        $this->freshRequest();
        $this->actingAs($this->admin);
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated();
        $this->submit($this->withdrawalBody($account, '100.00'))->assertUnprocessable()->assertJsonValidationErrors('details.amount');

        $this->product->forceFill(['excess_withdrawal_fee' => '25.00'])->save();
        // ₱4,900.00 left: the request plus its ₱25.00 fee must fit.
        $this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated();
    }

    public function test_approving_a_portal_request_does_not_count_the_request_twice(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->product->forceFill(['free_withdrawals_per_month' => 1])->save();

        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_the_fee_is_shown_before_submitting_held_with_the_amount_and_stored_on_the_request(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->product->forceFill(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '25.00'])->save();

        $this->member('GET', '/api/portal/requests/options')->assertOk()
            ->assertJsonPath('data.withdrawal_accounts.0.next_withdrawal_fee', '25.00')
            ->assertJsonPath('data.withdrawal_accounts.0.free_withdrawals_left', 0);

        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated()
            ->assertJsonPath('data.details.fee_amount', '25.00'));

        $this->assertSame(['25.00', '2026-11-10', 0, '25.00'], [(string) $request->fee_amount, $request->counted_on->toDateString(), $request->rule_free_withdrawals, (string) $request->rule_excess_fee]);
        $this->assertSame('1025.00', (string) SavingsHold::query()->findOrFail($request->savings_hold_id)->amount);

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();
        $this->assertSame('3975.00', $this->balanceOf($account));
    }

    public function test_a_request_keeps_the_fee_decided_when_it_was_made(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->product->forceFill(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00'])->save();

        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->assertSame('0.00', (string) $request->fee_amount);

        // A counter withdrawal after the request pays the fee; the request still doesn't.
        $this->freshRequest();
        $this->actingAs($this->admin);
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.fee_amount', '25.00');

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();
        $this->assertSame(1, SavingsTransaction::query()->where('account_id', $account->id)->where('type', 'fee')->count());
        $this->assertSame('4775.00', $this->balanceOf($account));
    }

    public function test_a_request_made_on_nov_30_and_paid_on_dec_1_counts_in_november_only(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->product->forceFill(['free_withdrawals_per_month' => 1])->save();

        $this->travelTo(Carbon::parse('2026-11-30 10:00:00', 'Asia/Manila'));
        $this->token = $this->portalToken($this->portalUser);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->travelTo(Carbon::parse('2026-12-01 10:00:00', 'Asia/Manila'));
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout(['date' => '2026-12-01'])])->assertCreated();

        $rules = app(ProductRules::class);
        $this->assertSame(1, $rules->withdrawalsInMonth($account->refresh(), '2026-11-30'));
        $this->assertSame(0, $rules->withdrawalsInMonth($account, '2026-12-01'));
        $paid = SavingsTransaction::query()->where('account_id', $account->id)->where('type', 'withdrawal')->sole();
        $this->assertSame(['2026-12-01', '2026-11-30'], [$paid->date->toDateString(), $paid->counted_on->toDateString()]);

        // December's free withdrawal is still there.
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '100.00', 'date' => '2026-12-01', 'payment_method' => 'cash'])->assertCreated();
    }

    public function test_a_cancelled_earlier_portal_request_lowers_a_later_approvals_fee_at_payout(): void
    {
        $account = $this->account();
        $this->fund($account, 5_000_000);
        $this->product->forceFill(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00'])->save();

        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->assertSame('2026-11-10', $request->counted_on->toDateString());

        $this->travelTo(Carbon::parse('2026-11-10 10:05:00', 'Asia/Manila'));
        $this->freshRequest();
        $this->actingAs($maker = $this->userWith(['savings:view', 'savings:transact']));
        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '15000.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])
            ->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '25.00')->json('data.approval.id');

        $this->member('POST', "/api/portal/requests/{$request->id}/cancel")->assertOk();

        $this->freshRequest();
        $this->actingAs($this->userWith(['savings:view', 'savings:approve']));
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();
        $this->assertSame(0, SavingsTransaction::query()->where('account_id', $account->id)->where('type', 'fee')->count());
        $this->assertSame('35000.00', $this->balanceOf($account));
    }

    public function test_open_requests_count_by_the_requests_own_date_not_the_holds(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->product->forceFill(['free_withdrawals_per_month' => 1])->save();

        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        SavingsHold::query()->whereKey($request->savings_hold_id)->update(['placed_on' => '2026-12-01']);

        $rules = app(ProductRules::class);
        $this->assertSame(1, $rules->withdrawalsInMonth($account->refresh(), '2026-11-10'));
        $this->assertSame(0, $rules->withdrawalsInMonth($account, '2026-12-01'));
    }

    public function test_an_approval_in_the_same_second_still_counts_before_a_portal_request(): void
    {
        $account = $this->account();
        $this->fund($account, 5_000_000);
        $this->product->forceFill(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00'])->save();

        // 10:00:00: approval A takes the free withdrawal, then portal request P in the same second.
        $this->freshRequest();
        $this->actingAs($this->userWith(['savings:view', 'savings:transact']));
        $approval = $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '15000.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])
            ->assertStatus(202)->assertJsonPath('data.approval.fee_amount', '0.00')->json('data.approval.id');
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->assertSame('25.00', (string) $request->fee_amount);

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();
        $paid = SavingsTransaction::query()->findOrFail($request->refresh()->savings_transaction_id);
        $this->assertSame('25.00', (string) SavingsTransaction::query()->where('fee_for_transaction_id', $paid->id)->value('debit'));

        $this->freshRequest();
        $this->actingAs($this->userWith(['savings:view', 'savings:approve']));
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();
        $this->assertSame(1, SavingsTransaction::query()->where('account_id', $account->id)->where('type', 'fee')->count());
        $this->assertSame('34875.00', $this->balanceOf($account));
    }
}
