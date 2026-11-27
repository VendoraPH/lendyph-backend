<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Each adjustment type carries, and applies, only its own fields.
 *
 * Portfolio staging's ADJ-000018 is a pending penalty waiver whose whole
 * payload is `{interest_rate: 4}`, written before the per-type validation
 * existed; the stored-row tests below rebuild that shape directly.
 */
class AdjustmentPayloadFieldsTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->loan = $this->createReleasedLoan();
    }

    public function test_a_penalty_waiver_request_cannot_carry_an_interest_rate(): void
    {
        $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true, 'interest_rate' => 4],
        ])->assertUnprocessable()->assertJsonValidationErrors(['new_values.interest_rate']);

        $this->assertSame(0, LoanAdjustment::count());
    }

    public function test_a_restructure_request_cannot_carry_a_waiver_selection(): void
    {
        $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['interest_rate' => 2, 'waive_all' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors(['new_values.waive_all']);
    }

    public function test_a_term_extension_request_cannot_carry_a_term(): void
    {
        $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 2, 'term' => 3],
        ])->assertUnprocessable()->assertJsonValidationErrors(['new_values.term']);
    }

    public function test_a_stored_penalty_waiver_carrying_only_an_interest_rate_cannot_be_approved(): void
    {
        $adjustment = $this->storedPending('penalty_waiver', ['interest_rate' => 4]);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_values']);

        $this->assertSame('pending', $adjustment->fresh()->status);
    }

    public function test_a_stored_balance_adjustment_carrying_an_interest_rate_cannot_be_approved(): void
    {
        $adjustment = $this->storedPending('balance_adjustment', ['interest_rate' => 4]);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/approve")->assertUnprocessable();
        $this->assertSame('pending', $adjustment->fresh()->status);
    }

    public function test_a_stored_term_extension_carrying_a_term_cannot_be_approved(): void
    {
        $adjustment = $this->storedPending('term_extension', ['term' => 3]);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/approve")->assertUnprocessable();
        $this->assertSame('pending', $adjustment->fresh()->status);
    }

    public function test_a_malformed_request_can_still_be_rejected(): void
    {
        $adjustment = $this->storedPending('penalty_waiver', ['interest_rate' => 4]);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/reject", ['remarks' => 'Wrong fields'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_an_approved_waiver_carrying_an_interest_rate_is_not_applied_and_the_rate_stands(): void
    {
        $adjustment = $this->storedPending('penalty_waiver', ['waive_all' => true, 'interest_rate' => 4]);
        $adjustment->update(['status' => 'approved', 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $rate = (float) $this->loan->interest_rate;

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertUnprocessable();

        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertEqualsWithDelta($rate, (float) $this->loan->fresh()->interest_rate, 0.0001);
    }

    /**
     * A pending row written straight to the table, as older code could.
     *
     * @param  array<string, mixed>  $newValues
     */
    private function storedPending(string $type, array $newValues): LoanAdjustment
    {
        return LoanAdjustment::create([
            'loan_id' => $this->loan->id,
            'adjustment_type' => $type,
            'old_values' => [],
            'new_values' => $newValues,
            'status' => 'pending',
            'adjusted_by' => $this->admin->id,
        ]);
    }
}
