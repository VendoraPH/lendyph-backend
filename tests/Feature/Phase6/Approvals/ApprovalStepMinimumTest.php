<?php

namespace Tests\Feature\Phase6\Approvals;

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
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
 * Phase 6 MB-BE6 (multi-branch decision 10): a loan approval step may carry an
 * optional minimum principal (`min_principal`). Seeding the chain leaves out
 * a step whose minimum is above the loan's principal, at submit and again at
 * re-submit. The first approval step and the Release step never carry one, so
 * every loan keeps at least one approval.
 */
class ApprovalStepMinimumTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_step_whose_minimum_is_above_the_principal_is_left_out(): void
    {
        $this->saveChain();

        $loan = $this->submittedLoan(10000);
        $steps = $loan->approvalSteps()->orderBy('step_order')->get();

        $this->assertSame(['processor', 'first', 'release'], $steps->pluck('step_id')->all());
        $this->assertSame([0, 1, 2], $steps->pluck('step_order')->all());
        $this->assertSame(['approved', 'pending', 'waiting'], $steps->pluck('status')->all());

        $row = AuditLog::query()->where('action', 'loan_approval_steps_left_out')
            ->where('auditable_id', $loan->id)->firstOrFail();
        $this->assertSame(
            "Loan {$loan->application_number}: step \"Head Office\" left out, because the principal of ₱10,000.00 is below its minimum of ₱50,000.00.",
            $row->description,
        );
    }

    public function test_a_step_whose_minimum_is_met_is_kept(): void
    {
        $this->saveChain();

        $equal = $this->submittedLoan(50000);
        $above = $this->submittedLoan(60000);

        $this->assertSame(['processor', 'first', 'head-office', 'release'], $equal->approvalSteps()->orderBy('step_order')->pluck('step_id')->all());
        $this->assertSame(['processor', 'first', 'head-office', 'release'], $above->approvalSteps()->orderBy('step_order')->pluck('step_id')->all());
        $this->assertFalse(AuditLog::query()->where('action', 'loan_approval_steps_left_out')->exists());
    }

    public function test_the_first_approval_step_cannot_carry_a_minimum(): void
    {
        $steps = $this->chain();
        $steps[1]['min_principal'] = '1000.00';

        $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['steps.1.min_principal' => 'Step "First Review" can\'t have a minimum principal: the first approval step always applies, so every loan keeps at least one approval.']);
    }

    public function test_the_release_and_submit_steps_cannot_carry_a_minimum(): void
    {
        $steps = $this->chain();
        $steps[3]['min_principal'] = '1000.00';

        $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['steps.3.min_principal' => 'Step "Release" can\'t have a minimum principal: the Release step always applies.']);

        $steps = $this->chain();
        $steps[0]['min_principal'] = '1000.00';

        $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['steps.0.min_principal' => 'Step "Processor" can\'t have a minimum principal: the Submit step always applies.']);
    }

    public function test_the_minimum_is_pesos_with_two_decimals_and_at_least_one_centavo(): void
    {
        foreach (['0', '0.00', '-5', '100.005', 'abc'] as $bad) {
            $steps = $this->chain();
            $steps[2]['min_principal'] = $bad;

            $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])
                ->assertStatus(422)
                ->assertJsonValidationErrors('steps.2.min_principal');
        }

        $steps = $this->chain();
        $steps[2]['min_principal'] = '0.01';
        $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])->assertOk();
    }

    public function test_the_save_stores_two_decimals_and_audits_the_minimum_in_words(): void
    {
        $steps = $this->chain();
        $steps[2]['min_principal'] = 50000;

        $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])
            ->assertOk()
            ->assertJsonPath('data.steps.2.min_principal', '50000.00')
            ->assertJsonMissingPath('data.steps.1.min_principal');

        $row = AuditLog::query()->where('action', 'loan_approval_chain_updated')->latest('id')->firstOrFail();
        $this->assertStringStartsWith(
            'Normal loan approval chain saved: Processor (Loan Processor), First Review (Manager), Head Office (BOD1, from a principal of ₱50,000.00), Release (Cashier).',
            $row->description,
        );
        $this->assertSame('50000.00', $row->new_values['steps'][2]['min_principal']);
    }

    public function test_re_submitting_after_a_principal_change_seeds_the_chain_again(): void
    {
        $this->saveChain();

        $loan = $this->submittedLoan(10000);
        $first = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$first->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'Raise the amount.',
            ])->assertOk();

        Loan::query()->whereKey($loan->id)->update(['principal_amount' => 75000]);

        $submit = $this->pendingStep($loan);
        $this->assertSame('submit', $submit->kind);

        $this->actingAs($this->admin)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$submit->id}/approve")
            ->assertOk();

        $round = $loan->approvalSteps()->where('round', 2)->orderBy('step_order')->get();
        $this->assertSame(['processor', 'first', 'head-office', 'release'], $round->pluck('step_id')->all());
        $this->assertSame([0, 1, 2, 3], $round->pluck('step_order')->all());
        $this->assertSame(['approved', 'pending', 'waiting', 'waiting'], $round->pluck('status')->all());

        $this->assertSame(
            ['processor', 'first', 'release'],
            $loan->approvalSteps()->where('round', 1)->orderBy('step_order')->pluck('step_id')->all(),
            'Round 1 stays as it was.',
        );
    }

    public function test_re_submitting_after_the_principal_falls_leaves_the_step_out_again(): void
    {
        $this->saveChain();

        $loan = $this->submittedLoan(60000);
        $first = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$first->id}/send-back", [
                'target_step_order' => 0,
                'remarks' => 'Lower the amount.',
            ])->assertOk();

        Loan::query()->whereKey($loan->id)->update(['principal_amount' => 20000]);

        $submit = $this->pendingStep($loan);
        $this->actingAs($this->admin)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$submit->id}/approve")
            ->assertOk();

        $this->assertSame(
            ['processor', 'first', 'release'],
            $loan->approvalSteps()->where('round', 2)->orderBy('step_order')->pluck('step_id')->all(),
        );
    }

    public function test_a_principal_raised_past_a_left_out_step_without_re_submitting_is_refused(): void
    {
        $this->saveChain();

        $loan = $this->submittedLoan(10000);
        Loan::query()->whereKey($loan->id)->update(['principal_amount' => 90000]);

        $first = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole('manager'))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$first->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['approval' => 'The principal is now ₱90,000.00, so the "Head Office" step (from ₱50,000.00) applies. Send the loan back to the Submit step so its approval chain is set again.']);

        $this->assertSame(LoanApprovalStep::STATUS_PENDING, $first->fresh()->status);
    }

    public function test_cda_review_chains_ignore_a_minimum(): void
    {
        $this->putJson('/api/settings/approval-workflow', ['type' => 'cda', 'steps' => [
            ['name' => 'Board review', 'role' => 'manager', 'min_principal' => '5000.00'],
        ]])->assertOk();

        $stored = ApprovalWorkflowSetting::where('type', 'cda')->value('steps');
        $this->assertArrayNotHasKey('min_principal', $stored[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chain(): array
    {
        return [
            ['id' => 'processor', 'name' => 'Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'first', 'name' => 'First Review', 'role' => 'manager', 'kind' => 'approve'],
            ['id' => 'head-office', 'name' => 'Head Office', 'role' => 'bod1', 'kind' => 'approve'],
            ['id' => 'release', 'name' => 'Release', 'role' => 'cashier', 'kind' => 'release'],
        ];
    }

    private function saveChain(): void
    {
        $steps = $this->chain();
        $steps[2]['min_principal'] = '50000.00';

        $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])->assertOk();
    }

    private function submittedLoan(int $principal): Loan
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
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $service->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
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
}
