<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The release dialog's insurance figures come from the server. The release
 * preview quotes the premium for a percentage and what it leaves of the net
 * proceeds; the release computes the premium itself, with the same code, and
 * refuses a premium the client worked out differently, by any centavo.
 */
class ReleaseInsuranceServerFiguresTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── the release preview ──────────────────────────────────────────────

    public function test_the_preview_quotes_a_full_premium_and_what_it_leaves(): void
    {
        $loan = $this->approvedLoan(10000, processingFee: 2);

        $data = $this->getJson("/api/loans/{$loan->id}/release-preview?insurance_premium_percentage=1.5")
            ->assertOk()->json('data');

        $this->assertSame([
            'premium_amount' => '150.00',
            'collected' => '150.00',
            'partial_amount' => null,
            'remaining_balance' => '0.00',
        ], $data['insurance']);
        $this->assertSame('200.00', $data['total_deductions']);
        $this->assertSame('350.00', $data['total_deductions_after_insurance']);
        $this->assertSame('9650.00', $data['net_proceeds_after_insurance']);
        $this->assertFalse($data['exceeds_net_proceeds']);
    }

    public function test_the_preview_quotes_a_partial_premium(): void
    {
        $loan = $this->approvedLoan(10000);

        $data = $this->getJson("/api/loans/{$loan->id}/release-preview?".http_build_query([
            'insurance_premium_percentage' => 2,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 50,
        ]))->assertOk()->json('data');

        $this->assertSame([
            'premium_amount' => '200.00',
            'collected' => '50.00',
            'partial_amount' => '50.00',
            'remaining_balance' => '150.00',
        ], $data['insurance']);
        $this->assertSame('50.00', $data['total_deductions_after_insurance']);
        $this->assertSame('9950.00', $data['net_proceeds_after_insurance']);
    }

    public function test_the_premium_is_rounded_half_up_to_the_centavo(): void
    {
        // 1% of ₱1,234.50 is ₱12.345.
        $loan = $this->approvedLoan(1234.50);

        $this->getJson("/api/loans/{$loan->id}/release-preview?insurance_premium_percentage=1")
            ->assertOk()
            ->assertJsonPath('data.insurance.premium_amount', '12.35');
    }

    public function test_the_preview_has_no_insurance_without_a_percentage_or_at_zero(): void
    {
        $loan = $this->approvedLoan(10000, processingFee: 2);

        foreach (['', '?insurance_premium_percentage=0'] as $query) {
            $data = $this->getJson("/api/loans/{$loan->id}/release-preview{$query}")->assertOk()->json('data');

            $this->assertNull($data['insurance']);
            $this->assertSame('200.00', $data['total_deductions_after_insurance']);
            $this->assertSame('9800.00', $data['net_proceeds_after_insurance']);
            $this->assertFalse($data['exceeds_net_proceeds']);
        }
    }

    public function test_the_preview_says_when_the_premium_exceeds_the_net_proceeds(): void
    {
        $loan = $this->approvedLoan(10000, deductions: [['name' => 'Service Fee', 'amount' => 9950, 'type' => 'fixed']]);

        $data = $this->getJson("/api/loans/{$loan->id}/release-preview?insurance_premium_percentage=1")
            ->assertOk()->json('data');

        $this->assertSame('100.00', $data['insurance']['collected']);
        $this->assertSame('-50.00', $data['net_proceeds_after_insurance']);
        $this->assertTrue($data['exceeds_net_proceeds']);
    }

    public function test_the_preview_refuses_a_partial_amount_above_the_premium(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->getJson("/api/loans/{$loan->id}/release-preview?".http_build_query([
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 100.01,
        ]))->assertUnprocessable()->assertJsonValidationErrors('insurance_partial_amount');
    }

    public function test_the_preview_validates_its_insurance_parameters_as_the_release_does(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->getJson("/api/loans/{$loan->id}/release-preview?insurance_premium_percentage=1.255")
            ->assertUnprocessable()->assertJsonValidationErrors('insurance_premium_percentage');

        $this->getJson("/api/loans/{$loan->id}/release-preview?insurance_premium_percentage=1&insurance_payment_type=partial")
            ->assertUnprocessable()->assertJsonValidationErrors('insurance_partial_amount');
    }

    // ── the release ──────────────────────────────────────────────────────

    public function test_the_release_computes_the_premium_from_the_percentage(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.5,
            'insurance_payment_type' => 'full',
        ])->assertOk();

        $loan->refresh();
        $this->assertEquals(150, (float) $loan->insurance_premium_amount);
        $this->assertEquals(150, (float) $loan->total_deductions);
        $this->assertEquals(9850, (float) $loan->net_proceeds);
        $this->assertEquals(150, collect($loan->deductions)->firstWhere('name', 'Insurance Premium')['amount']);
    }

    public function test_the_release_accepts_a_sent_premium_that_matches_the_servers(): void
    {
        $loan = $this->approvedLoan(1234.50);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1,
            'insurance_premium_amount' => 12.35,
            'insurance_payment_type' => 'full',
        ])->assertOk();

        $this->assertEquals(12.35, (float) $loan->fresh()->insurance_premium_amount);
    }

    public function test_the_release_refuses_a_sent_premium_a_centavo_off(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.5,
            'insurance_premium_amount' => 150.01,
            'insurance_payment_type' => 'full',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'insurance_premium_amount' => 'The premium for 1.5% is ₱150.00. Reload the release preview.',
        ]);

        $this->assertNothingReleased($loan);
    }

    public function test_the_release_takes_a_partial_amount_up_to_the_servers_premium(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 100.01,
        ])->assertUnprocessable()->assertJsonValidationErrors('insurance_partial_amount');
        $this->assertNothingReleased($loan);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 40,
        ])->assertOk();

        $loan->refresh();
        $this->assertEquals(100, (float) $loan->insurance_premium_amount);
        $this->assertEquals(40, (float) $loan->insurance_partial_amount);
        $this->assertEquals(60, (float) $loan->insurance_remaining_balance);
        $this->assertEquals(9960, (float) $loan->net_proceeds);
    }

    public function test_the_release_refuses_a_partial_amount_on_a_full_payment(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'full',
            'insurance_partial_amount' => 40,
        ])->assertUnprocessable()->assertJsonValidationErrors('insurance_partial_amount');

        $this->assertNothingReleased($loan);
    }

    public function test_a_partial_payment_with_nothing_collected_now_leaves_the_whole_premium_owing(): void
    {
        $loan = $this->approvedLoan(10000);
        $query = http_build_query([
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 0,
        ]);

        $data = $this->getJson("/api/loans/{$loan->id}/release-preview?{$query}")->assertOk()->json('data');
        $this->assertSame([
            'premium_amount' => '100.00',
            'collected' => '0.00',
            'partial_amount' => '0.00',
            'remaining_balance' => '100.00',
        ], $data['insurance']);
        $this->assertSame('10000.00', $data['net_proceeds_after_insurance']);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 0,
        ])->assertOk();

        $loan->refresh();
        $this->assertEquals(100, (float) $loan->insurance_remaining_balance);
        $this->assertEquals(10000, (float) $loan->net_proceeds);
        $this->assertNull(collect($loan->deductions)->firstWhere('name', 'Insurance Premium'));
    }

    public function test_a_full_payment_sending_no_partial_amount_and_a_release_with_no_insurance_fields_are_accepted(): void
    {
        $full = $this->approvedLoan(10000);
        $this->patchJson("/api/loans/{$full->id}/release", [
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'full',
        ])->assertOk();
        $this->assertEquals(100, (float) $full->fresh()->insurance_premium_amount);

        $none = $this->approvedLoan(10000);
        $this->patchJson("/api/loans/{$none->id}/release", [])->assertOk();
        $this->assertNull($none->fresh()->insurance_premium_amount);
    }

    public function test_the_release_at_zero_percent_takes_no_insurance(): void
    {
        $loan = $this->approvedLoan(10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 0,
            'insurance_premium_amount' => 0,
        ])->assertOk();

        $loan->refresh();
        $this->assertNull($loan->insurance_premium_amount);
        $this->assertEquals(10000, (float) $loan->net_proceeds);
    }

    public function test_the_release_refuses_a_premium_above_the_net_proceeds(): void
    {
        $loan = $this->approvedLoan(10000, deductions: [['name' => 'Service Fee', 'amount' => 9950, 'type' => 'fixed']]);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1,
            'insurance_payment_type' => 'full',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'insurance_premium_amount' => 'Insurance collected exceeds the loan net proceeds.',
        ]);

        $this->assertNothingReleased($loan);
    }

    private function assertNothingReleased(Loan $loan): void
    {
        $loan->refresh();
        $this->assertSame('approved', $loan->status);
        $this->assertNull($loan->insurance_premium_amount);
        $this->assertSame(0, $loan->amortizationSchedules()->count());
    }

    /**
     * @param  list<array{name: string, amount: float|int, type: string}>|null  $deductions
     */
    private function approvedLoan(float $principal, float $processingFee = 0, ?array $deductions = null): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => $processingFee,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 0,
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $service = app(LoanService::class);

        $loan = $service->createLoan(array_filter([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'deductions' => $deductions,
        ], fn (mixed $value): bool => $value !== null), $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan->fresh(), $this->admin, 'ok');

        return $loan->fresh();
    }
}
