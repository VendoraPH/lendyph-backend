<?php

namespace Tests\Feature\FinishRunRound6;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LoanAdjustment;
use App\Models\User;
use App\Services\LoanAdjustmentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6: an approved adjustment that isn't applied yet can be
 * cancelled with a reason by a `loan_adjustments:approve` holder who can see
 * the loan's branch (PATCH /loan-adjustments/{id}/cancel). It reads
 * Cancelled, is audited in words, and changes nothing on the loan. Pending
 * adjustments are rejected as before; applied, rejected and cancelled ones
 * are refused in words.
 */
class CancelApprovedAdjustmentTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A pending balance adjustment taking ₱1,000 off a released loan's principal. */
    private function pendingAdjustment(): LoanAdjustment
    {
        return app(LoanAdjustmentService::class)->createAdjustment($this->createReleasedLoan(), [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -1000],
        ], $this->admin);
    }

    private function approvedAdjustment(): LoanAdjustment
    {
        return app(LoanAdjustmentService::class)->approveAdjustment($this->pendingAdjustment(), $this->admin, 'ok')->fresh();
    }

    private function cancel(LoanAdjustment $adjustment, ?string $reason = 'The member paid it down instead.')
    {
        return $this->patchJson("/api/loan-adjustments/{$adjustment->id}/cancel", $reason === null ? [] : ['reason' => $reason]);
    }

    public function test_an_approved_adjustment_is_cancelled_with_its_reason_and_changes_nothing_on_the_loan(): void
    {
        $adjustment = $this->approvedAdjustment();
        $loan = $adjustment->loan;
        $schedulesBefore = $loan->amortizationSchedules()->orderBy('id')->get()->toArray();

        $this->travelTo(now()->addMinute());
        $response = $this->cancel($adjustment, '  The member paid it down instead.  ')
            ->assertOk()
            ->assertJsonPath('message', 'Adjustment cancelled.')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'The member paid it down instead.')
            ->assertJsonPath('data.cancelled_by_user.id', $this->admin->id)
            ->assertJsonPath('data.cancelled_by_user.name', $this->admin->full_name)
            ->assertJsonPath('data.can_cancel', false);

        $this->assertNotNull($response->json('data.cancelled_at'));
        $this->assertNull($adjustment->fresh()->applied_at);
        $this->assertSame($schedulesBefore, $loan->amortizationSchedules()->orderBy('id')->get()->toArray());

        // It can no longer be applied.
        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // The list reads it too.
        $this->getJson("/api/loans/{$loan->id}/adjustments")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'cancelled')
            ->assertJsonPath('data.0.cancellation_reason', 'The member paid it down instead.')
            ->assertJsonPath('data.0.cancelled_by_user.name', $this->admin->full_name);
    }

    public function test_the_cancel_writes_one_audit_row_in_words(): void
    {
        $adjustment = $this->approvedAdjustment();
        $before = AuditLog::count();

        $this->cancel($adjustment)->assertOk();

        $this->assertSame($before + 1, AuditLog::count());
        $row = AuditLog::latest('id')->first();
        $this->assertSame('adjustment_cancelled', $row->action);
        $this->assertSame(LoanAdjustment::class, $row->auditable_type);
        $this->assertSame($adjustment->id, $row->auditable_id);
        $this->assertSame(['status' => 'approved'], $row->old_values);
        $this->assertSame(['status' => 'cancelled', 'cancellation_reason' => 'The member paid it down instead.'], $row->new_values);
        $this->assertSame(
            sprintf('Balance adjustment %s on loan %s was cancelled before it was applied. Reason: The member paid it down instead.', $adjustment->adjustment_number, $adjustment->loan->loan_account_number),
            $row->description,
        );
    }

    public function test_only_an_approved_adjustment_can_be_cancelled_and_others_are_refused_in_words(): void
    {
        $pending = $this->pendingAdjustment();
        $this->cancel($pending)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status' => 'This adjustment is still waiting for approval. Reject it instead of cancelling it.']);

        $rejected = $this->pendingAdjustment();
        app(LoanAdjustmentService::class)->rejectAdjustment($rejected, $this->admin, 'no');
        $this->cancel($rejected)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status' => 'This adjustment was rejected, so there is nothing to cancel.']);

        $applied = $this->approvedAdjustment();
        app(LoanAdjustmentService::class)->applyAdjustment($applied->fresh());
        $this->cancel($applied)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status' => 'This adjustment has already been applied to the loan, so it can no longer be cancelled.']);

        $cancelled = $this->approvedAdjustment();
        $this->cancel($cancelled)->assertOk();
        $this->cancel($cancelled)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status' => 'This adjustment has already been cancelled.']);

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('applied', $applied->fresh()->status);
    }

    public function test_the_reason_is_required_trimmed_and_between_5_and_500_characters(): void
    {
        $adjustment = $this->approvedAdjustment();

        $this->cancel($adjustment, null)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->cancel($adjustment, '   ')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->cancel($adjustment, '  abc  ')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->cancel($adjustment, str_repeat('a', 501))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('approved', $adjustment->fresh()->status);

        $this->cancel($adjustment, str_repeat('a', 500))->assertOk();
    }

    public function test_it_needs_loan_adjustments_approve(): void
    {
        $adjustment = $this->approvedAdjustment();
        $viewer = User::factory()->inBranches($this->branch)->create();
        $viewer->givePermissionTo(['loans:view', 'loan_adjustments:view']);

        $this->actingAs($viewer);
        $this->getJson("/api/loans/{$adjustment->loan_id}/adjustments")->assertOk()->assertJsonPath('data.0.can_cancel', false);
        $this->cancel($adjustment)->assertForbidden();
        $this->assertSame('approved', $adjustment->fresh()->status);

        $approver = User::factory()->inBranches($this->branch)->create();
        $approver->givePermissionTo(['loans:view', 'loan_adjustments:view', 'loan_adjustments:approve']);
        $this->actingAs($approver);
        $this->getJson("/api/loans/{$adjustment->loan_id}/adjustments")->assertOk()->assertJsonPath('data.0.can_cancel', true);
        $this->cancel($adjustment)->assertOk()->assertJsonPath('data.cancelled_by_user.id', $approver->id);
    }

    public function test_another_branchs_adjustment_answers_404(): void
    {
        $adjustment = $this->approvedAdjustment();
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $adjustment->loan->borrower->update(['branch_id' => $far->id]);
        $adjustment->loan->update(['branch_id' => $far->id]);

        $approver = User::factory()->inBranches($this->branch)->create();
        $approver->givePermissionTo(['loans:view', 'loan_adjustments:view', 'loan_adjustments:approve']);
        $this->actingAs($approver);

        $this->cancel($adjustment)->assertNotFound();
        $this->assertSame('approved', $adjustment->fresh()->status);
    }

    public function test_only_an_approved_adjustment_offers_can_cancel(): void
    {
        $pending = $this->pendingAdjustment();
        $this->getJson("/api/loan-adjustments/{$pending->id}")->assertOk()->assertJsonPath('data.can_cancel', false);

        $approved = $this->approvedAdjustment();
        $this->getJson("/api/loan-adjustments/{$approved->id}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.cancelled_at', null)
            ->assertJsonPath('data.cancellation_reason', null)
            ->assertJsonPath('data.cancelled_by_user', null);
    }
}
