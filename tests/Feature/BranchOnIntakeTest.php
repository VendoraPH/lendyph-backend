<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 FX-1: every member and loan that comes in gets a branch the caller
 * can see.
 *
 * D7: a public registration may arrive with no branch. Approving it without
 * one left a member no branch user could see, and a loan for such a member
 * copied the null into the NOT NULL `loans.branch_id` (a 500). D8: a CSV
 * import's branch was a plain `exists` check, so a branch user could import
 * into another branch.
 */
class BranchOnIntakeTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    // D7: registration approval ---------------------------------------------

    public function test_approving_a_registration_with_no_branch_needs_one_and_says_so(): void
    {
        $applicant = $this->pendingApplicant(null);

        $this->patchJson("/api/borrowers/{$applicant->id}/approve-registration")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => 'Choose the branch this member belongs to before approving the registration.']);

        $this->assertSame('pending', $applicant->fresh()->status);
        $this->assertNull($applicant->fresh()->branch_id);
    }

    public function test_approving_a_registration_assigns_the_chosen_branch(): void
    {
        $applicant = $this->pendingApplicant(null);

        $this->patchJson("/api/borrowers/{$applicant->id}/approve-registration", ['branch_id' => $this->far->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.branch_id', $this->far->id);

        $this->assertSame($this->far->id, $applicant->fresh()->branch_id);
    }

    public function test_a_registration_that_already_has_a_branch_is_approved_there(): void
    {
        $applicant = $this->pendingApplicant($this->branch);

        $this->patchJson("/api/borrowers/{$applicant->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->id);

        $this->patchJson("/api/borrowers/{$this->pendingApplicant($this->branch)->id}/approve-registration", ['branch_id' => $this->branch->id])
            ->assertOk();
    }

    public function test_approval_never_moves_a_registration_to_another_branch(): void
    {
        $applicant = $this->pendingApplicant($this->branch);

        $this->patchJson("/api/borrowers/{$applicant->id}/approve-registration", ['branch_id' => $this->far->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);

        $this->assertSame('pending', $applicant->fresh()->status);
        $this->assertSame($this->branch->id, $applicant->fresh()->branch_id);
    }

    public function test_a_branch_user_can_only_approve_into_their_own_branches(): void
    {
        $reviewer = $this->branchUser(['borrowers:approve']);
        $this->actingAs($reviewer);

        // A registration with no branch is visible to branches:view_all only.
        $this->patchJson("/api/borrowers/{$this->pendingApplicant(null)->id}/approve-registration", ['branch_id' => $this->branch->id])
            ->assertNotFound();

        $mine = $this->pendingApplicant($this->branch);
        $this->patchJson("/api/borrowers/{$mine->id}/approve-registration", ['branch_id' => $this->far->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);
        $this->assertSame('pending', $mine->fresh()->status);

        $this->patchJson("/api/borrowers/{$mine->id}/approve-registration", ['branch_id' => $this->branch->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    // D7: a loan for a member with no branch -------------------------------

    public function test_a_loan_for_a_member_with_no_branch_answers_422_not_500(): void
    {
        $member = Borrower::factory()->create(['branch_id' => null, 'status' => 'pending']);

        $this->postJson('/api/loans', $this->loanPayload($member))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['borrower_id' => 'This member has no branch yet. Set the member\'s branch before creating a loan.']);

        $this->assertSame(0, Loan::where('borrower_id', $member->id)->count());
    }

    public function test_a_loan_for_a_member_with_a_branch_is_still_created_there(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/loans', $this->loanPayload($member))->assertCreated();

        $this->assertSame($this->branch->id, Loan::where('borrower_id', $member->id)->sole()->branch_id);
    }

    public function test_another_branchs_former_member_reads_only_as_an_invalid_id(): void
    {
        $gone = Borrower::factory()->create([
            'branch_id' => $this->far->id,
            'first_name' => 'Zenaida', 'last_name' => 'Quisumbing',
            'status' => 'withdrawn', 'membership_ended_on' => '2026-10-01',
        ]);
        $mine = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $source = $this->createReleasedLoan();
        $this->actingAs($this->branchUser(['loans:create', 'loans:restructure']));

        $responses = [
            'borrower_id' => $this->postJson('/api/loans', $this->loanPayload($gone)),
            'co_maker_ids.0' => $this->postJson('/api/loans', [...$this->loanPayload($mine), 'co_maker_ids' => [$gone->id]]),
            'restructure borrower_id' => $this->postJson("/api/loans/{$source->id}/restructure", ['borrower_id' => $gone->id]),
        ];

        foreach ($responses as $field => $response) {
            $key = str_replace('restructure ', '', $field);
            $response->assertUnprocessable()->assertJsonValidationErrors([$key]);
            $this->assertCount(1, $response->json('errors')[$key], $field);
            foreach (['Zenaida', 'Quisumbing', $gone->borrower_code, 'Oct 1, 2026', 'no branch'] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent(), "{$field} leaked {$leak}");
            }
        }
    }

    public function test_a_member_id_written_another_way_answers_422_never_500(): void
    {
        $member = Borrower::factory()->create(['branch_id' => null, 'status' => 'pending']);

        foreach (["{$member->id}.0", (float) $member->id, " {$member->id}"] as $id) {
            $this->postJson('/api/loans', [...$this->loanPayload($member), 'borrower_id' => $id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['borrower_id']);
        }

        $this->assertSame(0, Loan::where('borrower_id', $member->id)->count());
    }

    // Reactivate is not a way round registration review -----------------------

    public function test_reactivate_refuses_a_pending_or_rejected_registration(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $applicant = Borrower::factory()->create(['branch_id' => null, 'status' => $status]);

            $this->patchJson("/api/borrowers/{$applicant->id}/reactivate")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status' => 'This registration hasn\'t been approved. Approve it from Registration Review.']);

            $this->assertSame($status, $applicant->fresh()->status);
            $this->assertNull($applicant->fresh()->approved_at);
        }
    }

    public function test_reactivate_still_turns_an_inactive_or_blacklisted_member_active(): void
    {
        foreach (['inactive', 'blacklisted'] as $status) {
            $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => $status]);

            $this->patchJson("/api/borrowers/{$member->id}/reactivate")->assertOk();

            $this->assertSame('active', $member->fresh()->status);
        }
    }

    // D8: CSV import branch --------------------------------------------------

    public function test_a_csv_import_goes_only_into_one_of_the_callers_branches(): void
    {
        $role = Role::create(['name' => 'importer_fxa', 'guard_name' => 'web']);
        $role->syncPermissions(['imports:process']);
        $importer = User::factory()->inBranches($this->branch)->create();
        $importer->assignRole($role);
        $this->actingAs($importer);

        $this->postJson('/api/imports', $this->importPayload($this->far))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => 'Choose one of your own branches for this import.']);
        $this->assertSame(0, CsvImportRun::count());

        $this->postJson('/api/imports', $this->importPayload($this->branch))->assertCreated();
        $this->assertSame($this->branch->id, CsvImportRun::sole()->branch_id);
    }

    public function test_view_all_may_import_into_any_branch(): void
    {
        $this->assertNull(app(BranchAccess::class)->for($this->admin));

        $this->postJson('/api/imports', $this->importPayload($this->far))->assertCreated();
        $this->assertSame($this->far->id, CsvImportRun::sole()->branch_id);
    }

    // helpers ----------------------------------------------------------------

    private function pendingApplicant(?Branch $branch): Borrower
    {
        $applicant = Borrower::factory()->create(['branch_id' => $branch?->id, 'status' => 'pending']);
        $applicant->documents()->create([
            'type' => 'valid_id',
            'label' => 'umid',
            'file_path' => 'documents/valid_id/test.jpg',
            'original_filename' => 'test.jpg',
        ]);

        return $applicant;
    }

    /** @param  list<string>  $extra */
    private function branchUser(array $extra = []): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $user->givePermissionTo($extra);

        return $user;
    }

    /** @return array<string, mixed> */
    private function loanPayload(Borrower $member): array
    {
        return [
            'borrower_id' => $member->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 20000,
            'start_date' => now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function importPayload(Branch $branch): array
    {
        return [
            'branch_id' => $branch->id,
            'files' => [
                'customers' => ['filename' => 'members.csv', 'size_bytes' => 1, 'sha256' => hash('sha256', 'a')],
                'loans' => ['filename' => 'loans.csv', 'size_bytes' => 1, 'sha256' => hash('sha256', 'b')],
            ],
        ];
    }
}
