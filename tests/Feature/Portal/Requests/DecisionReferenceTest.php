<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 5: approving a new account whose opening deposit came in
 * by bank transfer, GCash or Maya needs the deposit's reference (422 in
 * words, on the field), since money coming in has its reference before it is
 * recorded. Cash needs none, nor does an opening with nothing to deposit. A
 * withdrawal's payout doesn't: its reference exists only once the money is
 * sent (after a second approval above tier 1), so it stays optional, and is
 * kept when given.
 */
class DecisionReferenceTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    public function test_a_non_cash_payout_needs_no_reference(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);

        foreach (['bank_transfer', 'gcash', 'maya'] as $method) {
            $request = $this->submitted($this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated());

            $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout(['method' => $method, 'reference' => ' '])])
                ->assertCreated()
                ->assertJsonPath('data.status', 'completed');

            $this->assertSame(SavingsHold::STATUS_RELEASED, SavingsHold::query()->findOrFail($request->refresh()->savings_hold_id)->status);
        }

        $this->assertSame([null, null, null], SavingsTransaction::query()->where('type', 'withdrawal')->orderBy('id')->pluck('external_reference')->all());
    }

    public function test_with_its_reference_a_non_cash_payout_posts_with_it(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout(['method' => 'gcash', 'reference' => 'GC-PAY-1'])])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(['gcash', 'GC-PAY-1'], [
            SavingsTransaction::query()->where('type', 'withdrawal')->sole()->method,
            SavingsTransaction::query()->where('type', 'withdrawal')->sole()->external_reference,
        ]);
    }

    public function test_a_payout_above_tier_one_waits_for_its_checker_without_a_reference(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());

        // The money isn't sent before the second approval, so there is no reference to give yet.
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout(['method' => 'bank_transfer'])])->assertStatus(202);

        $this->assertSame(['bank_transfer', null], [SavingsApproval::query()->sole()->payload['payment_method'], SavingsApproval::query()->sole()->payload['reference']]);
    }

    public function test_a_payout_reference_given_is_checked_for_duplicates(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $this->money("/api/savings/accounts/{$account->id}/deposits", ['amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'gcash', 'reference' => 'GC-1'])->assertCreated();
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout(['method' => 'gcash', 'reference' => 'GC-1'])])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "GC-1" is already on record for another GCash transaction. Check the reference and enter the right one.');
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->refresh()->status);
    }

    public function test_a_cash_payout_needs_no_reference(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_decision_other_than_approve_takes_no_payout(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated());

        $this->decide($request, ['decision' => 'reject', 'message' => 'Please visit the branch.'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_a_new_accounts_non_cash_opening_deposit_needs_its_reference(): void
    {
        $request = $this->openingRequest('500.00');

        $this->decide($request, ['decision' => 'approve', 'deposit' => ['date' => '2026-11-10', 'method' => 'gcash']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['deposit.reference' => ['Enter the GCash reference for the opening deposit.']]);
        $this->assertSame(0, SavingsAccount::query()->count());

        $this->decide($request, ['decision' => 'approve', 'deposit' => ['date' => '2026-11-10', 'method' => 'gcash', 'reference' => 'GC-OPEN-1']])->assertCreated();
        $this->assertSame('GC-OPEN-1', SavingsTransaction::query()->sole()->external_reference);
    }

    public function test_a_new_account_paid_in_cash_or_with_nothing_to_deposit_needs_no_reference(): void
    {
        $cash = $this->openingRequest('500.00');
        $this->decide($cash, ['decision' => 'approve', 'deposit' => ['date' => '2026-11-10', 'method' => 'cash']])->assertCreated();

        // ₱0.00 moves no money, whatever method the form still shows.
        $nothing = $this->openingRequest('0.00', SavingsProduct::factory()->create(['minimum_opening_balance' => '0.00']));
        $this->decide($nothing, ['decision' => 'approve', 'deposit' => ['date' => '2026-11-10', 'method' => 'bank_transfer']])->assertCreated();
    }

    private function openingRequest(string $deposit, ?SavingsProduct $product = null): PortalRequest
    {
        $product ??= $this->product;
        $product->forceFill(['portal_account_requests' => true])->save();

        return $this->submitted($this->submit([
            'type' => PortalRequest::TYPE_SAVINGS_OPENING,
            'details' => ['product_id' => $product->id, 'initial_deposit' => $deposit, 'terms_accepted' => true],
        ])->assertCreated());
    }
}
