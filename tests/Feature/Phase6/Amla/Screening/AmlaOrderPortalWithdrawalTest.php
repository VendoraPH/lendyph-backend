<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Models\SavingsHold;
use App\Services\Amla\Screening\Orders;
use Tests\Feature\Portal\Requests\PortalRequestsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The review's High (AMLA-B5): an order recorded over money a member's portal
 * withdrawal request already holds must still hold it when staff approve the
 * request, which releases the request's hold and pays out through the teller.
 * Order holds stack on top of every other hold.
 */
class AmlaOrderPortalWithdrawalTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    public function test_approving_a_portal_withdrawal_never_pays_out_money_the_order_holds(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '10000.00'))->assertCreated());

        app(Orders::class)->place($this->admin, [
            'reference' => 'AMLC Freeze Order 9', 'authority' => 'amlc', 'received_on' => now()->toDateString(),
            'borrower_id' => $this->member->id, 'required_action' => 'Freeze all funds.',
            'holds' => [['account_id' => $account->id, 'amount' => '20000.00']],
        ]);

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertUnprocessable();

        $this->assertSame('20000.00', $this->balanceOf($account));
        $this->assertSame(1, SavingsHold::query()->where('type', 'legal')->where('status', 'active')->count());
    }
}
