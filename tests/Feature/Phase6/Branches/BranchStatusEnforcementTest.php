<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Branches\BranchWriteGuard;
use App\Services\RepaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE2 (multi-branch decision 2): a suspended branch takes no new
 * members, loans, releases or imports, and a closed one nothing new at all;
 * existing rows in a closed branch can't be changed in ways that reopen them
 * (a payment void). Refusals are 422 in words, naming the branch.
 */
class BranchStatusEnforcementTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $town;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->town = Branch::factory()->create(['name' => 'Town Branch', 'code' => 'TWN']);
    }

    // New members -------------------------------------------------------

    public function test_a_suspended_or_closed_branch_takes_no_new_member(): void
    {
        foreach (['suspended' => 'is suspended', 'closed' => 'is closed'] as $status => $words) {
            $this->setStatus($status);

            $this->postJson('/api/borrowers', $this->memberPayload())
                ->assertUnprocessable()
                ->assertJsonPath('errors.branch_id.0', "Branch \"Town Branch\" (TWN) {$words}, so no new members can be added there.");
        }

        $this->setStatus('active');
        $this->postJson('/api/borrowers', $this->memberPayload())->assertCreated();
    }

    public function test_a_public_registration_cannot_pick_a_suspended_branch(): void
    {
        $this->setStatus('suspended');
        auth()->forgetGuards();

        $this->postJson('/api/borrowers', [...$this->memberPayload(), 'status' => 'pending'])
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_a_member_is_not_moved_into_a_suspended_branch_but_keeps_their_own(): void
    {
        $member = $this->member($this->branch);
        $this->setStatus('suspended');

        $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->town->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Town Branch" (TWN) is suspended, so no new members can be added there.');

        $inTown = $this->member($this->town);
        $this->putJson("/api/borrowers/{$inTown->id}", ['branch_id' => $this->town->id, 'occupation' => 'Teacher'])->assertOk();
    }

    public function test_a_registration_is_not_approved_into_a_suspended_branch(): void
    {
        $this->setStatus('suspended');

        $this->patchJson("/api/borrowers/{$this->applicant(null)->id}/approve-registration", ['branch_id' => $this->town->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Town Branch" (TWN) is suspended, so no new members can be added there.');

        // A registration that already names the suspended branch waits too.
        $this->patchJson("/api/borrowers/{$this->applicant($this->town)->id}/approve-registration")
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Town Branch" (TWN) is suspended, so no new members can be added there.');
    }

    public function test_a_csv_import_does_not_go_into_a_suspended_branch(): void
    {
        $this->setStatus('suspended');

        $this->postJson('/api/imports', $this->importPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Town Branch" (TWN) is suspended, so no members or loans can be imported into it.');
    }

    // Loans -------------------------------------------------------------

    public function test_a_suspended_branch_takes_no_new_loan_or_restructure(): void
    {
        $member = $this->member($this->town);
        $source = Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->town->id, 'status' => 'ongoing']);
        $this->setStatus('suspended');
        $message = 'Branch "Town Branch" (TWN) is suspended, so no new loans can be made there.';

        $this->postJson('/api/loans', $this->loanPayload($member))
            ->assertUnprocessable()
            ->assertJsonPath('errors.borrower_id.0', $message);

        $this->postJson("/api/loans/{$source->id}/restructure", ['borrower_id' => $member->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.borrower_id.0', $message);
    }

    public function test_a_hidden_members_branch_is_never_named(): void
    {
        $member = $this->member($this->town);
        $this->setStatus('suspended');
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $this->actingAs($user);

        $response = $this->postJson('/api/loans', $this->loanPayload($member))->assertUnprocessable();

        $this->assertStringNotContainsString('Town Branch', (string) $response->getContent());
    }

    public function test_a_loan_in_a_suspended_branch_is_not_released(): void
    {
        $loan = $this->createReleasedLoan();
        $approved = Loan::factory()->create(['borrower_id' => $loan->borrower_id, 'branch_id' => $this->branch->id, 'status' => 'approved']);
        DB::table('branches')->where('id', $this->branch->id)->update(['status' => 'suspended']);

        $this->patchJson("/api/loans/{$approved->id}/release", ['fee_fingerprint' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'Branch "Main Branch" (MAIN) is suspended, so no loans can be released there.');
        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_collections_go_on_in_a_suspended_branch(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('branches')->where('id', $this->branch->id)->update(['status' => 'suspended']);

        $repayment = app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();
    }

    // Closed branch: existing rows ----------------------------------------

    public function test_a_payment_in_a_closed_branch_is_not_voided(): void
    {
        $loan = $this->createReleasedLoan();
        $repayment = app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);
        DB::table('branches')->where('id', $this->branch->id)->update(['status' => 'closed']);

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'Branch "Main Branch" (MAIN) is closed, so its payments can\'t be voided.');
        $this->assertNull($repayment->fresh()->voided_at);
    }

    public function test_a_loan_in_a_closed_branch_is_not_voided(): void
    {
        $loan = Loan::factory()->create(['borrower_id' => $this->member($this->town)->id, 'branch_id' => $this->town->id, 'status' => 'draft']);
        $this->setStatus('closed');

        $this->patchJson("/api/loans/{$loan->id}/void")
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'Branch "Town Branch" (TWN) is closed, so its loans can\'t be voided.');
        $this->assertSame('draft', $loan->fresh()->status);
    }

    // Closed branch: other new rows -------------------------------------

    public function test_nobody_is_assigned_to_a_closed_branch(): void
    {
        $this->setStatus('closed');
        $user = User::factory()->inBranches($this->branch)->create();

        $this->putJson("/api/users/{$user->id}", ['branch_ids' => [$this->branch->id, $this->town->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors) => in_array('Branch "Town Branch" (TWN) is closed, so nothing new can be added there.', array_merge(...array_values($errors)), true));
    }

    public function test_no_journal_is_written_in_a_closed_branch(): void
    {
        $this->seedChartOfAccounts();
        $this->setStatus('closed');

        $this->postJson('/api/accounting/journals', [
            'date' => now()->toDateString(),
            'description' => 'Correction',
            'branch_id' => $this->town->id,
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 10000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 10000],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch_id.0', 'Branch "Town Branch" (TWN) is closed, so nothing new can be added there.');
    }

    // One lock for guarded writes and status changes -----------------------

    public function test_a_status_change_and_a_guarded_write_wait_for_each_other(): void
    {
        $this->app->instance(BranchWriteGuard::class, new BranchWriteGuard(waitSeconds: 1));
        $member = $this->member($this->town);
        $held = Cache::lock("branch-status:{$this->town->id}", 60);
        $this->assertTrue($held->get());

        try {
            $this->patchJson("/api/branches/{$this->town->id}/status", ['status' => 'suspended', 'reason' => 'x'])
                ->assertStatus(409)
                ->assertJsonPath('message', 'Another change in this branch is being saved. Try again in a moment.');
            $this->postJson('/api/loans', $this->loanPayload($member))->assertStatus(409);
        } finally {
            $held->release();
        }

        $this->assertSame('active', $this->town->fresh()->status);
        $this->postJson('/api/loans', $this->loanPayload($member))->assertCreated();
    }

    // Helpers ---------------------------------------------------------------

    private function setStatus(string $status): void
    {
        DB::table('branches')->where('id', $this->town->id)->update(['status' => $status]);
    }

    private function member(Branch $branch): Borrower
    {
        return AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $branch->id, 'status' => 'active']));
    }

    private function applicant(?Branch $branch): Borrower
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

    /** @return array<string, mixed> */
    private function memberPayload(): array
    {
        return [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'birthdate' => '1990-01-01',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'street_address' => '1 Rizal St',
            'city' => 'Iloilo City',
            'province' => 'Iloilo',
            'branch_id' => $this->town->id,
        ];
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
    private function importPayload(): array
    {
        return [
            'branch_id' => $this->town->id,
            'files' => [
                'customers' => ['filename' => 'members.csv', 'size_bytes' => 1, 'sha256' => hash('sha256', 'a')],
                'loans' => ['filename' => 'loans.csv', 'size_bytes' => 1, 'sha256' => hash('sha256', 'b')],
            ],
        ];
    }
}
