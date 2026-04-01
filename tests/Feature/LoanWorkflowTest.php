<?php

namespace Tests\Feature;

use App\Models\Borrower;
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
