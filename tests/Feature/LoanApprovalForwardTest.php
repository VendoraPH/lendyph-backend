<?php

namespace Tests\Feature;

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\LoanApprovalChainService;
use App\Services\LoanService;
use App\Services\Notifications;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Forwarding a loan to a named user (gap round B2): the submitter or an
 * approver may name who acts on the next step. Only that user (or admin and
 * super_admin) may then act on it, and only while they still could have been
 * named; otherwise the step falls back to its role's holders.
 */
class LoanApprovalForwardTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
            ['id' => 'bod', 'name' => 'BOD Chairman', 'role' => 'bod1', 'kind' => 'approve'],
            ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
        ]);
    }

    // -----------------------------------------------------------------
    // Picking the user
    // -----------------------------------------------------------------

    public function test_submitting_can_forward_the_first_step_to_a_named_user_and_the_audit_names_them(): void
    {
        $manager = $this->staff('manager', ['first_name' => 'Mara', 'last_name' => 'Reyes']);
        $loan = $this->draftLoan();

        $this->submit($loan, $manager->id)->assertOk();

        $step = $this->pendingStep($loan);
        $this->assertSame('for_review', $loan->fresh()->status);
        $this->assertSame('Manager', $step->name);
        $this->assertSame($manager->id, $step->assigned_to);

        $audit = AuditLog::where('action', 'loan_step_forwarded')->where('auditable_id', $loan->id)->latest('id')->firstOrFail();
        $this->assertSame('Mara Reyes', $audit->new_values['forwarded_to']);
        $this->assertSame($manager->id, $audit->new_values['forwarded_to_user_id']);
        $this->assertStringContainsString('forwarded to Mara Reyes', $audit->description);
    }

    public function test_approving_can_forward_the_next_step_and_the_audit_names_them(): void
    {
        $manager = $this->staff('manager');
        $chair = $this->staff('bod1', ['first_name' => 'Celia', 'last_name' => 'Santos']);
        $loan = $this->submittedLoan();

        $this->approveAs($manager, $loan, $chair->id)->assertOk();

        $this->assertSame($chair->id, $this->pendingStep($loan)->assigned_to);
        $audit = AuditLog::where('action', 'loan_step_approved')->where('auditable_id', $loan->id)->latest('id')->firstOrFail();
        $this->assertSame('Celia Santos', $audit->new_values['forwarded_to']);
        $this->assertSame($chair->id, $audit->new_values['forwarded_to_user_id']);
        $this->assertStringContainsString('forwarded to Celia Santos', $audit->description);
    }

    public function test_without_a_named_user_nothing_is_assigned(): void
    {
        $manager = $this->staff('manager');
        $loan = $this->submittedLoan();

        $this->approveAs($manager, $loan)->assertOk();

        $this->assertNull($this->pendingStep($loan)->assigned_to);
        $this->assertArrayNotHasKey('forwarded_to', AuditLog::where('action', 'loan_step_approved')->latest('id')->firstOrFail()->new_values);
    }

    public function test_an_inactive_user_is_refused(): void
    {
        $inactive = $this->staff('manager', ['status' => 'inactive']);

        $this->assertSubmitRefused($inactive->id);
    }

    public function test_a_user_who_does_not_exist_is_refused(): void
    {
        $this->assertSubmitRefused(999999);
    }

    public function test_a_user_without_the_steps_role_is_refused(): void
    {
        $this->assertSubmitRefused($this->staff('bod1')->id);
    }

    public function test_a_user_whose_role_is_deactivated_is_refused(): void
    {
        $manager = $this->staff('manager');
        Role::where('name', 'manager')->update(['is_active' => false]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSubmitRefused($manager->id);
    }

    public function test_a_user_without_loans_view_is_refused(): void
    {
        Role::create(['name' => 'reviewer', 'guard_name' => 'web', 'is_active' => true]);
        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'reviewer', 'name' => 'Reviewer', 'role' => 'reviewer', 'kind' => 'approve'],
            ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
        ]);

        $this->assertSubmitRefused($this->staff('reviewer')->id, 'Reviewer');
    }

    public function test_a_user_who_cannot_see_the_loans_branch_is_refused(): void
    {
        // The cashier holds no branches:view_all, unlike the manager and board.
        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
        ]);

        $this->assertSubmitRefused($this->staff('cashier', [], $this->far)->id, 'Teller');
    }

    public function test_an_unknown_an_inactive_and_an_out_of_branch_user_get_the_same_answer(): void
    {
        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
        ]);
        $answers = [];

        foreach ([999999, $this->staff('cashier', ['status' => 'inactive'])->id, $this->staff('cashier', [], $this->far)->id] as $userId) {
            $loan = $this->draftLoan();
            $answers[] = $this->submit($loan, $userId)->assertUnprocessable()->json('errors');
        }

        $expected = ['forward_to_user_id' => [self::refusal('Teller')]];
        $this->assertSame([$expected, $expected, $expected], $answers);
    }

    public function test_the_forward_is_bound_only_for_its_own_loan(): void
    {
        $manager = $this->staff('manager');
        $other = $this->draftLoan();
        $loan = $this->draftLoan();
        app()->instance(LoanApprovalChainService::SUBMIT_FORWARD_KEY, ['loan_id' => $loan->id, 'user_id' => $manager->id]);

        app(LoanService::class)->submitForReview($other, $this->admin);

        $this->assertNull($this->pendingStep($other)->assigned_to);
        app()->forgetInstance(LoanApprovalChainService::SUBMIT_FORWARD_KEY);
    }

    public function test_forwarding_to_yourself_is_refused(): void
    {
        $processorManager = $this->staff('loan_processor');
        $processorManager->assignRole('manager');
        $loan = $this->draftLoan();

        $this->actingAs($processorManager)
            ->patchJson("/api/loans/{$loan->id}/submit", ['forward_to_user_id' => $processorManager->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['forward_to_user_id']);

        $this->assertSame('draft', $loan->fresh()->status);
    }

    public function test_the_restructures_creator_is_refused_on_the_last_approve_step(): void
    {
        $creator = $this->staff('bod1');
        $source = $this->createReleasedLoan();
        $restructure = app(LoanService::class)->restructure($source, [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
        ], $creator);
        app(LoanService::class)->submitForReview($restructure, $this->admin);
        $manager = $this->staff('manager');

        $response = $this->approveAs($manager, $restructure, $creator->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['forward_to_user_id']);

        $this->assertSame([self::refusal('BOD Chairman')], $response->json('errors.forward_to_user_id'));
        $this->assertSame('Manager', $this->pendingStep($restructure)->name);
        $this->assertNull($this->pendingStep($restructure)->assigned_to);
    }

    public function test_the_release_step_needs_loans_release_too(): void
    {
        Role::create(['name' => 'clerk', 'guard_name' => 'web', 'is_active' => true])->givePermissionTo('loans:view');
        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
            ['id' => 'clerk', 'name' => 'Release Clerk', 'role' => 'clerk', 'kind' => 'release'],
        ]);
        $loan = $this->submittedLoan();
        $clerk = $this->staff('clerk');

        $response = $this->approveAs($this->staff('manager'), $loan, $clerk->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['forward_to_user_id']);

        $this->assertSame([self::refusal('Release Clerk')], $response->json('errors.forward_to_user_id'));
        $this->assertSame('for_review', $loan->fresh()->status);
        $this->assertSame('Manager', $this->pendingStep($loan)->name);
    }

    public function test_forwarding_after_the_last_step_is_refused(): void
    {
        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
        ]);
        $loan = $this->submittedLoan();

        $this->approveAs($this->staff('manager'), $loan, $this->staff('manager')->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['forward_to_user_id']);
    }

    // -----------------------------------------------------------------
    // Who may act
    // -----------------------------------------------------------------

    public function test_only_the_assignee_or_a_bypass_role_may_act_on_an_assigned_step(): void
    {
        $assignee = $this->staff('manager');
        $otherManager = $this->staff('manager');
        $loan = $this->draftLoan();
        $this->submit($loan, $assignee->id)->assertOk();

        $this->assertFalse($this->canActOnPending($loan, $otherManager));
        $this->assertTrue($this->canActOnPending($loan, $assignee));
        $this->assertTrue($this->canActOnPending($loan, $this->staff('admin')));

        $this->approveAs($otherManager, $loan)->assertForbidden();
        $this->assertSame('Manager', $this->pendingStep($loan)->name);

        $this->actingAs($this->admin)->getJson("/api/loans/{$loan->id}/approval-steps")
            ->assertOk()
            ->assertJsonPath('data.current_steps.1.assigned_to', ['id' => $assignee->id, 'name' => $assignee->full_name])
            ->assertJsonPath('data.current_steps.2.assigned_to', null);

        $this->approveAs($assignee, $loan)->assertOk();
    }

    public function test_an_admin_may_act_on_a_step_assigned_to_someone_else(): void
    {
        $loan = $this->draftLoan();
        $this->submit($loan, $this->staff('manager')->id)->assertOk();

        $this->approveAs($this->staff('admin'), $loan)->assertOk();
    }

    public function test_a_deactivated_assignee_falls_back_to_the_roles_holders(): void
    {
        $assignee = $this->staff('manager');
        $otherManager = $this->staff('manager');
        $loan = $this->draftLoan();
        $this->submit($loan, $assignee->id)->assertOk();

        $assignee->update(['status' => 'inactive']);

        $this->assertTrue($this->canActOnPending($loan, $otherManager));
        $this->approveAs($otherManager, $loan)->assertOk();
    }

    public function test_an_assignee_who_lost_the_role_falls_back_to_the_roles_holders(): void
    {
        $assignee = $this->staff('manager');
        $otherManager = $this->staff('manager');
        $loan = $this->draftLoan();
        $this->submit($loan, $assignee->id)->assertOk();

        // Still a cashier, so they can read the chain.
        $assignee->assignRole('cashier');
        $assignee->removeRole('manager');

        $this->assertFalse($this->canActOnPending($loan, $assignee->fresh()));
        $this->assertTrue($this->canActOnPending($loan, $otherManager));
    }

    // -----------------------------------------------------------------
    // Release
    // -----------------------------------------------------------------

    public function test_a_release_step_assigned_to_a_cashier_can_be_released_only_by_them_or_an_admin(): void
    {
        $assignee = $this->staff('cashier');
        $otherCashier = $this->staff('cashier');
        $loan = $this->submittedLoan();
        $this->approveAs($this->staff('manager'), $loan)->assertOk();
        $this->approveAs($this->staff('bod1'), $loan, $assignee->id)->assertOk();
        $this->assertSame('approved', $loan->fresh()->status);
        $this->assertSame($assignee->id, $this->pendingStep($loan)->assigned_to);

        $response = $this->actingAs($otherCashier)->patchJson("/api/loans/{$loan->id}/release")->assertForbidden();
        $this->assertStringContainsString($assignee->full_name, $response->json('message'));
        $this->assertSame('approved', $loan->fresh()->status);

        $this->actingAs($assignee)->patchJson("/api/loans/{$loan->id}/release")->assertOk();
        $this->assertSame('released', $loan->fresh()->status);
    }

    public function test_a_release_assigned_to_someone_no_longer_eligible_is_open_to_the_role(): void
    {
        $assignee = $this->staff('cashier');
        $loan = $this->submittedLoan();
        $this->approveAs($this->staff('manager'), $loan)->assertOk();
        $this->approveAs($this->staff('bod1'), $loan, $assignee->id)->assertOk();
        $assignee->update(['status' => 'inactive']);

        $this->actingAs($this->staff('cashier'))->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_an_admin_may_release_a_loan_assigned_to_someone_else(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs($this->staff('manager'), $loan)->assertOk();
        $this->approveAs($this->staff('bod1'), $loan, $this->staff('cashier')->id)->assertOk();

        $this->actingAs($this->staff('admin'))->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    // -----------------------------------------------------------------
    // Awaiting me
    // -----------------------------------------------------------------

    public function test_awaiting_me_counts_steps_assigned_to_me_and_unassigned_steps_for_my_role(): void
    {
        $assignee = $this->staff('manager');
        $otherManager = $this->staff('manager');
        $assigned = $this->draftLoan();
        $this->submit($assigned, $assignee->id)->assertOk();
        $unassigned = $this->submittedLoan();

        $this->assertSame([$assigned->id, $unassigned->id], $this->awaitingIdsFor($assignee));
        $this->assertSame([$unassigned->id], $this->awaitingIdsFor($otherManager));

        $assignee->update(['status' => 'inactive']);

        $this->assertSame([$assigned->id, $unassigned->id], $this->awaitingIdsFor($otherManager));
    }

    public function test_awaiting_me_counts_an_approved_loans_release_step_for_a_releaser(): void
    {
        $assignee = $this->staff('cashier');
        $otherCashier = $this->staff('cashier');
        $assigned = $this->submittedLoan();
        $this->approveAs($this->staff('manager'), $assigned)->assertOk();
        $this->approveAs($this->staff('bod1'), $assigned, $assignee->id)->assertOk();
        $unassigned = $this->submittedLoan();
        $this->approveAs($this->staff('manager'), $unassigned)->assertOk();
        $this->approveAs($this->staff('bod1'), $unassigned)->assertOk();

        $this->assertSame([$assigned->id, $unassigned->id], $this->awaitingIdsFor($assignee));
        $this->assertSame([$unassigned->id], $this->awaitingIdsFor($otherCashier));

        // Without loans:release the release step is not theirs to wait on.
        Role::where('name', 'cashier')->firstOrFail()->revokePermissionTo('loans:release');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame([], $this->awaitingIdsFor($otherCashier->fresh()));
    }

    public function test_admins_awaiting_me_is_unchanged(): void
    {
        $loan = $this->draftLoan();
        $this->submit($loan, $this->staff('manager')->id)->assertOk();

        $this->assertSame([], $this->awaitingIdsFor($this->staff('admin')));
        $this->assertSame([], $this->awaitingIdsFor($this->admin));
    }

    public function test_only_the_release_assignee_may_send_an_approved_restructure_back(): void
    {
        $assignee = $this->staff('cashier');
        $source = $this->createReleasedLoan();
        $restructure = app(LoanService::class)->restructure($source, [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        app(LoanService::class)->submitForReview($restructure, $this->admin);
        $this->approveAs($this->staff('manager'), $restructure)->assertOk();
        $this->approveAs($this->staff('bod1'), $restructure, $assignee->id)->assertOk();

        $this->actingAs($this->staff('cashier'))
            ->patchJson("/api/loans/{$restructure->id}/send-back-approved", ['reason' => 'Source paid down'])
            ->assertForbidden();
        $this->assertSame('approved', $restructure->fresh()->status);

        $this->actingAs($assignee)
            ->patchJson("/api/loans/{$restructure->id}/send-back-approved", ['reason' => 'Source paid down'])
            ->assertOk();
        $this->assertSame('for_review', $restructure->fresh()->status);
    }

    public function test_awaiting_me_reads_forwarded_steps_without_a_query_per_step(): void
    {
        // Another manager's badge reads every step forwarded within the role.
        $manager = $this->staff('manager');
        $viewer = $this->staff('manager');
        $this->forwardedToManager($manager);

        $one = $this->awaitingQueryCount($viewer->fresh());
        $this->forwardedToManager($manager);
        $this->forwardedToManager($manager);
        $this->forwardedToManager($manager);

        $this->assertSame($one, $this->awaitingQueryCount($viewer->fresh()));
    }

    // -----------------------------------------------------------------
    // Rounds
    // -----------------------------------------------------------------

    public function test_a_send_back_opens_a_round_with_no_assignments(): void
    {
        $assignee = $this->staff('bod1');
        $loan = $this->submittedLoan();
        $this->approveAs($this->staff('manager'), $loan, $assignee->id)->assertOk();

        $step = $this->pendingStep($loan);
        $this->actingAs($assignee)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", ['target_step_order' => 1, 'remarks' => 'Check income'])
            ->assertOk();

        $round2 = $loan->approvalSteps()->where('round', 2)->get();
        $this->assertCount(4, $round2);
        $this->assertSame([null, null, null, null], $round2->pluck('assigned_to')->all());
    }

    public function test_sending_an_approved_restructure_back_opens_a_round_with_no_assignments(): void
    {
        $source = $this->createReleasedLoan();
        $restructure = app(LoanService::class)->restructure($source, [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        app(LoanService::class)->submitForReview($restructure, $this->admin);
        $this->approveAs($this->staff('manager'), $restructure)->assertOk();
        $this->approveAs($this->staff('bod1'), $restructure, $this->staff('cashier')->id)->assertOk();
        $this->assertSame('approved', $restructure->fresh()->status);

        app(LoanService::class)->sendBackApproved($restructure->fresh(), $this->admin, 'Source paid down');

        $round = $restructure->approvalSteps()->max('round');
        $this->assertSame([null, null, null, null], $restructure->approvalSteps()->where('round', $round)->pluck('assigned_to')->all());
    }

    // -----------------------------------------------------------------
    // Candidates
    // -----------------------------------------------------------------

    public function test_the_candidates_are_the_next_steps_eligible_users_by_name(): void
    {
        $zed = $this->staff('bod1', ['first_name' => 'Zed', 'last_name' => 'Uy']);
        $ana = $this->staff('bod1', ['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $this->staff('bod1', ['status' => 'inactive']);
        $this->staff('manager');
        $manager = $this->staff('manager');
        $loan = $this->submittedLoan();

        $this->actingAs($manager)->getJson("/api/loans/{$loan->id}/approval/forward-candidates")
            ->assertOk()
            ->assertExactJson(['data' => [
                'next_step' => ['name' => 'BOD Chairman', 'role' => 'bod1', 'role_label' => Role::where('name', 'bod1')->firstOrFail()->auditLabel(), 'kind' => 'approve'],
                'users' => [
                    ['id' => $ana->id, 'name' => 'Ana Cruz'],
                    ['id' => $zed->id, 'name' => 'Zed Uy'],
                ],
            ]]);
    }

    public function test_the_candidates_leave_out_the_caller_and_users_outside_the_branch(): void
    {
        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
        ]);
        $processorCashier = $this->staff('loan_processor');
        $processorCashier->assignRole('cashier');
        $near = $this->staff('cashier');
        $this->staff('cashier', [], $this->far);
        $loan = $this->draftLoan();

        $response = $this->actingAs($processorCashier)->getJson("/api/loans/{$loan->id}/approval/forward-candidates")->assertOk();

        $this->assertSame('Teller', $response->json('data.next_step.name'));
        $this->assertSame([$near->id], array_column($response->json('data.users'), 'id'));
    }

    public function test_the_candidates_for_a_draft_come_from_the_configured_chain(): void
    {
        $manager = $this->staff('manager');
        $loan = $this->draftLoan();

        $response = $this->actingAs($this->staff('loan_processor'))
            ->getJson("/api/loans/{$loan->id}/approval/forward-candidates")
            ->assertOk();

        $this->assertSame('Manager', $response->json('data.next_step.name'));
        $this->assertSame([$manager->id], array_column($response->json('data.users'), 'id'));
    }

    public function test_the_candidates_need_the_caller_to_be_able_to_act(): void
    {
        $loan = $this->submittedLoan();
        $this->actingAs($this->staff('bod1'))->getJson("/api/loans/{$loan->id}/approval/forward-candidates")->assertForbidden();

        $draft = $this->draftLoan();
        $this->actingAs($this->staff('cashier'))->getJson("/api/loans/{$draft->id}/approval/forward-candidates")->assertForbidden();
    }

    public function test_the_candidates_of_a_hidden_branch_are_not_found(): void
    {
        $loan = $this->submittedLoan();

        $this->actingAs($this->staff('loan_processor', [], $this->far))
            ->getJson("/api/loans/{$loan->id}/approval/forward-candidates")
            ->assertNotFound();
    }

    public function test_the_candidates_of_a_loan_outside_review_are_refused(): void
    {
        $loan = $this->createReleasedLoan();

        $this->getJson("/api/loans/{$loan->id}/approval/forward-candidates")
            ->assertUnprocessable();
    }

    // -----------------------------------------------------------------
    // Notices
    // -----------------------------------------------------------------

    public function test_approval_needed_goes_only_to_the_assignee(): void
    {
        $assignee = $this->staff('manager');
        $this->staff('manager');
        $loan = $this->draftLoan();

        $this->submit($loan, $assignee->id)->assertOk();

        $this->assertSame([$assignee->id], $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan));
    }

    public function test_approval_needed_without_an_assignee_goes_to_the_role(): void
    {
        $one = $this->staff('manager');
        $two = $this->staff('manager');
        $loan = $this->draftLoan();

        $this->submit($loan)->assertOk();

        $this->assertSame($this->ids($one, $two), $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan));
    }

    public function test_ready_for_release_goes_to_the_release_roles_releasers_once_and_not_to_the_creator_or_officer(): void
    {
        $creatorCashier = $this->staff('cashier');
        $creatorCashier->givePermissionTo('loans:update');
        $officer = $this->staff('loan_officer');
        $cashier = $this->staff('cashier');
        $farCashier = $this->staff('cashier', [], $this->far);
        $loan = $this->draftLoan($creatorCashier, $officer);
        app(LoanService::class)->submitForReview($loan, $creatorCashier);
        $this->approveAs($this->staff('manager'), $loan)->assertOk();
        $this->approveAs($this->staff('bod1'), $loan)->assertOk();

        $this->assertSame([$cashier->id], $this->recipients(Notifications::LOAN_READY_FOR_RELEASE, $loan));
        $this->assertNotContains($farCashier->id, $this->recipients(Notifications::LOAN_READY_FOR_RELEASE, $loan));
        $this->assertSame($this->ids($creatorCashier, $officer), $this->recipients(StaffNotification::LOAN_APPROVED, $loan));

        $row = $cashier->notifications()->where('type', Notifications::LOAN_READY_FOR_RELEASE)->firstOrFail();
        $this->assertSame('Loan ready for release', $row->data['title']);
        $this->assertStringContainsString('₱60,000.00', $row->data['body']);
        $this->assertStringContainsString('"Teller" step', $row->data['body']);
        $this->assertSame("/loans/{$loan->id}", $row->data['url']);
    }

    public function test_ready_for_release_goes_only_to_the_release_assignee(): void
    {
        $assignee = $this->staff('cashier');
        $this->staff('cashier');
        $loan = $this->submittedLoan();
        $this->approveAs($this->staff('manager'), $loan)->assertOk();

        $this->approveAs($this->staff('bod1'), $loan, $assignee->id)->assertOk();

        $this->assertSame([$assignee->id], $this->recipients(Notifications::LOAN_READY_FOR_RELEASE, $loan));
    }

    public function test_ready_for_release_skips_role_holders_without_loans_release(): void
    {
        Role::create(['name' => 'clerk', 'guard_name' => 'web', 'is_active' => true])->givePermissionTo('loans:view');
        $this->chain([
            ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
            ['id' => 'clerk', 'name' => 'Release Clerk', 'role' => 'clerk', 'kind' => 'release'],
        ]);
        $this->staff('clerk');
        $releasingClerk = $this->staff('clerk');
        $releasingClerk->givePermissionTo('loans:release');
        $loan = $this->submittedLoan();

        $this->approveAs($this->staff('manager'), $loan)->assertOk();

        $this->assertSame([$releasingClerk->id], $this->recipients(Notifications::LOAN_READY_FOR_RELEASE, $loan));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  list<array<string, string>>  $steps
     */
    private function chain(array $steps): void
    {
        ApprovalWorkflowSetting::updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => $steps]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staff(string $role, array $attributes = [], ?Branch $branch = null): User
    {
        $user = User::factory()->inBranches($branch ?? $this->branch)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function draftLoan(?User $creator = null, ?User $officer = null): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'account_officer_id' => $officer?->id,
        ], $creator ?? $this->admin);
    }

    private function submittedLoan(): Loan
    {
        $loan = $this->draftLoan();
        app(LoanService::class)->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    private function submit(Loan $loan, ?int $forwardTo = null): TestResponse
    {
        return $this->actingAs($this->admin)->patchJson(
            "/api/loans/{$loan->id}/submit",
            $forwardTo === null ? [] : ['forward_to_user_id' => $forwardTo],
        );
    }

    /**
     * The one answer to a user who can't be given a step, whatever the reason,
     * so a refusal never says whether an account exists or what it holds.
     */
    private static function refusal(string $stepName): string
    {
        return "This user can't be given the \"{$stepName}\" step. Pick someone from the list.";
    }

    private function assertSubmitRefused(int $userId, string $stepName = 'Manager'): void
    {
        $loan = $this->draftLoan();

        $response = $this->submit($loan, $userId)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['forward_to_user_id']);

        $this->assertSame([self::refusal($stepName)], $response->json('errors.forward_to_user_id'));
        $this->assertFalse(app()->bound(LoanApprovalChainService::SUBMIT_FORWARD_KEY));
        $this->assertSame('draft', $loan->fresh()->status);
        $this->assertSame(0, $loan->approvalSteps()->count());
        $this->assertSame(0, DatabaseNotification::count());
    }

    private function approveAs(User $user, Loan $loan, ?int $forwardTo = null): TestResponse
    {
        $step = $this->pendingStep($loan);

        $response = $this->actingAs($user)->patchJson(
            "/api/loans/{$loan->id}/approval-steps/{$step->id}/approve",
            $forwardTo === null ? [] : ['forward_to_user_id' => $forwardTo],
        );
        $this->actingAs($this->admin);

        return $response;
    }

    private function forwardedToManager(User $manager): Loan
    {
        $loan = $this->draftLoan();
        $this->submit($loan, $manager->id)->assertOk();

        return $loan;
    }

    private function awaitingQueryCount(User $user): int
    {
        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/loans?awaiting_me=1&per_page=100')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->actingAs($this->admin);

        return $count;
    }

    private function canActOnPending(Loan $loan, User $user): bool
    {
        $steps = $this->actingAs($user)->getJson("/api/loans/{$loan->id}/approval-steps")->assertOk()->json('data.current_steps');
        $this->actingAs($this->admin);

        return (bool) collect($steps)->firstWhere('status', LoanApprovalStep::STATUS_PENDING)['can_act'];
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->reorder()
            ->orderByDesc('round')
            ->orderBy('step_order')
            ->firstOrFail();
    }

    /**
     * @return list<int>
     */
    private function awaitingIdsFor(User $user): array
    {
        $ids = array_column(
            $this->actingAs($user)->getJson('/api/loans?awaiting_me=1&per_page=100')->assertOk()->json('data'),
            'id',
        );
        $this->actingAs($this->admin);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function recipients(string $type, Loan $loan): array
    {
        return DatabaseNotification::query()
            ->where('type', $type)
            ->get()
            ->filter(fn (DatabaseNotification $n) => ($n->data['data']['loan_id'] ?? null) === $loan->id)
            ->pluck('notifiable_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function ids(User ...$users): array
    {
        return collect($users)->pluck('id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }
}
