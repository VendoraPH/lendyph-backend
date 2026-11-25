<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AuditLog;
use App\Models\SavingsTransaction;
use Tests\Feature\Portal\Requests\PortalRequestsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6: a Member Portal withdrawal posts through the Teller as
 * an ordinary `withdrawal` row (WithdrawalRequests::approve()), so a GCash or
 * bank payout staff sent before its reference existed takes the reference
 * afterwards, as a counter withdrawal does (savings:transact, the decision's
 * own savings permission).
 */
class PortalPayoutReferenceTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    public function test_a_portal_withdrawal_paid_by_gcash_gets_its_reference_afterwards(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout(['method' => 'gcash'])])->assertCreated();

        $row = SavingsTransaction::query()->where('type', 'withdrawal')->sole();
        $this->assertSame(['gcash', null], [$row->method, $row->external_reference]);

        $this->freshRequest();
        $this->actingAs($this->userWith(['savings:view', 'savings:transact']));
        $this->getJson('/api/savings/payouts/missing-reference')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $row->id);
        $this->postJson("/api/savings/transactions/{$row->id}/reference", ['reference' => 'GC-PORTAL-1'])
            ->assertOk()
            ->assertJsonPath('data.external_reference', 'GC-PORTAL-1');

        $this->assertSame('GC-PORTAL-1', $row->refresh()->external_reference);
        $this->assertSame(1, AuditLog::query()->where('action', 'savings_payout_reference_recorded')->count());
        $this->getJson('/api/savings/payouts/missing-reference')->assertJsonPath('meta.total', 0);
    }
}
