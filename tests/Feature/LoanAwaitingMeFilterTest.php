<?php

namespace Tests\Feature;

use App\Models\ApprovalWorkflowSetting;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/loans?awaiting_me=1, behind the "awaiting me" badge (#432).
 *
 * A loan is awaiting the signed-in user when it is `for_review` and its
 * current approval step (the step `current_approver` names: the first
 * `pending` step of the latest round) carries one of the user's role names.
 * Belonging to the user's role is the whole test: admin and super_admin, who
 * may act on any step, are not shown every loan.
 */
class LoanAwaitingMeFilterTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        ApprovalWorkflowSetting::updateOrCreate(
            ['type' => ApprovalWorkflowSetting::TYPE_NORMAL],
            ['steps' => [
                ['id' => 'processor', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
                ['id' => 'manager', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
                ['id' => 'bod', 'name' => 'BOD Chairman', 'role' => 'bod1', 'kind' => 'approve'],
                ['id' => 'teller', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
            ]],
        );
    }

    public function test_a_loan_waiting_on_the_user_s_role_is_listed(): void
    {
        $atManager = $this->submittedLoan();
        $this->assertSame('Manager', $this->pendingStep($atManager)->name);
        $atBod = $this->submittedLoan();
        $this->approveAs('manager', $atBod);
        $this->createReleasedLoan();

        $response = $this->actingAs($this->userWithRoles('manager'))
            ->getJson('/api/loans?awaiting_me=1')
            ->assertOk();

        $this->assertSame([$atManager->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Manager', $response->json('data.0.current_approver'));
    }

    public function test_a_loan_waiting_on_another_role_is_not_listed(): void
    {
        $atManager = $this->submittedLoan();
        $atBod = $this->submittedLoan();
        $this->approveAs('manager', $atBod);
        $this->assertSame('BOD Chairman', $this->pendingStep($atBod)->name);

        $this->assertSame([$atManager->id], $this->awaitingIdsFor($this->userWithRoles('manager')));
        $this->assertSame([$atBod->id], $this->awaitingIdsFor($this->userWithRoles('bod1')));
        $this->assertSame([], $this->awaitingIdsFor($this->userWithRoles('viewer')));
    }

    public function test_a_user_holding_several_roles_sees_each_role_s_loans(): void
    {
        $atManager = $this->submittedLoan();
        $atBod = $this->submittedLoan();
        $this->approveAs('manager', $atBod);
        $this->createReleasedLoan();

        $ids = $this->awaitingIdsFor($this->userWithRoles('manager', 'bod1'));
        sort($ids);

        $this->assertSame([$atManager->id, $atBod->id], $ids);
    }

    public function test_a_pending_row_left_in_an_earlier_round_is_not_counted(): void
    {
        $loan = $this->submittedLoan();
        $this->approveAs('manager', $loan);

        $bod = $this->pendingStep($loan);
        $this->actingAs($this->userWithRoles('bod1'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$bod->id}/send-back", [
                'target_step_order' => 1,
                'remarks' => 'Collateral appraisal is out of date.',
            ])
            ->assertOk();
        $this->actingAs($this->admin);
        $this->approveAs('manager', $loan);

        // Round 2 now waits on the board. Leave a stale `pending` Manager row
        // behind in round 1, the shape a closed round can be left in.
        $loan->approvalSteps()->where('round', 1)->where('role', 'manager')
            ->update(['status' => LoanApprovalStep::STATUS_PENDING]);
        $this->assertSame(2, (int) $loan->approvalSteps()->max('round'));

        $this->assertSame([], $this->awaitingIdsFor($this->userWithRoles('manager')));
        $this->assertSame([$loan->id], $this->awaitingIdsFor($this->userWithRoles('bod1')));
    }

    public function test_only_the_first_pending_step_of_the_round_counts(): void
    {
        $loan = $this->submittedLoan();
        $loan->approvalSteps()->where('role', 'bod1')
            ->update(['status' => LoanApprovalStep::STATUS_PENDING]);

        $this->assertSame([$loan->id], $this->awaitingIdsFor($this->userWithRoles('manager')));
        $this->assertSame([], $this->awaitingIdsFor($this->userWithRoles('bod1')));
    }

    public function test_loans_outside_review_are_never_listed(): void
    {
        // Approved, with its release step pending for the cashier.
        $approved = $this->submittedLoan();
        $this->approveAs('manager', $approved);
        $this->approveAs('bod1', $approved);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('cashier', $this->pendingStep($approved)->role);

        // Back in draft with a Manager step still pending.
        $draft = $this->submittedLoan();
        Loan::whereKey($draft->id)->update(['status' => 'draft']);

        $this->assertSame([], $this->awaitingIdsFor($this->userWithRoles('cashier')));
        $this->assertSame([], $this->awaitingIdsFor($this->userWithRoles('manager')));
    }

    public function test_admin_and_super_admin_are_not_shown_other_roles_steps(): void
    {
        $this->submittedLoan();

        $this->assertSame([], $this->awaitingIdsFor($this->userWithRoles('admin')));
        $this->assertSame([], $this->awaitingIdsFor($this->admin));
    }

    public function test_the_role_name_must_match_exactly(): void
    {
        $loan = $this->submittedLoan();
        $manager = $this->userWithRoles('manager');

        foreach (['Manager', 'manager ', 'MANAGER'] as $lookalike) {
            $this->pendingStep($loan)->update(['role' => $lookalike]);

            $this->assertSame([], $this->awaitingIdsFor($manager), "step role '{$lookalike}'");
        }
    }

    public function test_without_the_filter_the_list_is_unchanged(): void
    {
        $atManager = $this->submittedLoan();
        $atBod = $this->submittedLoan();
        $this->approveAs('manager', $atBod);
        $released = $this->createReleasedLoan();

        $manager = $this->userWithRoles('manager');
        $all = $this->actingAs($manager)->getJson('/api/loans?per_page=100')->assertOk();
        $off = $this->actingAs($manager)->getJson('/api/loans?per_page=100&awaiting_me=0')->assertOk();

        $ids = array_column($all->json('data'), 'id');
        foreach ([$atManager->id, $atBod->id, $released->id] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertSame(Loan::count(), $all->json('meta.total'));
        $this->assertSame($all->json('data'), $off->json('data'));
        $this->assertSame($all->json('meta'), $off->json('meta'));
    }

    public function test_the_filter_leaves_the_status_counts_alone(): void
    {
        $this->submittedLoan();
        $this->submittedLoan();

        $manager = $this->userWithRoles('manager');
        $all = $this->actingAs($manager)->getJson('/api/loans')->assertOk();
        $awaiting = $this->actingAs($manager)->getJson('/api/loans?awaiting_me=1')->assertOk();

        $this->assertSame($all->json('meta.stats'), $awaiting->json('meta.stats'));
    }

    public function test_a_malformed_flag_is_rejected(): void
    {
        $this->getJson('/api/loans?awaiting_me=banana')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['awaiting_me']);
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

        return $ids;
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

        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        app(LoanService::class)->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    private function userWithRoles(string ...$roles): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        foreach ($roles as $role) {
            $user->assignRole(Role::where('name', $role)->firstOrFail());
        }

        return $user;
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

    private function approveAs(string $role, Loan $loan): void
    {
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRoles($role))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertOk();

        $this->actingAs($this->admin);
    }
}
