<?php

namespace Tests\Feature;

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The multi-step BOD approval chain, now that it lives on the server.
 *
 * Every one of these assertions used to be unenforceable: the chain was
 * localStorage under `loan-approval-{id}`, so of a ten-step policy-exception
 * chain exactly three transitions ever reached the API and authorization
 * existed only as a client-side `canUserActOnStep`.
 */
class LoanApprovalChainTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_submitting_a_loan_seeds_the_chain_from_the_configured_settings(): void
    {
        ApprovalWorkflowSetting::create([
            'type' => ApprovalWorkflowSetting::TYPE_NORMAL,
            'steps' => [
                ['id' => 'processor', 'name' => 'Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
                ['id' => 'branch-head', 'name' => 'Branch Head', 'role' => 'manager', 'kind' => 'approve'],
                ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
            ],
        ]);

        $loan = $this->submittedLoan();

        $steps = $loan->approvalSteps()->get();

        $this->assertSame(['processor', 'branch-head', 'teller'], $steps->pluck('step_id')->all());
        $this->assertSame(['Processor', 'Branch Head', 'Teller'], $steps->pluck('name')->all());
        $this->assertSame([0, 1, 2], $steps->pluck('step_order')->all());
        $this->assertSame([1, 1, 1], $steps->pluck('round')->all());

        // The submitter just acted, so step 0 is already signed; step 1 is live.
        $this->assertSame(
            ['approved', 'pending', 'waiting'],
            $steps->pluck('status')->all(),
        );
        $this->assertSame($this->admin->id, $steps->first()->acted_by);
        $this->assertNotNull($steps->first()->acted_at);
    }

    public function test_the_snapshot_is_not_rewritten_when_the_settings_chain_is_edited_later(): void
    {
        $loan = $this->submittedLoan();

        $this->assertSame('Manager', $loan->approvalSteps()->where('step_order', 1)->value('name'));

        ApprovalWorkflowSetting::updateOrCreate(
            ['type' => ApprovalWorkflowSetting::TYPE_NORMAL],
            ['steps' => [
                ['id' => 'loan-processor', 'name' => 'Totally Different', 'role' => 'loan_processor', 'kind' => 'submit'],
                ['id' => 'someone-else', 'name' => 'Someone Else', 'role' => 'manager', 'kind' => 'release'],
            ]],
        );

        // An in-flight loan keeps the chain it started with.
        $this->assertSame(4, $loan->approvalSteps()->count());
        $this->assertSame('Manager', $loan->approvalSteps()->where('step_order', 1)->value('name'));
    }

    public function test_policy_exception_loans_get_the_ten_step_chain_and_normal_loans_the_four_step(): void
    {
        $normal = $this->submittedLoan();
        $exception = $this->submittedLoan(policyException: true);

        $this->assertSame(4, $normal->approvalSteps()->count());
        $this->assertSame(
            ['loan_processor', 'manager', 'bod1', 'general_bookkeeper'],
            $normal->approvalSteps()->pluck('role')->all(),
        );

        $this->assertSame(10, $exception->approvalSteps()->count());
        $this->assertSame(
            ['loan_processor', 'manager', 'bod1', 'bod2', 'bod3', 'bod4', 'bod5', 'bod6', 'bod7', 'cashier'],
            $exception->approvalSteps()->pluck('role')->all(),
        );

        // The nine roles the policy-exception chain names now actually exist —
        // before this feature nine of its ten steps pointed at no role at all.
        foreach (['loan_processor', 'manager', 'bod1', 'bod2', 'bod3', 'bod4', 'bod5', 'bod6', 'bod7'] as $role) {
            $this->assertTrue(
                Role::where('name', $role)->where('guard_name', 'web')->exists(),
                "Role {$role} is missing.",
            );
        }
    }

    public function test_the_chain_roles_hold_only_loans_view_before_the_accounting_branch_lands(): void
    {
        foreach (['loan_processor', 'bod1', 'bod7'] as $roleName) {
            $this->assertSame(
                ['loans:view'],
                Role::where('name', $roleName)->firstOrFail()->permissions->pluck('name')->all(),
                "{$roleName} holds more than loans:view.",
            );
        }
    }

    public function test_manager_picks_up_the_read_only_accounting_permissions_once_they_exist(): void
    {
        // 2026_09_16_200002 (feat/accounting-foundation) grants these to
        // `manager`, but it skips any role that does not exist yet — which is
        // why this branch's roles migration has to sort before it. This is the
        // seeder half of that contract: simulate the post-merge world by
        // creating the permissions, then re-seed.
        $accounting = ['accounting:view', 'chart_of_accounts:view', 'journals:view', 'expenses:view', 'cash_accounts:view'];

        foreach ($accounting as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->seed(RoleAndPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = Role::where('name', 'manager')->firstOrFail()->fresh('permissions');

        $this->assertEqualsCanonicalizing(
            array_merge(['loans:view'], $accounting),
            $manager->permissions->pluck('name')->all(),
        );

        // Board roles get no accounting access at all, and the accounting
        // branch's preparer/approver split must not be widened from here.
        foreach (['loan_processor', 'bod1', 'bod7'] as $roleName) {
            $this->assertSame(
                ['loans:view'],
                Role::where('name', $roleName)->firstOrFail()->fresh('permissions')->permissions->pluck('name')->all(),
                "{$roleName} was granted accounting access it must not have.",
            );
        }
    }

    public function test_the_steps_own_role_may_act(): void
    {
        $loan = $this->submittedLoan();
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve", ['remarks' => 'Looks fine'])
            ->assertOk()
            ->assertJsonPath('data.current_steps.1.status', 'approved')
            ->assertJsonPath('data.current_steps.1.remarks', 'Looks fine')
            ->assertJsonPath('data.current_steps.2.status', 'pending');
    }

    public function test_an_admin_may_act_on_any_step(): void
    {
        $loan = $this->submittedLoan();
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('admin'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $step->fresh()->status);
    }

    public function test_a_user_without_the_steps_role_is_forbidden(): void
    {
        $loan = $this->submittedLoan();
        $step = $this->pendingStep($loan);

        // A collector holds `loans:view`, so this 403 comes from the chain's own
        // role check and not from the permission gate in front of it.
        $this->actingAs($this->userWithRole('collector'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $step->fresh()->status);
    }

    public function test_a_user_holding_a_later_steps_role_cannot_jump_the_queue(): void
    {
        $loan = $this->submittedLoan();

        // bod1 is step 2; the chain is still waiting on the manager at step 1.
        $bodStep = $loan->approvalSteps()->where('step_order', 2)->first();

        $this->actingAs($this->userWithRole('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bodStep->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('step');
    }

    public function test_acting_on_a_step_that_is_not_pending_is_rejected(): void
    {
        $loan = $this->submittedLoan();

        // Step 0 is already approved by the submitter.
        $done = $loan->approvalSteps()->where('step_order', 0)->first();

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$done->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('step');
    }

    public function test_a_step_belonging_to_another_loan_is_not_found(): void
    {
        $loan = $this->submittedLoan();
        $other = $this->submittedLoan();
        $foreignStep = $this->pendingStep($other);

        $this->patchJson("/api/loans/{$loan->id}/approval-steps/{$foreignStep->id}/approve")
            ->assertNotFound();
    }

    public function test_approving_the_last_approve_step_moves_the_loan_to_approved(): void
    {
        $loan = $this->submittedLoan();

        $this->approveAs('manager', $loan)->assertOk();

        // Still mid-chain: the BOD step is pending and the loan is in review.
        $this->assertSame('for_review', $loan->fresh()->status);

        $this->approveAs('bod1', $loan)->assertOk();

        // The next step is the `release` step, so the existing
        // for_review -> approved transition runs and Loan::isReleasable flips.
        $loan->refresh();
        $this->assertSame('approved', $loan->status);
        $this->assertTrue($loan->is_releasable);
        $this->assertNotNull($loan->approved_at);
        $this->assertSame('pending', $loan->approvalSteps()->where('kind', 'release')->value('status'));
    }

    public function test_releasing_the_loan_closes_out_the_release_step(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);
        $this->approveAs('bod1', $loan);

        app(LoanService::class)->release($loan->refresh(), $this->admin);

        $release = $loan->approvalSteps()->where('kind', 'release')->first();
        $this->assertSame('approved', $release->status);
        $this->assertSame($this->admin->id, $release->acted_by);
    }

    public function test_send_back_requires_remarks(): void
    {
        $loan = $this->submittedLoan();
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", [
                'target_step_order' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remarks');

        $this->assertSame('pending', $step->fresh()->status);
        $this->assertSame(1, $loan->approvalSteps()->max('round'));
    }

    public function test_send_back_opens_round_two_preserves_round_one_and_leaves_the_loan_in_review(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);

        $bod = $this->pendingStep($loan);
        $bodUser = $this->userWithRole('bod1');

        $this->actingAs($bodUser)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bod->id}/send-back", [
                'target_step_order' => 1,
                'remarks' => 'Collateral appraisal is out of date.',
            ])
            ->assertOk();

        // Round 1 is frozen, not rewritten: the acting step carries who/when/why.
        $roundOne = $loan->approvalSteps()->where('round', 1)->orderBy('step_order')->get();
        $this->assertSame(['approved', 'approved', 'sent_back', 'waiting'], $roundOne->pluck('status')->all());
        $this->assertSame($bodUser->id, $roundOne[2]->acted_by);
        $this->assertSame('Collateral appraisal is out of date.', $roundOne[2]->remarks);
        $this->assertNotNull($roundOne[2]->acted_at);

        // Round 2: everything before the target keeps its signoff, the target is
        // live again, everything after it is cleared back to waiting.
        $roundTwo = $loan->approvalSteps()->where('round', 2)->orderBy('step_order')->get();
        $this->assertCount(4, $roundTwo);
        $this->assertSame(['approved', 'pending', 'waiting', 'waiting'], $roundTwo->pluck('status')->all());
        $this->assertSame($this->admin->id, $roundTwo[0]->acted_by);
        $this->assertNotNull($roundTwo[0]->acted_at);
        $this->assertNull($roundTwo[1]->acted_by);
        $this->assertNull($roundTwo[1]->acted_at);
        $this->assertNull($roundTwo[1]->remarks);
        $this->assertNull($roundTwo[2]->acted_by);

        // A send-back is a move WITHIN review, not a rejection.
        $this->assertSame('for_review', $loan->fresh()->status);
    }

    public function test_send_back_to_a_step_that_is_not_earlier_is_rejected(): void
    {
        $loan = $this->submittedLoan();
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", [
                'target_step_order' => 2,
                'remarks' => 'Nope.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_step_order');

        $this->assertSame(1, $loan->approvalSteps()->max('round'));
    }

    public function test_send_back_to_a_step_that_does_not_exist_is_rejected(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", [
                'target_step_order' => 99,
                'remarks' => 'Nope.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_step_order');

        $this->assertSame(1, $loan->approvalSteps()->max('round'));
    }

    public function test_the_resubmitted_loan_can_climb_the_chain_again(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);

        $bod = $this->pendingStep($loan);
        $this->actingAs($this->userWithRole('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bod->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'Redo the paperwork.',
            ])->assertOk();

        // Round 2 starts at the submit step, and re-submitting is an approve on it.
        $resubmit = $this->pendingStep($loan);
        $this->assertSame('submit', $resubmit->kind);
        $this->assertSame(2, $resubmit->round);

        $this->actingAs($this->userWithRole('loan_processor'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$resubmit->id}/approve")
            ->assertOk();

        $this->approveAs('manager', $loan)->assertOk();
        $this->approveAs('bod1', $loan)->assertOk();

        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_resubmitting_a_submit_kind_step_does_not_touch_the_loan_status(): void
    {
        // The frontend has no separate resubmit verb — it PATCHes .../approve
        // on the submit-kind step — so there must be no `kind` guard in the way.
        $loan = $this->submittedLoan();
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'Fix the figures.',
            ])->assertOk();

        $resubmit = $this->pendingStep($loan);
        $this->assertSame('submit', $resubmit->kind);

        $this->actingAs($this->userWithRole('loan_processor'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$resubmit->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $resubmit->fresh()->status);
        $this->assertSame('for_review', $loan->fresh()->status);
        $this->assertSame(1, $this->pendingStep($loan)->step_order);
    }

    public function test_a_step_from_a_closed_round_can_no_longer_be_actioned(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);

        $bodStepRoundOne = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bodStepRoundOne->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'Start again.',
            ])->assertOk();

        // Round 1's manager step is still `approved` and its row still exists.
        // Nothing may act on it now that round 2 is the live one.
        $staleManager = $loan->approvalSteps()->where('round', 1)->where('step_order', 1)->first();

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$staleManager->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('step');
    }

    public function test_the_chain_is_not_actionable_once_the_loan_leaves_review(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);
        $this->approveAs('bod1', $loan);

        $this->assertSame('approved', $loan->fresh()->status);

        // The release step is pending, but the chain's approve endpoint is not
        // how a loan gets released — PATCH /loans/{id}/release is.
        $release = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('general_bookkeeper'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$release->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_release_step_is_closed_even_when_the_legacy_single_shot_approve_was_used(): void
    {
        // SetupLendyPH::createReleasedLoan takes exactly this path, so most of
        // the suite produces a loan whose chain never advanced past step 1.
        $loan = $this->submittedLoan();

        app(LoanService::class)->approve($loan, $this->admin, 'Straight through');
        app(LoanService::class)->release($loan->refresh(), $this->admin);

        $release = $loan->approvalSteps()->where('kind', 'release')->first();

        $this->assertSame('approved', $release->status, 'The release step was left unfinished on a released loan.');
        $this->assertSame($this->admin->id, $release->acted_by);
    }

    public function test_a_second_send_back_opens_a_third_round_and_carries_signoffs_forward(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);

        // Round 1 -> 2, back to the manager at step 1.
        $bod = $this->pendingStep($loan);
        $this->actingAs($this->userWithRole('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bod->id}/send-back", [
                'target_step_order' => 1,
                'remarks' => 'First pass.',
            ])->assertOk();

        $this->approveAs('manager', $loan);

        // Round 2 -> 3, back to the manager again. Round 3 is copied from
        // round 2's copies, so this is where a carry-forward bug compounds.
        $bodAgain = $this->pendingStep($loan);
        $this->actingAs($this->userWithRole('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bodAgain->id}/send-back", [
                'target_step_order' => 1,
                'remarks' => 'Second pass.',
            ])->assertOk();

        $roundThree = $loan->approvalSteps()->where('round', 3)->orderBy('step_order')->get();
        $this->assertCount(4, $roundThree);
        $this->assertSame(['approved', 'pending', 'waiting', 'waiting'], $roundThree->pluck('status')->all());

        // The original submitter is still named on step 0 three rounds later.
        $this->assertSame($this->admin->id, $roundThree[0]->acted_by);
        $this->assertSame('for_review', $loan->fresh()->status);
    }

    public function test_send_back_past_approved_steps_drops_their_signoffs(): void
    {
        $loan = $this->submittedLoan(policyException: true);
        $this->approveAs('manager', $loan);
        $this->approveAs('bod1', $loan);
        $this->approveAs('bod2', $loan);

        // bod3 sends it all the way back to the manager at step 1, so bod1's
        // and bod2's signoffs are deliberately dropped.
        $bod3 = $this->pendingStep($loan);
        $this->actingAs($this->userWithRole('bod3'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bod3->id}/send-back", [
                'target_step_order' => 1,
                'remarks' => 'Board wants this reworked.',
            ])->assertOk();

        $roundTwo = $loan->approvalSteps()->where('round', 2)->orderBy('step_order')->get();

        $this->assertSame('approved', $roundTwo[0]->status);
        $this->assertSame('pending', $roundTwo[1]->status);
        foreach ([2, 3, 4] as $order) {
            $this->assertSame('waiting', $roundTwo[$order]->status);
            $this->assertNull($roundTwo[$order]->acted_by, "Step {$order} kept a signoff it should have lost.");
            $this->assertNull($roundTwo[$order]->acted_at);
        }
    }

    public function test_sent_back_remarks_name_the_step_the_loan_went_back_to(): void
    {
        $loan = $this->submittedLoan();
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'Missing collateral docs.',
            ])->assertOk();

        // Render-ready for the round summary, which prints it verbatim.
        $this->getJson("/api/loans/{$loan->id}/approval-steps")
            ->assertOk()
            ->assertJsonPath('data.rounds.0.sent_back_remarks', 'To Loan Processor: Missing collateral docs.');

        // The stored remark itself stays the reviewer's own words, because the
        // step row renders it separately and would otherwise double the prefix.
        $this->assertSame('Missing collateral docs.', $step->fresh()->remarks);
    }

    public function test_a_malformed_stored_chain_falls_back_to_the_default(): void
    {
        ApprovalWorkflowSetting::create([
            'type' => ApprovalWorkflowSetting::TYPE_NORMAL,
            'steps' => [
                ['id' => 'broken', 'name' => 'No Kind Or Role'],
            ],
        ]);

        // Indexing the missing keys would otherwise 500 inside submitForReview's
        // transaction, and a bad `kind` would be a MySQL enum error.
        $loan = $this->submittedLoan();

        $this->assertSame(4, $loan->approvalSteps()->count());
        $this->assertSame('loan-processor', $loan->approvalSteps()->where('step_order', 0)->value('step_id'));
    }

    public function test_each_transition_writes_its_own_audit_row(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->userWithRole('manager');
        $step = $this->pendingStep($loan);

        $this->actingAs($manager)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve", ['remarks' => 'OK'])
            ->assertOk();

        $approved = AuditLog::where('action', 'loan_step_approved')->latest('id')->first();
        $this->assertNotNull($approved, 'No loan_step_approved audit row was written.');
        $this->assertSame($manager->id, $approved->user_id);
        $this->assertSame($loan->id, $approved->auditable_id);
        $this->assertSame(Loan::class, $approved->auditable_type);
        $this->assertSame('manager', $approved->new_values['role']);
        $this->assertSame(1, $approved->new_values['step_order']);

        $bodUser = $this->userWithRole('bod1');
        $bodStep = $this->pendingStep($loan);

        $this->actingAs($bodUser)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bodStep->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'Missing documents.',
            ])->assertOk();

        $sentBack = AuditLog::where('action', 'loan_step_sent_back')->latest('id')->first();
        $this->assertNotNull($sentBack, 'No loan_step_sent_back audit row was written.');
        $this->assertSame($bodUser->id, $sentBack->user_id);
        $this->assertSame($loan->id, $sentBack->auditable_id);
        $this->assertSame(0, $sentBack->new_values['target_step_order']);
        $this->assertSame(2, $sentBack->new_values['new_round']);
        $this->assertSame('Missing documents.', $sentBack->new_values['remarks']);
    }

    public function test_the_index_endpoint_matches_the_frontend_approval_state_contract(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);

        $bod = $this->pendingStep($loan);
        $bodUser = $this->userWithRole('bod1');
        $this->actingAs($bodUser)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bod->id}/send-back", [
                'target_step_order' => 1,
                'remarks' => 'Re-check the figures.',
            ])->assertOk();

        $response = $this->actingAs($this->userWithRole('manager'))
            ->getJson("/api/loans/{$loan->id}/approval-steps")
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'current_steps' => [['id', 'index', 'step_id', 'name', 'role', 'kind', 'status', 'remarks', 'acted_at', 'acted_by', 'acted_by_id', 'can_act']],
                'rounds' => [['round', 'sent_back_by', 'sent_back_at', 'sent_back_remarks', 'steps']],
            ],
        ]);

        // `current_steps` is the live round; `rounds` holds only the frozen ones.
        $response->assertJsonPath('data.current_steps.0.index', 0)
            ->assertJsonPath('data.current_steps.1.status', 'pending')
            ->assertJsonCount(1, 'data.rounds')
            ->assertJsonPath('data.rounds.0.round', 1)
            ->assertJsonPath('data.rounds.0.sent_back_by', $bodUser->full_name)
            ->assertJsonPath('data.rounds.0.sent_back_remarks', 'To Manager: Re-check the figures.');

        // acted_by is a display NAME, which is what the existing UI renders.
        $response->assertJsonPath('data.current_steps.0.acted_by', $this->admin->full_name)
            ->assertJsonPath('data.current_steps.0.acted_by_id', $this->admin->id);

        // can_act is the server's full answer for the REQUESTING user: the
        // manager owns the pending step and nothing else.
        $this->assertSame(
            [false, true, false, false],
            array_column($response->json('data.current_steps'), 'can_act'),
        );

        $this->assertSame(
            [false, false, false, false],
            array_column(
                $this->actingAs($this->userWithRole('collector'))
                    ->getJson("/api/loans/{$loan->id}/approval-steps")
                    ->json('data.current_steps'),
                'can_act',
            ),
        );
    }

    public function test_deleting_a_draft_loan_takes_its_chain_with_it(): void
    {
        // LoanController@destroy hard-deletes drafts, so the FK is
        // cascadeOnDelete rather than the restrictOnDelete loan_adjustments uses.
        $loan = $this->submittedLoan();
        $loan->update(['status' => 'draft']);

        $this->assertSame(4, LoanApprovalStep::where('loan_id', $loan->id)->count());

        $this->actingAs($this->admin)
            ->deleteJson("/api/loans/{$loan->id}")
            ->assertOk();

        $this->assertSame(0, LoanApprovalStep::where('loan_id', $loan->id)->count());
    }

    private function submittedLoan(bool $policyException = false): Loan
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
            'policy_exception' => $policyException,
        ], $this->admin);

        $service->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }

    private function approveAs(string $role, Loan $loan): TestResponse
    {
        $step = $this->pendingStep($loan);

        return $this->actingAs($this->userWithRole($role))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve");
    }
}
