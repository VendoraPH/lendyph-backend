<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class LoanReleaseInsuranceTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function approvedLoan(float $principal = 10000): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loanService = app(LoanService::class);

        $loan = $loanService->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $loanService->submitForReview($loan);
        $loanService->approve($loan, $this->admin, 'OK');

        return $loan->fresh();
    }

    public function test_release_without_insurance_leaves_net_proceeds_unchanged(): void
    {
        $loan = $this->approvedLoan();
        $originalNetProceeds = (float) $loan->net_proceeds;

        $response = $this->patchJson("/api/loans/{$loan->id}/release", []);

        $response->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.insurance_premium_percentage', null)
            ->assertJsonPath('data.insurance_payment_type', null);

        $this->assertEquals($originalNetProceeds, (float) $response->json('data.net_proceeds'));

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'insurance_premium_pct' => null,
            'insurance_premium_amount' => null,
            'insurance_payment_type' => null,
            'insurance_partial_amount' => null,
            'insurance_remaining_balance' => 0,
        ]);
    }

    public function test_release_with_zero_percentage_skips_insurance_block(): void
    {
        $loan = $this->approvedLoan();
        $originalNetProceeds = (float) $loan->net_proceeds;

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 0,
            'insurance_premium_amount' => 0,
            'insurance_payment_type' => 'full',
        ]);

        $response->assertOk();
        $this->assertEquals($originalNetProceeds, (float) $response->json('data.net_proceeds'));

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'insurance_premium_pct' => null,
        ]);
    }

    public function test_release_with_full_insurance_subtracts_premium_from_net_proceeds(): void
    {
        $loan = $this->approvedLoan(10000);
        $originalNetProceeds = (float) $loan->net_proceeds;

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 100,
            'insurance_payment_type' => 'full',
            'insurance_remaining_balance' => 0,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.insurance_payment_type', 'full');

        $this->assertEquals(100.0, (float) $response->json('data.insurance_premium_amount'));
        $this->assertEquals(0.0, (float) $response->json('data.insurance_remaining_balance'));
        $this->assertEquals(
            round($originalNetProceeds - 100, 2),
            (float) $response->json('data.net_proceeds'),
        );

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'insurance_payment_type' => 'full',
            'insurance_partial_amount' => null,
        ]);
    }

    public function test_release_with_partial_insurance_subtracts_only_partial_amount(): void
    {
        $loan = $this->approvedLoan(10000);
        $originalNetProceeds = (float) $loan->net_proceeds;

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 100,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 60,
            'insurance_remaining_balance' => 40,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.insurance_payment_type', 'partial');

        $this->assertEquals(60.0, (float) $response->json('data.insurance_partial_amount'));
        $this->assertEquals(40.0, (float) $response->json('data.insurance_remaining_balance'));
        $this->assertEquals(
            round($originalNetProceeds - 60, 2),
            (float) $response->json('data.net_proceeds'),
        );
    }

    public function test_release_rejects_partial_without_partial_amount(): void
    {
        $loan = $this->approvedLoan();

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 100,
            'insurance_payment_type' => 'partial',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['insurance_partial_amount']);
    }

    public function test_release_rejects_partial_amount_greater_than_premium(): void
    {
        $loan = $this->approvedLoan();

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 100,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 200,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['insurance_partial_amount']);
    }

    public function test_release_writes_release_insurance_audit_log(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 100,
            'insurance_payment_type' => 'full',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'release_insurance',
            'auditable_type' => Loan::class,
            'auditable_id' => $loan->id,
        ]);

        $entry = AuditLog::where('action', 'release_insurance')
            ->where('auditable_id', $loan->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals('full', $entry->new_values['insurance_payment_type']);
        $this->assertEquals(100.0, $entry->new_values['collected_at_release']);
    }
}
