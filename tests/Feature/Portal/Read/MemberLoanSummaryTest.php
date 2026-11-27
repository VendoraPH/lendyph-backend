<?php

namespace Tests\Feature\Portal\Read;

use App\Models\Branch;
use App\Models\Loan;
use App\Services\BorrowerLoanSummaryService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * BorrowerLoanSummaryService::forMember(): the Member Overview's loan totals
 * for the member themself (portal design §9, P2). A member sees all of their
 * own loans, whichever branch holds each one, so the figures equal the staff
 * summary for a caller who sees every branch.
 */
class MemberLoanSummaryTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    public function test_for_member_equals_the_staff_summary_over_every_branch(): void
    {
        $this->seedAndLogin();
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $elsewhere = $this->releasedLoanFor($me, 1, 15000);
        Loan::query()->whereKey($elsewhere->id)->update(['branch_id' => Branch::factory()->create()->id]);
        $this->pay($loan, 5000);
        $this->draftLoanFor($me);

        $service = app(BorrowerLoanSummaryService::class);
        $forMember = $service->forMember($me->borrower);

        $this->assertSame($service->summarize($me->borrower, $this->admin), $forMember);
        $this->assertSame(2, $forMember['current_loans']);
        $this->assertSame(1, $forMember['counts']['draft']);
        $this->assertGreaterThan(0, $forMember['total_outstanding']);
    }
}
