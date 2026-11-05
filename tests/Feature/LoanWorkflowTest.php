<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class LoanWorkflowTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_create_loan_product(): void
    {
        $response = $this->postJson('/api/loan-products', [
            'name' => 'Regular Loan',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'min_amount' => 5000,
            'max_amount' => 500000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Regular Loan');
    }

    public function test_full_loan_lifecycle(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        // Create loan application
        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $loanId = $response->json('data.id');
        $this->assertStringStartsWith('LA-', $response->json('data.application_number'));

        // Submit for review
        $this->patchJson("/api/loans/{$loanId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'for_review');

        // Approve
        $this->patchJson("/api/loans/{$loanId}/approve", ['approval_remarks' => 'OK'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Preview amortization before release
        $this->getJson("/api/loans/{$loanId}/amortization-preview")
            ->assertOk()
            ->assertJsonCount(6, 'data');

        // Release
        $response = $this->patchJson("/api/loans/{$loanId}/release");
        $response->assertOk()
            ->assertJsonPath('data.status', 'released');
        $this->assertStringStartsWith('LN-', $response->json('data.loan_account_number'));
        $this->assertCount(6, $response->json('data.amortization_schedules'));

        // View amortization schedule post-release
        $this->getJson("/api/loans/{$loanId}/amortization-schedule")
            ->assertOk()
            ->assertJsonStructure(['data', 'summary']);
    }

    public function test_submitting_says_for_approval_not_for_review(): void
    {
        $loanId = $this->draftLoanId();

        $response = $this->patchJson("/api/loans/{$loanId}/submit")->assertOk();

        $this->assertSame('Loan submitted for approval.', $response->json('message'));
        $this->assertSame('for_review', $response->json('data.status'));

        $this->patchJson("/api/loans/{$loanId}/submit")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', "Loan must be in 'draft' status to submit for approval.");
    }

    public function test_status_refusals_never_show_the_raw_for_review_key(): void
    {
        $draft = $this->draftLoanId();

        $this->patchJson("/api/loans/{$draft}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', "Loan must be in 'for approval' status to approve.");

        $this->patchJson("/api/loans/{$draft}/reject", ['approval_remarks' => 'no'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', "Loan must be in 'for approval' status to reject.");

        $this->patchJson("/api/loans/{$draft}/submit")->assertOk();
        $this->patchJson("/api/loans/{$draft}/approve")->assertOk();

        $this->putJson("/api/loans/{$draft}", ['purpose' => 'Changed'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Loan can only be edited while it is a draft or under approval.');

        $this->postJson('/api/loans/preview', [
            'loan_id' => $draft,
            'loan_product_id' => Loan::findOrFail($draft)->loan_product_id,
            'borrower_id' => Loan::findOrFail($draft)->borrower_id,
            'principal_amount' => (float) Loan::findOrFail($draft)->principal_amount,
            'start_date' => now()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Loan can only be edited while it is a draft or under approval.');
    }

    public function test_an_approval_step_on_a_loan_past_approval_says_for_approval(): void
    {
        $loanId = $this->draftLoanId();
        $this->patchJson("/api/loans/{$loanId}/submit")->assertOk();

        $loan = Loan::findOrFail($loanId);
        $step = $loan->approvalSteps()->where('status', LoanApprovalStep::STATUS_PENDING)->firstOrFail();
        $loan->forceFill(['status' => 'approved'])->save();

        $this->patchJson("/api/loans/{$loanId}/approval-steps/{$step->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', "Loan must be in 'for approval' status to act on its approval chain.");
    }

    private function draftLoanId(): int
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => (float) $product->min_amount,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    public function test_reject_loan(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
        ]);

        $loanId = $response->json('data.id');
        $this->patchJson("/api/loans/{$loanId}/submit");

        $this->patchJson("/api/loans/{$loanId}/reject", ['approval_remarks' => 'Denied'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_void_draft_loan(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
        ]);

        $loanId = $response->json('data.id');

        $this->patchJson("/api/loans/{$loanId}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'void');
    }

    public function test_cannot_release_draft_loan(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
        ]);

        $loanId = $response->json('data.id');

        $this->patchJson("/api/loans/{$loanId}/release")
            ->assertUnprocessable();
    }
}
