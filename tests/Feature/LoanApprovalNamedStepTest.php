<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The late click on the loan approval chain (gap round B7): approve and
 * send-back may name the step the screen showed as pending (`step_id`, the
 * step row's id). When that step isn't the pending one any more, the answer
 * is a 409 in words naming the step the loan is now at, so a stale screen
 * never signs off the next step. Without `step_id` (older screens) nothing
 * changes.
 */
class LoanApprovalNamedStepTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_approve_naming_the_pending_step_signs_it_off(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve", ['step_id' => $manager->id])
            ->assertOk();

        $this->assertSame(LoanApprovalStep::STATUS_APPROVED, $manager->fresh()->status);
        $this->assertSame('BOD Chairwoman', $this->pendingStep($loan)->name);
    }

    public function test_a_late_approve_naming_a_step_already_signed_answers_409_naming_the_current_step(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve")->assertOk();
        $chairwoman = $this->pendingStep($loan);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve", ['step_id' => $manager->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That approval step isn\'t waiting any more: the loan is now at the "BOD Chairwoman" step. Reload it.');

        // Nothing moved: the step that is waiting is still waiting.
        $this->assertSame(LoanApprovalStep::STATUS_PENDING, $chairwoman->fresh()->status);
        $this->assertSame('for_review', $loan->fresh()->status);
    }

    public function test_a_stale_step_id_on_the_pending_steps_url_is_refused_too(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve")->assertOk();
        $chairwoman = $this->pendingStep($loan);

        // A screen that read the manager step as pending can't reach the
        // chairwoman's step by pairing its stale step_id with her URL.
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$chairwoman->id}/approve", ['step_id' => $manager->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That approval step isn\'t waiting any more: the loan is now at the "BOD Chairwoman" step. Reload it.');

        $this->assertSame(LoanApprovalStep::STATUS_PENDING, $chairwoman->fresh()->status);
    }

    public function test_without_step_id_a_late_approve_keeps_todays_answer(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve")->assertOk();

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['step']);
    }

    public function test_once_the_last_approver_signs_a_late_click_names_the_release_step(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve")->assertOk();
        $chairwoman = $this->pendingStep($loan);
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$chairwoman->id}/approve")->assertOk();
        $this->assertSame('approved', $loan->fresh()->status);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$chairwoman->id}/approve", ['step_id' => $chairwoman->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That approval step isn\'t waiting any more: the loan is now at the "General Bookkeeper" step. Reload it.');
    }

    public function test_a_late_send_back_naming_a_step_already_signed_answers_409(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);
        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve")->assertOk();
        $chairwoman = $this->pendingStep($loan);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/send-back", [
            'step_id' => $manager->id,
            'target_step_order' => 0,
            'remarks' => 'Missing payslip.',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That approval step isn\'t waiting any more: the loan is now at the "BOD Chairwoman" step. Reload it.');

        $this->assertSame(1, $loan->approvalSteps()->max('round'));
        $this->assertSame(LoanApprovalStep::STATUS_PENDING, $chairwoman->fresh()->status);
    }

    public function test_send_back_naming_the_pending_step_opens_a_new_round(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/send-back", [
            'step_id' => $manager->id,
            'target_step_order' => 0,
            'remarks' => 'Missing payslip.',
        ])->assertOk();

        $this->assertSame(2, $loan->approvalSteps()->max('round'));
    }

    public function test_step_id_must_be_a_step_number(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->pendingStep($loan);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$manager->id}/approve", ['step_id' => 'manager'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['step_id']);

        $this->assertSame(LoanApprovalStep::STATUS_PENDING, $manager->fresh()->status);
    }

    private function submittedLoan(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $service->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }
}
