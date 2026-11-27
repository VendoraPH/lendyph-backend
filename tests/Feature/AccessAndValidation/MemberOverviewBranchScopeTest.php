<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use App\Services\RepaymentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /borrowers/{id}/loan-summary covers only the loans the caller can see
 * (Loan::visibleTo), the same as the loan pages. A member's loans can sit in
 * more than one branch: the member moved, or a loan was opened elsewhere.
 */
class MemberOverviewBranchScopeTest extends TestCase
{
    use SetupLendyPH;

    private Loan $here;

    private Loan $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->here = $this->createReleasedLoan(['principal_amount' => 60000]);

        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->there = $this->createReleasedLoan(['principal_amount' => 10000]);
        $this->there->update(['borrower_id' => $this->here->borrower_id, 'branch_id' => $far->id]);

        $payments = app(RepaymentService::class);
        $payments->processRepayment($this->here->fresh(), 1000, now()->toDateString(), $this->admin);
        $payments->processRepayment($this->there->fresh(), 700, now()->toDateString(), $this->admin);
    }

    public function test_a_branch_user_gets_no_figures_from_another_branchs_loan(): void
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $this->actingAs($user);

        $data = $this->getJson("/api/borrowers/{$this->here->borrower_id}/loan-summary")->assertOk()->json('data');
        $loanSummary = $this->getJson("/api/loans/{$this->here->id}/summary")->assertOk()->json('data');

        $this->assertEquals(60000, $data['total_borrowed']);
        $this->assertEquals(1000, $data['total_paid']);
        $this->assertEquals($loanSummary['total_outstanding'], $data['total_outstanding']);
        $this->assertEquals($loanSummary['outstanding_principal'], $data['outstanding_principal']);
        $this->assertSame(1, $data['current_loans']);
        $this->assertSame(1, array_sum($data['counts']));
    }

    public function test_a_user_who_sees_every_branch_still_gets_all_of_the_members_loans(): void
    {
        $data = $this->getJson("/api/borrowers/{$this->here->borrower_id}/loan-summary")->assertOk()->json('data');

        $this->assertEquals(70000, $data['total_borrowed']);
        $this->assertEquals(1700, $data['total_paid']);
        $this->assertSame(2, $data['current_loans']);
        $this->assertSame(2, array_sum($data['counts']));
    }
}
