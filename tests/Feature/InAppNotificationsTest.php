<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\LoanApprovalChainService;
use App\Services\LoanService;
use App\Services\Notifications;
use App\Services\RepaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Staff in-app notifications (decision N, Arvin, 2026-11-02): each event
 * reaches the right staff, only those who can see the loan's branch, never
 * the actor, once each, only after the action commits, and a failure to
 * notify never fails the action.
 */
class InAppNotificationsTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    // -----------------------------------------------------------------
    // The approval chain
    // -----------------------------------------------------------------

    public function test_submitting_tells_the_first_approvers_role_and_not_the_submitter(): void
    {
        $creator = $this->staff('loan_processor');
        $manager = $this->staff('manager');
        $inactiveManager = $this->staff('manager', ['status' => 'inactive']);
        $loan = $this->draftLoan($creator, $this->staff('loan_officer'));

        app(LoanService::class)->submitForReview($loan, $creator);

        $this->assertSame([$manager->id], $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan));
        $this->assertNotContains($inactiveManager->id, $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan));

        $row = $this->latestOfType($manager, StaffNotification::APPROVAL_NEEDED);
        $this->assertSame('approval_needed', $row->type);
        $this->assertSame('Loan awaiting your approval', $row->data['title']);
        $this->assertStringContainsString('₱60,000.00', $row->data['body']);
        $this->assertStringContainsString('"Manager" step', $row->data['body']);
        $this->assertSame("/loans/{$loan->id}", $row->data['url']);
        $this->assertSame($loan->id, $row->data['data']['loan_id']);
    }

    public function test_a_holder_of_a_deactivated_role_is_not_told(): void
    {
        $creator = $this->staff('loan_processor');
        $manager = $this->staff('manager');
        Role::where('name', 'manager')->update(['is_active' => false]);
        $loan = $this->draftLoan($creator, null);

        app(LoanService::class)->submitForReview($loan, $creator);

        $this->assertNotContains($manager->id, $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan));
    }

    public function test_each_approval_tells_the_next_role_and_the_last_tells_the_creator_and_officer(): void
    {
        $creator = $this->staff('loan_processor');
        $officer = $this->staff('loan_officer');
        $manager = $this->staff('manager');
        $chair = $this->staff('bod1');
        $loan = $this->draftLoan($creator, $officer);
        app(LoanService::class)->submitForReview($loan, $creator);

        $chain = app(LoanApprovalChainService::class);
        $chain->approve($loan, $this->pendingStep($loan), $manager);

        $this->assertSame([$chair->id], $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan, round: 1, stepOrder: 2));
        $this->assertSame([], $this->recipients(StaffNotification::LOAN_APPROVED, $loan));

        $chain->approve($loan, $this->pendingStep($loan), $chair, 'Fine');

        $this->assertSame('approved', $loan->fresh()->status);
        $this->assertSame(
            $this->ids($creator, $officer),
            $this->recipients(StaffNotification::LOAN_APPROVED, $loan),
        );
        // The release step is announced as the approval, not as approval_needed.
        $this->assertSame([], $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan, round: 1, stepOrder: 3));
        $this->assertStringContainsString('approved by '.$chair->full_name, $this->latestOfType($officer, StaffNotification::LOAN_APPROVED)->data['body']);
    }

    public function test_a_rejection_tells_the_creator_and_officer_with_the_reason(): void
    {
        $creator = $this->staff('loan_processor');
        $officer = $this->staff('loan_officer');
        $loan = $this->draftLoan($creator, $officer);
        app(LoanService::class)->submitForReview($loan, $creator);

        app(LoanService::class)->reject($loan, $this->admin, 'Income not verified');

        $this->assertSame($this->ids($creator, $officer), $this->recipients(StaffNotification::LOAN_REJECTED, $loan));
        $this->assertStringContainsString('Reason: Income not verified', $this->latestOfType($creator, StaffNotification::LOAN_REJECTED)->data['body']);
    }

    public function test_a_send_back_tells_the_creator_and_officer_and_the_target_roles_other_holders(): void
    {
        $creator = $this->staff('loan_processor');
        $otherProcessor = $this->staff('loan_processor');
        $officer = $this->staff('loan_officer');
        $manager = $this->staff('manager');
        $loan = $this->draftLoan($creator, $officer);
        app(LoanService::class)->submitForReview($loan, $creator);

        app(LoanApprovalChainService::class)->sendBack($loan, $this->pendingStep($loan), 0, $manager, 'Missing payslip');

        $this->assertSame($this->ids($creator, $officer), $this->recipients(StaffNotification::LOAN_SENT_BACK, $loan));
        // The creator holds the target role too, but has just been told.
        $this->assertSame([$otherProcessor->id], $this->recipients(StaffNotification::APPROVAL_NEEDED, $loan, round: 2));
        $this->assertStringContainsString('"Loan Processor" step', $this->latestOfType($creator, StaffNotification::LOAN_SENT_BACK)->data['body']);
        $this->assertStringContainsString('Reason: Missing payslip', $this->latestOfType($creator, StaffNotification::LOAN_SENT_BACK)->data['body']);
    }

    // -----------------------------------------------------------------
    // Release, restructure, void, adjustments
    // -----------------------------------------------------------------

    public function test_a_release_tells_the_creator_and_officer_but_not_the_releaser(): void
    {
        $creator = $this->staff('loan_processor');
        $officer = $this->staff('loan_officer');
        $loan = $this->approvedLoan($creator, $officer);

        app(LoanService::class)->release($loan, $creator);

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::LOAN_RELEASED, $loan));
        $body = $this->latestOfType($officer, StaffNotification::LOAN_RELEASED)->data['body'];
        $this->assertStringContainsString('₱60,000.00 released by '.$creator->full_name, $body);
        $this->assertStringContainsString($loan->fresh()->loan_account_number, $body);
    }

    public function test_the_creator_who_is_also_the_officer_is_told_once(): void
    {
        $officer = $this->staff('loan_officer');
        $loan = $this->approvedLoan($officer, $officer);

        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::LOAN_RELEASED, $loan));
    }

    public function test_a_user_who_cannot_see_the_loans_branch_is_not_told(): void
    {
        $creator = $this->staff('loan_processor');
        $farOfficer = $this->staff('loan_officer', [], $this->far);
        $farManager = $this->staff('manager', [], $this->far);
        $loan = $this->approvedLoan($creator, $farOfficer);

        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame([$creator->id], $this->recipients(StaffNotification::LOAN_RELEASED, $loan));

        // branches:view_all sees every branch: a far-branch manager is still told.
        $draft = $this->draftLoan($creator, null);
        app(LoanService::class)->submitForReview($draft, $creator);
        $this->assertContains($farManager->id, $this->recipients(StaffNotification::APPROVAL_NEEDED, $draft));
    }

    public function test_a_creator_whose_only_role_was_deactivated_is_not_told(): void
    {
        $role = Role::create(['name' => 'branch_processor', 'guard_name' => 'web', 'is_active' => true, 'is_system' => false]);
        $role->syncPermissions(['loans:view', 'loans:create', 'loans:update']);
        $creator = tap($this->staff(null), fn (User $u) => $u->assignRole($role));
        $officer = $this->staff('loan_officer');
        $loan = $this->approvedLoan($creator, $officer);

        $role->update(['is_active' => false]);
        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::LOAN_RELEASED, $loan));
    }

    public function test_an_officer_whose_role_lacks_loans_view_is_not_told(): void
    {
        $role = Role::create(['name' => 'field_agent', 'guard_name' => 'web', 'is_active' => true, 'is_system' => false]);
        $role->syncPermissions(['borrowers:view']);
        $officer = tap($this->staff(null), fn (User $u) => $u->assignRole($role));
        $creator = $this->staff('loan_processor');
        $loan = $this->approvedLoan($creator, $officer);

        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame([$creator->id], $this->recipients(StaffNotification::LOAN_RELEASED, $loan));
    }

    public function test_releasing_a_restructure_tells_the_new_loans_creator_and_officer_once_each(): void
    {
        $creator = $this->staff('loan_processor');
        $officer = $this->staff('loan_officer');
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructuredDraft($source, $creator);
        $newLoan->update(['account_officer_id' => $officer->id]);
        app(LoanService::class)->submitForReview($newLoan, $creator);
        app(LoanService::class)->approve($newLoan, $this->admin, 'ok');

        app(LoanService::class)->release($newLoan->fresh(), $this->admin);

        $this->assertSame('restructured', $source->fresh()->status);
        // One notice per event: the restructure, not also a release.
        $this->assertSame([], $this->recipients(StaffNotification::LOAN_RELEASED, $newLoan));
        $this->assertSame($this->ids($creator, $officer), $this->recipients(StaffNotification::RESTRUCTURE_APPLIED, $newLoan));
        $this->assertCount(1, $this->rowsOfType($officer, StaffNotification::RESTRUCTURE_APPLIED));
        $row = $this->latestOfType($officer, StaffNotification::RESTRUCTURE_APPLIED);
        $this->assertSame($source->id, $row->data['data']['source_loan_id']);
        $this->assertStringContainsString('restructured into', $row->data['body']);
        $this->assertStringContainsString('₱70,800.00', $row->data['body']);
    }

    public function test_a_restructure_with_no_officer_still_tells_its_creator(): void
    {
        $creator = $this->staff('loan_processor');
        $source = $this->createReleasedLoan();
        $newLoan = $this->restructuredDraft($source, $creator);
        app(LoanService::class)->submitForReview($newLoan, $creator);
        app(LoanService::class)->approve($newLoan, $this->admin, 'ok');

        app(LoanService::class)->release($newLoan->fresh(), $this->admin);

        $this->assertNull($newLoan->fresh()->account_officer_id);
        $this->assertSame([$creator->id], $this->recipients(StaffNotification::RESTRUCTURE_APPLIED, $newLoan));
    }

    public function test_a_recipient_with_only_a_direct_loans_view_grant_is_told(): void
    {
        $role = Role::create(['name' => 'field_agent', 'guard_name' => 'web', 'is_active' => true, 'is_system' => false]);
        $role->syncPermissions(['borrowers:view']);
        $officer = tap($this->staff(null), fn (User $u) => $u->assignRole($role));
        $officer->givePermissionTo('loans:view');
        $loan = $this->approvedLoan($this->staff('loan_processor'), $officer);

        app(LoanService::class)->release($loan, $this->admin);

        $this->assertContains($officer->id, $this->recipients(StaffNotification::LOAN_RELEASED, $loan));
    }

    public function test_a_void_tells_the_officer_and_whoever_recorded_the_payment_but_not_the_voider(): void
    {
        $officer = $this->staff('loan_officer');
        $cashier = $this->staff('cashier');
        $loan = $this->createReleasedLoan();
        $loan->update(['account_officer_id' => $officer->id]);
        $repayment = app(RepaymentService::class)->processRepayment(Loan::findOrFail($loan->id), 1000, now()->toDateString(), $cashier);

        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        $this->assertSame($this->ids($officer, $cashier), $this->recipients(StaffNotification::PAYMENT_VOIDED, $loan));
        $row = $this->latestOfType($cashier, StaffNotification::PAYMENT_VOIDED);
        $this->assertSame("/payments/{$repayment->id}", $row->data['url']);
        $this->assertSame($repayment->id, $row->data['data']['repayment_id']);
        $this->assertStringContainsString("Payment {$repayment->receipt_number} of ₱1,000.00", $row->data['body']);
        $this->assertStringContainsString('Reason: Keyed twice', $row->data['body']);
    }

    public function test_requesting_an_adjustment_tells_the_holders_of_the_approve_permission(): void
    {
        $role = Role::create(['name' => 'adjustment_approver', 'guard_name' => 'web', 'is_active' => true, 'is_system' => false]);
        $role->syncPermissions(['loans:view', 'loan_adjustments:approve']);
        $approver = tap($this->staff(null), fn (User $u) => $u->assignRole($role));
        $farApprover = tap($this->staff(null, [], $this->far), fn (User $u) => $u->assignRole($role));
        $officer = $this->staff('loan_officer');
        $loan = $this->createReleasedLoan();

        $requester = $this->staff(null);
        $requesterRole = Role::create(['name' => 'adjustment_requester', 'guard_name' => 'web', 'is_active' => true, 'is_system' => false]);
        $requesterRole->syncPermissions(['loans:view', 'loan_adjustments:create', 'loan_adjustments:approve']);
        $requester->assignRole($requesterRole);

        $this->actingAs($requester)->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
            'remarks' => 'Hardship',
        ])->assertCreated();

        $recipients = $this->recipients(StaffNotification::ADJUSTMENT_PENDING, $loan);
        $this->assertContains($approver->id, $recipients);
        $this->assertContains($this->admin->id, $recipients);
        $this->assertNotContains($requester->id, $recipients);
        $this->assertNotContains($farApprover->id, $recipients);
        $this->assertNotContains($officer->id, $recipients);

        $adjustment = LoanAdjustment::where('loan_id', $loan->id)->latest('id')->first();
        $row = $this->latestOfType($approver, StaffNotification::ADJUSTMENT_PENDING);
        $this->assertSame($adjustment->id, $row->data['data']['adjustment_id']);
        $this->assertStringContainsString("{$adjustment->adjustment_number}: a penalty waiver on", $row->data['body']);
    }

    public function test_extending_a_loan_tells_its_officer_once(): void
    {
        $officer = $this->staff('loan_officer');
        $loan = $this->extendableLoan($officer);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::EXTENSION_APPLIED, $loan));
        $row = $this->latestOfType($officer, StaffNotification::EXTENSION_APPLIED);
        $adjustment = LoanAdjustment::where('loan_id', $loan->id)->latest('id')->firstOrFail();
        $this->assertSame($adjustment->id, $row->data['data']['adjustment_id']);
        $this->assertStringContainsString("{$adjustment->adjustment_number} extended", $row->data['body']);
    }

    public function test_extending_on_pay_tells_the_officer_once(): void
    {
        $officer = $this->staff('loan_officer');
        $loan = $this->extendableLoan($officer);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::EXTENSION_APPLIED, $loan));
        $this->assertCount(1, $this->rowsOfType($officer, StaffNotification::EXTENSION_APPLIED));
    }

    public function test_the_extension_preview_notifies_nobody(): void
    {
        $loan = $this->extendableLoan($this->staff('loan_officer'));
        $before = DatabaseNotification::count();

        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")->assertOk();
        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertOk();

        $this->assertSame($before, DatabaseNotification::count());
    }

    public function test_a_failing_send_during_an_extension_still_extends_and_is_logged(): void
    {
        $loan = $this->extendableLoan($this->staff('loan_officer'));
        Log::spy();
        Notification::shouldReceive('send')->andThrow(new RuntimeException('deadlock while sending'));

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])
            ->assertOk();

        $this->assertTrue(LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->where('status', 'applied')->exists());
        $this->assertSame([], $this->recipients(StaffNotification::EXTENSION_APPLIED, $loan));
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'in-app notification could not be sent')
                && $context['loan_id'] === $loan->id)
            ->atLeast()->once();
    }

    public function test_applying_an_in_place_restructure_tells_its_officer(): void
    {
        $officer = $this->staff('loan_officer');
        $loan = $this->createReleasedLoan();
        $loan->update(['account_officer_id' => $officer->id]);

        $adjustmentId = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 12, 'interest_rate' => 2.0],
            'description' => 'Reschedule the outstanding balance',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$adjustmentId}/approve")->assertOk();
        $this->assertSame([], $this->recipients(StaffNotification::RESTRUCTURE_APPLIED, $loan));

        $this->patchJson("/api/loan-adjustments/{$adjustmentId}/apply")->assertOk();

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::RESTRUCTURE_APPLIED, $loan));
        $this->assertSame([], $this->recipients(StaffNotification::EXTENSION_APPLIED, $loan));
        $this->assertSame($adjustmentId, $this->latestOfType($officer, StaffNotification::RESTRUCTURE_APPLIED)->data['data']['adjustment_id']);
    }

    public function test_applying_a_term_extension_adjustment_tells_its_officer_once(): void
    {
        $officer = $this->staff('loan_officer');
        $loan = $this->createReleasedLoan();
        $loan->update(['account_officer_id' => $officer->id]);

        $adjustmentId = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 3],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$adjustmentId}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$adjustmentId}/apply")->assertOk();

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::EXTENSION_APPLIED, $loan));
        $this->assertCount(1, $this->rowsOfType($officer, StaffNotification::EXTENSION_APPLIED));
        $this->assertSame([], $this->recipients(StaffNotification::RESTRUCTURE_APPLIED, $loan));
    }

    public function test_extension_and_in_place_restructure_notifications_reach_the_officer(): void
    {
        $officer = $this->staff('loan_officer');
        $loan = $this->createReleasedLoan();
        $loan->update(['account_officer_id' => $officer->id]);
        $adjustment = LoanAdjustment::create([
            'loan_id' => $loan->id,
            'adjustment_type' => 'term_extension',
            'old_values' => [],
            'new_values' => [],
            'status' => 'applied',
            'adjusted_by' => $this->admin->id,
        ]);

        app(Notifications::class)->extensionApplied($loan, $adjustment);
        app(Notifications::class)->restructureApplied($loan, $adjustment);

        $this->assertSame([$officer->id], $this->recipients(StaffNotification::EXTENSION_APPLIED, $loan));
        $this->assertSame([$officer->id], $this->recipients(StaffNotification::RESTRUCTURE_APPLIED, $loan));
        $this->assertStringContainsString("{$adjustment->adjustment_number} extended", $this->latestOfType($officer, StaffNotification::EXTENSION_APPLIED)->data['body']);

        // The signed-in user is the actor when none is passed.
        $this->actingAs($officer);
        app(Notifications::class)->extensionApplied($loan, $adjustment);
        $this->assertCount(1, $this->rowsOfType($officer, StaffNotification::EXTENSION_APPLIED));
    }

    // -----------------------------------------------------------------
    // Delivery
    // -----------------------------------------------------------------

    public function test_a_rolled_back_action_sends_nothing(): void
    {
        $creator = $this->staff('loan_processor');
        $officer = $this->staff('loan_officer');
        $loan = $this->draftLoan($creator, $officer);
        app(LoanService::class)->submitForReview($loan, $creator);
        $before = DatabaseNotification::count();

        try {
            DB::transaction(function () use ($loan) {
                app(LoanService::class)->reject($loan, $this->admin, 'No');

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('for_review', $loan->fresh()->status);
        $this->assertSame($before, DatabaseNotification::count());
        $this->assertSame([], $this->recipients(StaffNotification::LOAN_REJECTED, $loan));
    }

    public function test_a_failing_notification_never_fails_the_action(): void
    {
        $officer = $this->staff('loan_officer');
        $loan = $this->approvedLoan($this->staff('loan_processor'), $officer);
        Log::spy();
        Notification::shouldReceive('send')->andThrow(new RuntimeException('database channel down'));

        app(LoanService::class)->release($loan, $this->admin);

        $this->assertSame('released', $loan->fresh()->status);
        $this->assertSame([], $this->recipients(StaffNotification::LOAN_RELEASED, $loan));
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'in-app notification could not be sent')
                && $context['loan_id'] === $loan->id)
            ->atLeast()->once();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * An active staff member in $branch (Main Branch by default) holding $role.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function staff(?string $role, array $attributes = [], ?Branch $branch = null): User
    {
        $user = User::factory()->inBranches($branch ?? $this->branch)->create($attributes);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function draftLoan(User $creator, ?User $officer): Loan
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
        ], $creator);
    }

    private function restructuredDraft(Loan $source, User $creator): Loan
    {
        return app(LoanService::class)->restructure($source, [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
        ], $creator);
    }

    /**
     * A released one-month upon-maturity loan, which POST /extend accepts.
     */
    private function extendableLoan(User $officer): Loan
    {
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => 60000,
            'start_date' => now()->subDays(10)->toDateString(),
        ]);
        $loan->update(['account_officer_id' => $officer->id]);

        return $loan;
    }

    private function approvedLoan(User $creator, ?User $officer): Loan
    {
        $loan = $this->draftLoan($creator, $officer);
        app(LoanService::class)->submitForReview($loan, $creator);
        app(LoanService::class)->approve($loan, $this->admin, 'ok');

        return $loan->fresh();
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }

    /**
     * The users told about $loan with $type, sorted by id.
     *
     * @return list<int>
     */
    private function recipients(string $type, Loan $loan, ?int $round = null, ?int $stepOrder = null): array
    {
        return DatabaseNotification::query()
            ->where('type', $type)
            ->where('notifiable_type', User::class)
            ->get()
            ->filter(fn (DatabaseNotification $n) => ($n->data['data']['loan_id'] ?? null) === $loan->id)
            ->filter(fn (DatabaseNotification $n) => $round === null || ($n->data['data']['round'] ?? null) === $round)
            ->filter(fn (DatabaseNotification $n) => $stepOrder === null || ($n->data['data']['step_order'] ?? null) === $stepOrder)
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

    private function latestOfType(User $user, string $type): DatabaseNotification
    {
        return $user->notifications()->where('type', $type)->firstOrFail();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function rowsOfType(User $user, string $type)
    {
        return $user->notifications()->where('type', $type)->get();
    }
}
