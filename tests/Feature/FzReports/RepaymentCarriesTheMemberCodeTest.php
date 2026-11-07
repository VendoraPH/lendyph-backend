<?php

namespace Tests\Feature\FzReports;

use App\Services\RepaymentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Official Receipt's "Member No." reads the repayment's `borrower_code`
 * (round fz).
 */
class RepaymentCarriesTheMemberCodeTest extends TestCase
{
    use SetupLendyPH;

    public function test_every_repayment_endpoint_sends_the_member_s_code(): void
    {
        $this->seedAndLogin();
        $loan = $this->createReleasedLoan();
        $repayment = app(RepaymentService::class)->processRepayment($loan->fresh(), 1000, now()->toDateString(), $this->admin);
        $code = $loan->borrower->borrower_code;

        $this->assertNotEmpty($code);

        $this->getJson("/api/repayments/{$repayment->id}")->assertOk()->assertJsonPath('data.borrower_code', $code);
        $this->getJson('/api/repayments')->assertOk()->assertJsonPath('data.0.borrower_code', $code);
        $this->getJson("/api/loans/{$loan->id}/repayments")->assertOk()->assertJsonPath('data.0.borrower_code', $code);
    }
}
