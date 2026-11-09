<?php

namespace Tests\Feature;

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Voiding a loan cancels its pending approval steps, with an audit row
 * (Lead's call 4). Before, a voided loan's chain stayed `pending` and
 * `waiting`, so it still read as waiting on an approver.
 *
 * Only the live round's unfinished steps change. A step already signed off
 * keeps its signature, and an earlier round is history.
 */
class LoanVoidCancelsApprovalStepsTest extends TestCase
{
    use SetupLendyPH;

    private const STEP_IDS = ['processor', 'manager', 'bod', 'release'];

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
                ['id' => 'release', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
            ]],
        );
    }

    private function draftLoan(): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    private function loanForApproval(): Loan
    {
        $loan = $this->draftLoan();
        app(LoanService::class)->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    /**
     * @return array<string, string> each step's status, by step id
     */
    private function statuses(Loan $loan): array
    {
        return $loan->approvalSteps()->reorder('step_order')->pluck('status', 'step_id')->all();
    }

    private function cancelledRows(Loan $loan)
    {
        return AuditLog::query()
            ->where('auditable_type', Loan::class)
            ->where('auditable_id', $loan->id)
            ->where('action', 'loan_chain_cancelled')
            ->get();
    }

    public function test_voiding_a_loan_for_approval_cancels_its_unfinished_steps_with_an_audit_row(): void
    {
        $loan = $this->loanForApproval();
        $before = $this->statuses($loan);
        $open = array_keys(array_filter($before, fn (string $status): bool => in_array($status, ['waiting', 'pending'], true)));
        $this->assertContains('pending', $before, 'the chain is waiting on an approver');
        $this->assertSame(['manager', 'bod', 'release'], $open);

        $this->patchJson("/api/loans/{$loan->id}/void")->assertOk()->assertJsonPath('data.status', 'void');

        $after = $this->statuses($loan);
        foreach (self::STEP_IDS as $stepId) {
            $this->assertSame(
                in_array($stepId, $open, true) ? LoanApprovalStep::STATUS_CANCELLED : $before[$stepId],
                $after[$stepId],
                "step {$stepId}",
            );
        }

        $rows = $this->cancelledRows($loan);
        $this->assertCount(1, $rows);
        $this->assertSame(['round' => 1, 'steps_closed' => $open], $rows->sole()->new_values);
        $this->assertSame($this->admin->id, $rows->sole()->user_id);

        $cancelled = $loan->approvalSteps()->where('status', LoanApprovalStep::STATUS_CANCELLED)->get();
        $this->assertTrue($cancelled->every(fn (LoanApprovalStep $step): bool => $step->acted_by === $this->admin->id && $step->acted_at !== null));

        $this->getJson("/api/loans/{$loan->id}")->assertOk()->assertJsonPath('data.current_approver', null);
        $this->assertNotContains(
            true,
            collect($this->getJson("/api/loans/{$loan->id}/approval-steps")->assertOk()->json('data.current_steps'))->pluck('can_act')->all(),
        );
    }

    public function test_voiding_an_approved_loan_cancels_its_release_step(): void
    {
        $loan = $this->loanForApproval();
        app(LoanService::class)->approve($loan, $this->admin, 'ok');
        $this->assertSame('pending', $this->statuses($loan)['release']);

        $this->patchJson("/api/loans/{$loan->id}/void")->assertOk();

        $after = $this->statuses($loan);
        $this->assertSame(LoanApprovalStep::STATUS_CANCELLED, $after['release']);
        $this->assertSame(['processor' => 'approved', 'manager' => 'approved', 'bod' => 'approved'], array_diff_key($after, ['release' => true]));
        $this->assertSame(['round' => 1, 'steps_closed' => ['release']], $this->cancelledRows($loan)->sole()->new_values);
    }

    public function test_voiding_a_draft_with_no_chain_writes_no_chain_row(): void
    {
        $loan = $this->draftLoan();

        $this->patchJson("/api/loans/{$loan->id}/void")->assertOk();

        $this->assertSame([], $this->statuses($loan));
        $this->assertCount(0, $this->cancelledRows($loan));
    }

    /** A sent-back round is history: only the live round's steps are cancelled. */
    public function test_only_the_live_rounds_steps_are_cancelled(): void
    {
        $loan = $this->loanForApproval();
        $this->assertSame(1, $loan->approvalSteps()->max('round'));

        foreach ($loan->approvalSteps()->get() as $step) {
            LoanApprovalStep::create([...$step->only(['step_order', 'step_id', 'name', 'status_label', 'role', 'kind']), 'loan_id' => $loan->id, 'round' => 2, 'status' => $step->status]);
        }
        $roundOne = $loan->approvalSteps()->where('round', 1)->reorder('step_order')->pluck('status', 'step_id')->all();

        $this->patchJson("/api/loans/{$loan->id}/void")->assertOk();

        $this->assertSame($roundOne, $loan->approvalSteps()->where('round', 1)->reorder('step_order')->pluck('status', 'step_id')->all());
        $this->assertSame(3, $loan->approvalSteps()->where('round', 2)->where('status', LoanApprovalStep::STATUS_CANCELLED)->count());
        $this->assertSame(['round' => 2, 'steps_closed' => ['manager', 'bod', 'release']], $this->cancelledRows($loan)->sole()->new_values);
    }
}
