<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class LoanAdjustmentTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_create_penalty_waiver(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
            'description' => 'Waive all penalties',
        ]);

        $response->assertCreated();
        $this->assertStringStartsWith('ADJ-', $response->json('data.adjustment_number'));
        $this->assertEquals('pending', $response->json('data.status'));
        $this->assertNotNull($response->json('data.old_values'));
    }

    public function test_penalty_waiver_requires_an_explicit_selection(): void
    {
        // Without this the payload reached a handler that waived the penalties
        // on every open schedule of the loan — silently, and irreversibly.
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['penalty_waived' => 500],
            'description' => 'Waive a specific amount',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_values.schedule_ids');
    }

    public function test_balance_adjustment_requires_adjustment_amount(): void
    {
        // `outstanding_balance` is not the contract — the handler reads
        // new_values['adjustment_amount'] directly, so a missing key was a 500.
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['outstanding_balance' => 12345],
            'description' => 'Set a new balance',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_values.adjustment_amount');
    }

    public function test_term_extension_requires_additional_terms(): void
    {
        // `term` is the restructure field; term_extension reads additional_terms.
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['term' => 9],
            'description' => 'Extend the term',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_values.additional_terms');
    }

    public function test_restructure_requires_at_least_one_figure(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['reason' => 'nothing to change'],
            'description' => 'Empty restructure',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_values');
    }

    public function test_penalty_waiver_with_schedule_ids_is_accepted(): void
    {
        $loan = $this->createReleasedLoan();
        $scheduleId = $loan->amortizationSchedules()->value('id');

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['schedule_ids' => [$scheduleId]],
            'description' => 'Waive one schedule',
        ])->assertCreated();
    }

    public function test_approve_and_apply_penalty_waiver(): void
    {
        $loan = $this->createReleasedLoan();

        // Set penalties on first schedule manually
        $loan->amortizationSchedules()->first()->update([
            'penalty_amount' => 500,
            'status' => 'overdue',
        ]);

        $createResponse = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ]);

        $adjId = $createResponse->json('data.id');

        // Approve
        $this->patchJson("/api/loan-adjustments/{$adjId}/approve", ['remarks' => 'OK'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Apply
        $this->patchJson("/api/loan-adjustments/{$adjId}/apply")
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        // Verify penalties are zeroed
        $this->assertDatabaseHas('amortization_schedules', [
            'loan_id' => $loan->id,
            'period_number' => 1,
            'penalty_amount' => 0,
        ]);
    }

    public function test_create_term_extension(): void
    {
        $loan = $this->createReleasedLoan();
        $originalTerm = $loan->term;

        $createResponse = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 3],
        ]);

        $adjId = $createResponse->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$adjId}/approve");
        $this->patchJson("/api/loan-adjustments/{$adjId}/apply")->assertOk();

        $loan->refresh();
        $this->assertGreaterThan($originalTerm, $loan->term);
    }

    public function test_reject_adjustment(): void
    {
        $loan = $this->createReleasedLoan();

        $createResponse = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ]);

        $adjId = $createResponse->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$adjId}/reject", ['remarks' => 'Not justified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_cannot_apply_unapproved_adjustment(): void
    {
        $loan = $this->createReleasedLoan();

        $createResponse = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ]);

        $adjId = $createResponse->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$adjId}/apply")
            ->assertUnprocessable();
    }

    public function test_cannot_adjust_non_released_loan(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['status' => 'completed']);

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ])->assertUnprocessable();
    }
}
