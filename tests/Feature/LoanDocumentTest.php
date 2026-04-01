<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\LoanProduct;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class LoanDocumentTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_disclosure_for_released_loan(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->getJson("/api/loans/{$loan->id}/disclosure");

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'document_title', 'reference_number', 'borrower', 'loan_terms',
                'deductions', 'totals', 'amortization_schedule', 'co_makers',
            ]])
            ->assertJsonPath('data.document_title', 'DISCLOSURE STATEMENT');
    }

    public function test_promissory_note_for_released_loan(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->getJson("/api/loans/{$loan->id}/promissory-note");

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'document_title', 'reference_number', 'borrower', 'co_makers',
                'loan_terms', 'payment_schedule_summary', 'branch', 'signatures',
            ]])
            ->assertJsonPath('data.document_title', 'PROMISSORY NOTE');

        $this->assertStringStartsWith('PN-', $response->json('data.reference_number'));
    }

    public function test_disclosure_for_approved_loan_uses_computed_schedule(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 30000,
            'start_date' => now()->toDateString(),
        ]);
        $loanId = $response->json('data.id');

        $this->patchJson("/api/loans/{$loanId}/submit");
        $this->patchJson("/api/loans/{$loanId}/approve", ['approval_remarks' => 'OK']);

        // Approved but not released — should compute schedule on the fly
        $this->getJson("/api/loans/{$loanId}/disclosure")
            ->assertOk()
            ->assertJsonCount(6, 'data.amortization_schedule');
    }

    public function test_disclosure_for_draft_loan_returns_422(): void
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

        $this->getJson("/api/loans/{$loanId}/disclosure")
            ->assertUnprocessable();
    }

    public function test_promissory_note_for_draft_loan_returns_422(): void
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

        $this->getJson("/api/loans/{$loanId}/promissory-note")
            ->assertUnprocessable();
    }
}
