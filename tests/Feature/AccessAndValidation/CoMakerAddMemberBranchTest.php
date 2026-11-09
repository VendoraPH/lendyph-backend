<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /loans/{loan}/co-makers answers with the co-maker by the round fz
 * personal-data rule: the whole record needs `borrowers:view` AND the member's
 * own branch. A loan in the caller's branch whose member has moved to another
 * branch proves neither, so the caller gets only the co-maker's name, code and
 * relationship.
 */
class CoMakerAddMemberBranchTest extends TestCase
{
    use SetupLendyPH;

    private const CO_MAKER_PERSONAL_FIELDS = ['address', 'contact_number', 'occupation', 'employer', 'monthly_income'];

    private Branch $far;

    private Loan $loan;

    private CoMaker $coMaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        $loanService = app(LoanService::class);
        $this->loan = $loanService->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loanService->submitForReview($this->loan);
        $loanService->approve($this->loan, $this->admin, 'Approved for testing');
        $this->loan->refresh();

        $this->coMaker = CoMaker::factory()->create([
            'borrower_id' => $this->loan->borrower_id,
            'address' => '77 Co-maker Lane',
            'contact_number' => '09173330000',
            'occupation' => 'Teacher',
            'employer' => 'DepEd Lihim',
            'monthly_income' => 31987.65,
            'relationship_to_borrower' => 'Sister',
        ]);
    }

    private function releaserInTheLoansBranch(): User
    {
        $role = Role::create(['name' => 'probe_'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions(['loans:view', 'loans:release', 'borrowers:view']);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_a_member_in_another_branch_gets_only_the_co_makers_name_code_and_relationship(): void
    {
        Borrower::whereKey($this->loan->borrower_id)->update(['branch_id' => $this->far->id]);

        $this->actingAs($this->releaserInTheLoansBranch());

        $response = $this->postJson("/api/loans/{$this->loan->id}/co-makers", ['co_maker_id' => $this->coMaker->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $this->coMaker->id)
            ->assertJsonPath('data.co_maker_code', $this->coMaker->co_maker_code)
            ->assertJsonPath('data.full_name', $this->coMaker->full_name)
            ->assertJsonPath('data.relationship_to_borrower', 'Sister');

        foreach (self::CO_MAKER_PERSONAL_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data'));
        }

        foreach (['77 Co-maker Lane', '09173330000', 'Teacher', 'DepEd Lihim', '31987.65'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }

        $this->assertSame([$this->coMaker->id], $this->loan->coMakers()->pluck('co_makers.id')->all());
    }

    public function test_a_member_in_the_callers_branch_still_gets_the_whole_co_maker(): void
    {
        $this->actingAs($this->releaserInTheLoansBranch());

        $this->postJson("/api/loans/{$this->loan->id}/co-makers", ['co_maker_id' => $this->coMaker->id])
            ->assertCreated()
            ->assertJsonPath('data.address', '77 Co-maker Lane')
            ->assertJsonPath('data.contact_number', '09173330000')
            ->assertJsonPath('data.employer', 'DepEd Lihim');
    }
}
