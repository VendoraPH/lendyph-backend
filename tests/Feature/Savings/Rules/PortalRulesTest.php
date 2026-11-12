<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\Branch;
use App\Models\PortalRequest;
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
}
