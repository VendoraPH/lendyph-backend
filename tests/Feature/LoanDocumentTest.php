<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    // ── Loan document uploads (policy exception letters etc.) ─────────────────

    public function test_uploads_policy_exception_letter_to_loan(): void
    {
        Storage::fake('public');

        $loan = $this->createReleasedLoan();
        $file = UploadedFile::fake()->create('letter.pdf', 200, 'application/pdf');

        $response = $this->postJson("/api/loans/{$loan->id}/documents", [
            'file' => $file,
            'type' => 'policy_exception_letter',
            'label' => 'Policy Exception Letter',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'policy_exception_letter')
            ->assertJsonPath('data.label', 'Policy Exception Letter');

        $this->assertDatabaseHas('documents', [
            'documentable_type' => Loan::class,
            'documentable_id' => $loan->id,
            'type' => 'policy_exception_letter',
        ]);

        $document = Document::where('documentable_type', Loan::class)
            ->where('documentable_id', $loan->id)
            ->first();
        Storage::disk('public')->assertExists($document->file_path);
    }

    public function test_lists_uploaded_loan_documents(): void
    {
        Storage::fake('public');

        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/documents", [
            'file' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
            'type' => 'policy_exception_letter',
        ])->assertCreated();

        $this->postJson("/api/loans/{$loan->id}/documents", [
            'file' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
            'type' => 'supporting_document',
        ])->assertCreated();

        $this->getJson("/api/loans/{$loan->id}/documents")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_loan_document_upload_requires_loans_update_permission(): void
    {
        Storage::fake('public');

        $loan = $this->createReleasedLoan();

        // Collector role has loans:view but NOT loans:update
        $collector = User::factory()->create(['branch_id' => $this->branch->id]);
        $collector->assignRole('collector');

        $this->actingAs($collector)
            ->postJson("/api/loans/{$loan->id}/documents", [
                'file' => UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf'),
                'type' => 'policy_exception_letter',
            ])
            ->assertForbidden();
    }

    public function test_loan_document_upload_on_nonexistent_loan_returns_404(): void
    {
        $this->postJson('/api/loans/999999/documents', [
            'file' => UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf'),
            'type' => 'policy_exception_letter',
        ])->assertNotFound();
    }

    public function test_loan_document_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');

        $loan = $this->createReleasedLoan();

        // 11 MB — rule is max:10240 KB
        $oversized = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

        $this->postJson("/api/loans/{$loan->id}/documents", [
            'file' => $oversized,
            'type' => 'policy_exception_letter',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }
}
