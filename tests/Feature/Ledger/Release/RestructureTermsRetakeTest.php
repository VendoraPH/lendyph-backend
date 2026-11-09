<?php

namespace Tests\Feature\Ledger\Release;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A restructure's approved snapshot (`restructure_outstanding`,
 * `restructure_shortfall`) is re-taken from the source's live open periods
 * when it is re-submitted, approved or edited, so a fall in what the source
 * owes during review is signed off with the new figures and the release goes
 * ahead. A fall after the last approval is still refused at release.
 *
 * createReleasedLoan() leaves ₱60,000.00 principal and ₱10,800.00 interest
 * owed; every restructure here is at ₱50,000.00.
 */
class RestructureTermsRetakeTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    private const PAID_DOWN = 'The source loan was paid down or adjusted after approval. Send the restructure back for approval again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_payment_during_review_is_signed_off_after_a_send_back_and_the_release_succeeds(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->submittedRestructureOf($source);
        $this->approveStepAs('manager', $new);

        // Period 1 paid while the restructure is in review: ₱59,000.00 owed.
        $this->payOn($source, 11800);
        $this->sendBackToTheStart($new);
        $this->resubmitAndClimb($new);

        $new->refresh();
        $this->assertSame('approved', $new->status);
        $this->assertSame(['59000.00', '9000.00'], [$new->restructure_outstanding, $new->restructure_shortfall]);
        $retaken = AuditLog::where('action', 'restructure_terms_retaken')->where('auditable_id', $new->id)->get();
        $this->assertCount(1, $retaken, 'figures that did not move again are not written again');
        $this->assertSame('submission', $retaken->first()->new_values['retaken_at']);
        $this->assertEqualsWithDelta(59000.0, (float) $retaken->first()->new_values['restructure_outstanding'], 0.001);
        $this->assertEqualsWithDelta(9000.0, (float) $retaken->first()->new_values['restructure_shortfall'], 0.001);
        $this->assertNull($retaken->first()->old_values);

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $this->assertSame('9000.00', $source->fresh()->write_off_amount);
        $this->assertSame(['50000.00', '0.00', '0.00', '0.00'], $this->storedSplit($new));
    }

    public function test_a_penalty_waiver_during_review_is_signed_off_after_a_send_back_and_the_release_succeeds(): void
    {
        $source = $this->createReleasedLoan();
        $source->amortizationSchedules()->orderBy('period_number')->first()->update(['penalty_amount' => 500]);
        $new = $this->submittedRestructureOf($source);
        $this->assertSame('71300.00', $new->restructure_outstanding);
        $this->approveStepAs('manager', $new);

        $this->applyAdjustment($source, ['adjustment_type' => 'penalty_waiver', 'new_values' => ['waive_all' => true]]);
        $this->sendBackToTheStart($new);
        $this->resubmitAndClimb($new);

        $this->assertSame(['70800.00', '20800.00'], [$new->fresh()->restructure_outstanding, $new->fresh()->restructure_shortfall]);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
        $this->assertSame('20800.00', $source->fresh()->write_off_amount);
    }

    public function test_a_balance_adjustment_during_review_is_signed_off_after_a_send_back_and_the_release_succeeds(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->submittedRestructureOf($source);
        $this->approveStepAs('manager', $new);

        // ₱5,000.00 of principal taken off the source.
        $this->applyAdjustment($source, [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['target_principal_balance' => 55000, 'reason' => 'Agreed settlement'],
        ]);
        $this->sendBackToTheStart($new);
        $this->resubmitAndClimb($new);

        $this->assertSame(['65800.00', '15800.00'], [$new->fresh()->restructure_outstanding, $new->fresh()->restructure_shortfall]);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
        $this->assertSame(['50000.00', '0.00', '0.00', '5000.00'], $this->storedSplit($new));
    }

    public function test_the_final_approval_takes_the_figures_of_that_moment(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->submittedRestructureOf($source);
        $this->approveStepAs('manager', $new);
        $this->payOn($source, 11800);

        // No send-back: the last sign-off still re-takes the figures.
        $this->approveStepAs('bod1', $new);

        $this->assertSame('approved', $new->fresh()->status);
        $this->assertSame('59000.00', $new->fresh()->restructure_outstanding);
        $this->assertSame('approval', AuditLog::where('action', 'restructure_terms_retaken')->where('auditable_id', $new->id)->sole()->new_values['retaken_at']);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
    }

    public function test_a_fall_after_the_last_approval_is_still_refused_at_release(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->submittedRestructureOf($source);
        $this->approveStepAs('manager', $new);
        $this->payOn($source, 11800);
        $this->sendBackToTheStart($new);
        $this->resubmitAndClimb($new);

        // Period 2 paid after the re-approval: ₱47,200.00 owed.
        $this->payOn($source, 11800);
        $before = $this->releaseState();

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => self::PAID_DOWN]);

        $this->assertSame($before, $this->releaseState());
    }

    public function test_editing_a_restructure_with_the_same_principal_re_takes_moved_figures(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->submittedRestructureOf($source);
        $this->payOn($source, 11800);

        $this->putJson("/api/loans/{$new->id}", ['principal_amount' => 50000])->assertOk();

        $this->assertSame(['59000.00', '9000.00'], [$new->fresh()->restructure_outstanding, $new->fresh()->restructure_shortfall]);
        $this->assertSame('edit', AuditLog::where('action', 'restructure_terms_retaken')->where('auditable_id', $new->id)->sole()->new_values['retaken_at']);
    }

    public function test_a_rise_from_penalty_during_review_is_not_taken_into_the_forgiveness(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->submittedRestructureOf($source);
        $source->amortizationSchedules()->orderBy('period_number')->first()->update(['penalty_amount' => 500]);
        $this->approveStepAs('manager', $new);
        $this->approveStepAs('bod1', $new);

        $this->assertSame(['70800.00', '20800.00'], [$new->fresh()->restructure_outstanding, $new->fresh()->restructure_shortfall]);
        $this->assertSame(0, AuditLog::where('action', 'restructure_terms_retaken')->count());
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
        $this->assertSame('20800.00', $source->fresh()->write_off_amount);
    }

    public function test_nothing_is_re_taken_or_written_when_the_source_has_not_moved(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->submittedRestructureOf($source);
        $this->approveStepAs('manager', $new);
        $this->approveStepAs('bod1', $new);

        $this->assertSame(['70800.00', '20800.00'], [$new->fresh()->restructure_outstanding, $new->fresh()->restructure_shortfall]);
        $this->assertSame(0, AuditLog::where('action', 'restructure_terms_retaken')->count());
    }

    /** A ₱50,000.00 restructure of `$source`, created and submitted, with the manager's step pending. */
    private function submittedRestructureOf(Loan $source): Loan
    {
        $id = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 50000,
            'start_date' => now()->toDateString(),
            'remarks' => 'Board approved write-off',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loans/{$id}/submit")->assertOk();

        return Loan::findOrFail($id);
    }

    private function approveStepAs(string $role, Loan $loan): void
    {
        $step = $this->pendingStep($loan);
        $this->assertSame($role, $step->role);

        $this->actingAs($this->userWithRole($role))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertOk();
        $this->actingAs($this->admin);
    }

    private function sendBackToTheStart(Loan $loan): void
    {
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole($step->role))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'The source loan changed; take the figures again.',
            ])->assertOk();
        $this->actingAs($this->admin);
    }

    /** Re-submit on the submit step, then sign off every approve step. */
    private function resubmitAndClimb(Loan $loan): void
    {
        $resubmit = $this->pendingStep($loan);
        $this->assertSame(LoanApprovalStep::KIND_SUBMIT, $resubmit->kind);
        $this->approveStepAs($resubmit->role, $loan);

        while (($step = $this->pendingStep($loan))->kind === LoanApprovalStep::KIND_APPROVE) {
            $this->approveStepAs($step->role, $loan);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function applyAdjustment(Loan $loan, array $payload): void
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", $payload)->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}
