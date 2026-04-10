<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BusinessLogicTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── 1. Loan range validation ──────────────────────────────────────────

    public function test_rejects_principal_below_product_minimum(): void
    {
        $product = LoanProduct::factory()->create(['min_amount' => 10000, 'max_amount' => 100000]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 5000,
            'start_date' => now()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('principal_amount');
    }

    public function test_rejects_principal_above_product_maximum(): void
    {
        $product = LoanProduct::factory()->create(['min_amount' => 10000, 'max_amount' => 100000]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 200000,
            'start_date' => now()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('principal_amount');
    }

    public function test_rejects_interest_rate_outside_product_range(): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 5.0,
            'min_interest_rate' => 2.0,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        // Too high
        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'interest_rate' => 10.0,
            'start_date' => now()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('interest_rate');

        // Too low
        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'interest_rate' => 1.0,
            'start_date' => now()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('interest_rate');
    }

    // ── 2. Auto-deduction computation ─────────────────────────────────────

    public function test_auto_computes_deductions_from_product_fees(): void
    {
        $product = LoanProduct::factory()->create([
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 100000,
            'start_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        // 2% processing + 1% service = 3% of 100000 = 3000
        $this->assertEquals(3000, $data['total_deductions']);
        $this->assertEquals(97000, $data['net_proceeds']);
        $this->assertCount(2, $data['deductions']);
    }

    public function test_explicit_deductions_override_auto_computation(): void
    {
        $product = LoanProduct::factory()->create([
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 100000,
            'start_date' => now()->toDateString(),
            'deductions' => [
                ['name' => 'Custom Fee', 'amount' => 500, 'type' => 'fixed'],
            ],
        ]);

        $response->assertCreated();
        // Should use explicit deduction, not auto-computed
        $this->assertEquals(500, $response->json('data.total_deductions'));
    }

    // ── 3. Term/frequency overrides ───────────────────────────────────────

    public function test_accepts_term_override(): void
    {
        $product = LoanProduct::factory()->create([
            'term' => 12,
            'min_term' => 3,
            'max_term' => 24,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'term' => 6,
            'start_date' => now()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.term', 6);
    }

    // ── 4. Loan status lifecycle ──────────────────────────────────────────

    public function test_first_payment_transitions_released_to_ongoing(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'ongoing']);
    }

    public function test_full_payment_transitions_to_completed(): void
    {
        $loan = $this->createReleasedLoan();
        $totalDue = $loan->amortizationSchedules->sum('total_due');

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $totalDue,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'completed']);
    }

    // ── 5. Rejection workflow ─────────────────────────────────────────────

    public function test_rejection_populates_rejection_fields(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $loan = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'start_date' => now()->toDateString(),
        ])->json('data');

        $this->patchJson("/api/loans/{$loan['id']}/submit");

        $response = $this->patchJson("/api/loans/{$loan['id']}/reject", [
            'approval_remarks' => 'Insufficient income',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('loans', [
            'id' => $loan['id'],
            'rejection_remarks' => 'Insufficient income',
        ]);

        $dbLoan = Loan::find($loan['id']);
        $this->assertNotNull($dbLoan->rejected_by);
        $this->assertNotNull($dbLoan->rejected_at);
    }

    // ── 6. Account officer assignment ─────────────────────────────────────

    public function test_account_officer_saved_on_create(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $officer = User::factory()->create();

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'start_date' => now()->toDateString(),
            'account_officer_id' => $officer->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.account_officer_id', $officer->id);
    }

    // ── 7. Co-maker auto-creation from borrower ID ────────────────────────

    public function test_co_maker_created_from_borrower_id(): void
    {
        $product = LoanProduct::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $coMakerBorrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'start_date' => now()->toDateString(),
            'co_maker_ids' => [$coMakerBorrower->id],
        ]);

        $response->assertCreated();

        // A CoMaker record should have been created from the borrower
        $this->assertDatabaseHas('co_makers', [
            'borrower_id' => $coMakerBorrower->id,
            'first_name' => $coMakerBorrower->first_name,
        ]);
    }

    // ── 8. Repayment method default ───────────────────────────────────────

    public function test_repayment_defaults_to_cash_when_method_not_sent(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 100,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.method', 'cash');
    }

    // ── 9. CheckDefaultedLoans command ─────────────────────────────────────

    public function test_defaulted_command_marks_overdue_loans(): void
    {
        // Create a loan with schedules in the distant past
        $loan = $this->createReleasedLoan(['start_date' => now()->subYear()->toDateString()]);

        // All schedules should be far past due
        Artisan::call('loans:check-defaulted', ['--days' => 30]);

        $loan->refresh();
        $this->assertEquals('defaulted', $loan->status);
    }

    public function test_defaulted_command_skips_loans_with_recent_schedules(): void
    {
        // Create a loan starting today — schedules are in the future
        $loan = $this->createReleasedLoan();

        Artisan::call('loans:check-defaulted', ['--days' => 30]);

        $loan->refresh();
        $this->assertNotEquals('defaulted', $loan->status);
    }

    // ── 10. Borrower spouse fields ────────────────────────────────────────

    public function test_spouse_fields_saved_on_create(): void
    {
        $response = $this->postJson('/api/borrowers', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'branch_id' => $this->branch->id,
            'civil_status' => 'married',
            'spouse_first_name' => 'Juan',
            'spouse_last_name' => 'Santos',
            'spouse_occupation' => 'Engineer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('borrowers', [
            'spouse_first_name' => 'Juan',
            'spouse_last_name' => 'Santos',
            'spouse_occupation' => 'Engineer',
        ]);
    }

    // ── 11. Loan product range fields ─────────────────────────────────────

    public function test_loan_product_range_fields_persist(): void
    {
        $response = $this->postJson('/api/loan-products', [
            'name' => 'Flex Loan',
            'interest_rate' => 5.0,
            'min_interest_rate' => 2.0,
            'interest_method' => 'straight',
            'term' => 12,
            'min_term' => 3,
            'max_term' => 24,
            'frequency' => 'monthly',
            'frequencies' => ['monthly', 'semi_monthly'],
            'processing_fee' => 2.0,
            'min_processing_fee' => 1.0,
            'max_processing_fee' => 3.0,
            'notarial_fee' => 500,
            'custom_fees' => [
                ['name' => 'Insurance', 'type' => 'percentage', 'value' => 1.5],
            ],
            'min_amount' => 5000,
            'max_amount' => 500000,
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertEquals(2.0, (float) $data['min_interest_rate']);
        $this->assertEquals(5.0, (float) $data['max_interest_rate']);
        $this->assertEquals(3, $data['min_term']);
        $this->assertEquals(24, $data['max_term']);
        $this->assertEquals(['monthly', 'semi_monthly'], $data['frequencies']);
        $this->assertEquals(1.0, (float) $data['min_processing_fee']);
        $this->assertEquals(3.0, (float) $data['max_processing_fee']);
        $this->assertEquals(500, (float) $data['notarial_fee']);
        $this->assertCount(1, $data['custom_fees']);
        $this->assertEquals('Insurance', $data['custom_fees'][0]['name']);
    }

    // ── 12. LoanResource computed fields ──────────────────────────────────

    public function test_loan_resource_returns_computed_payment_fields(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);

        $response = $this->getJson("/api/loans/{$loan->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'outstanding_balance',
                    'next_due_date',
                    'current_due',
                    'overdue_amount',
                    'penalty_amount',
                    'total_payable',
                    'borrower_name',
                    'loan_product_name',
                    'release_date',
                ],
            ]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['outstanding_balance']);
        $this->assertNotNull($data['next_due_date']);
        $this->assertNotNull($data['borrower_name']);
        $this->assertNotNull($data['loan_product_name']);
    }
}
