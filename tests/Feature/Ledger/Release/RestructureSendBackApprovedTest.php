<?php

namespace Tests\Feature\Ledger\Release;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\LoanService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * PATCH /loans/{loan}/send-back-approved: an approved restructure that isn't
 * released yet goes back to its approval chain, with a reason, for someone
 * holding `loans:release`. Its figures are then taken again on re-submit and
 * approval, so a source paid down while it waited for release can still be
 * released.
 *
 * createReleasedLoan() leaves ₱60,000.00 principal and ₱10,800.00 interest
 * owed; every restructure here is at ₱50,000.00.
 */
class RestructureSendBackApprovedTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    private const PAID_DOWN = 'The source loan was paid down or adjusted after this restructure was approved. Send it back for approval so the figures can be taken again.';

    private const CONFLICT = 'Another change to this loan was saved at the same time. Reload and try again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_paid_down_approved_restructure_is_sent_back_approved_again_with_the_figures_taken_again_and_released(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        // Period 1 paid while it waits for release: ₱59,000.00 owed.
        $this->payOn($source, 11800);

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => self::PAID_DOWN]);

        $bookkeeper = $this->userWithRole('general_bookkeeper');
        $response = $this->actingAs($bookkeeper)
            ->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => '  The source was paid down.  '])
            ->assertOk()
            ->assertJsonPath('message', 'Restructure sent back for approval.')
            ->assertJsonPath('data.id', $new->id)
            ->assertJsonPath('data.status', 'for_review')
            ->assertJsonPath('data.can_send_back_approved', false);
        $this->assertNull($response->json('data.approved_at'));
        $this->actingAs($this->admin);

        $new->refresh();
        $this->assertSame('for_review', $new->status);
        $this->assertNull($new->approved_by);
        $this->assertNull($new->approved_at);

        // The release step of the approved round is frozen as sent back.
        $sentBack = $new->approvalSteps()->where('round', 1)->where('kind', LoanApprovalStep::KIND_RELEASE)->sole();
        $this->assertSame(LoanApprovalStep::STATUS_SENT_BACK, $sentBack->status);
        $this->assertSame($bookkeeper->id, (int) $sentBack->acted_by);
        $this->assertSame('The source was paid down.', $sentBack->remarks);
        $this->assertSame(0, (int) $sentBack->sent_back_to_step_order);

        // Round 2 starts again at the submit step; nobody's sign-off carries over.
        $round2 = $new->approvalSteps()->where('round', 2)->orderBy('step_order')->get();
        $this->assertSame(
            [LoanApprovalStep::STATUS_PENDING, LoanApprovalStep::STATUS_WAITING, LoanApprovalStep::STATUS_WAITING, LoanApprovalStep::STATUS_WAITING],
            $round2->pluck('status')->all(),
        );
        $this->assertSame([null, null, null, null], $round2->pluck('acted_by')->all());
        $this->assertSame(LoanApprovalStep::KIND_SUBMIT, $round2->first()->kind);

        $this->resubmitAndClimb($new);

        $new->refresh();
        $this->assertSame('approved', $new->status);
        $this->assertSame(['59000.00', '9000.00'], [$new->restructure_outstanding, $new->restructure_shortfall]);
        $retaken = AuditLog::where('action', 'restructure_terms_retaken')->where('auditable_id', $new->id)->sole();
        $this->assertSame('submission', $retaken->new_values['retaken_on']);

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $this->assertSame('restructured', $source->fresh()->status);
        $this->assertSame('9000.00', $source->fresh()->write_off_amount);
        $this->assertSame(['50000.00', '0.00', '0.00', '0.00'], $this->storedSplit($new));
    }

    public function test_the_send_back_writes_one_audit_row_with_new_values_only(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $bookkeeper = $this->userWithRole('general_bookkeeper');

        $this->actingAs($bookkeeper)
            ->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])
            ->assertOk();

        $row = AuditLog::where('action', 'restructure_sent_back')->sole();
        $this->assertSame($new->id, (int) $row->auditable_id);
        $this->assertSame($bookkeeper->id, (int) $row->user_id);
        $this->assertNull($row->old_values);
        $this->assertSame(
            ['reason' => 'Figures changed', 'acted_by' => $bookkeeper->id, 'previous_status' => 'approved'],
            $row->new_values,
        );
        $this->assertStringContainsString($new->application_number, $row->description);
        $this->assertStringContainsString('Figures changed', $row->description);

        // The Audit Trail names who sent it back.
        $this->actingAs($this->admin);
        $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field');
        $this->assertSame($bookkeeper->full_name, $changes['acted_by']['new']);
    }

    public function test_the_creator_hears_it_came_back_and_the_submit_step_hears_it_is_theirs(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $processor = $this->userWithRole('loan_processor');
        $bookkeeper = $this->userWithRole('general_bookkeeper');

        $this->actingAs($bookkeeper)
            ->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])
            ->assertOk();

        // The creator (super_admin) is told it came back, with the reason.
        $this->assertSame([$this->admin->id], $this->recipients(StaffNotification::LOAN_SENT_BACK, $new));
        $body = DatabaseNotification::where('type', StaffNotification::LOAN_SENT_BACK)->sole()->data['body'];
        $this->assertStringContainsString('"Loan Processor" step', $body);
        $this->assertStringContainsString('Reason: Figures changed', $body);
        // The submit step's role is told it is theirs, in round 2; never the actor.
        $this->assertSame([$processor->id], $this->recipients(StaffNotification::APPROVAL_NEEDED, $new, round: 2));
        $this->assertSame([], DatabaseNotification::where('notifiable_id', $bookkeeper->id)->pluck('type')->all());
    }

    public function test_without_loans_release_it_is_refused_with_403_and_writes_nothing(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $manager = $this->userWithRole('manager');
        $before = $this->releaseState();

        $this->actingAs($manager)
            ->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])
            ->assertForbidden();

        $this->assertSame($before, $this->releaseState());
        $this->assertSame(0, DatabaseNotification::where('type', StaffNotification::LOAN_SENT_BACK)->count());
    }

    public function test_a_missing_blank_or_too_long_reason_is_refused_with_422_and_writes_nothing(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $before = $this->releaseState();

        foreach ([[], ['reason' => '   '], ['reason' => null], ['reason' => str_repeat('a', 1001)]] as $body) {
            $this->patchJson("/api/loans/{$new->id}/send-back-approved", $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reason']);
        }

        $this->assertSame($before, $this->releaseState());
    }

    public function test_a_reason_of_1000_characters_is_accepted(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);

        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => str_repeat('a', 1000)])->assertOk();
    }

    public function test_an_ordinary_approved_loan_is_refused_with_422_and_writes_nothing(): void
    {
        $loan = $this->approvedLoan();
        $before = $this->releaseState();

        $this->patchJson("/api/loans/{$loan->id}/send-back-approved", ['reason' => 'Figures changed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Only a restructure can be sent back for approval once it is approved.']);

        $this->assertSame($before, $this->releaseState());
    }

    public function test_a_draft_in_review_or_released_restructure_is_refused_with_422_and_writes_nothing(): void
    {
        $source = $this->createReleasedLoan();
        $draft = $this->draftRestructureOf($source);
        $this->assertRefusedByStatus($draft, 'This restructure has not been submitted for approval yet.');

        $this->patchJson("/api/loans/{$draft->id}/submit")->assertOk();
        $this->assertSame('for_review', $draft->fresh()->status);
        $this->assertRefusedByStatus($draft, 'This restructure has not been approved yet, so it can\'t be sent back from here. Send it back from its approval chain instead.');

        $released = $this->chainApprovedRestructureOf($this->createReleasedLoan());
        $this->patchJson("/api/loans/{$released->id}/release")->assertOk();
        $this->assertRefusedByStatus($released, 'This restructure has already been released, so it can\'t be sent back for approval.');
    }

    public function test_a_second_send_back_from_a_stale_read_answers_409_and_writes_nothing_more(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $stale = Loan::findOrFail($new->id);
        $service = app(LoanService::class);

        $service->sendBackApproved(Loan::findOrFail($new->id), $this->admin, 'First');
        $after = $this->releaseState();

        $this->assertConflict(fn () => $service->sendBackApproved($stale, $this->admin, 'Second'));

        $this->assertSame($after, $this->releaseState());
        $this->assertSame(1, AuditLog::where('action', 'restructure_sent_back')->count());
        $this->assertSame(2, $this->currentRoundOf($new));
    }

    public function test_a_restructure_released_or_voided_since_it_was_read_answers_409_and_writes_nothing(): void
    {
        foreach (['released', 'void'] as $status) {
            $source = $this->createReleasedLoan();
            $new = $this->chainApprovedRestructureOf($source);
            $stale = Loan::findOrFail($new->id);
            DB::table('loans')->where('id', $new->id)->update(['status' => $status]);
            $before = $this->releaseState();

            $this->assertConflict(fn () => app(LoanService::class)->sendBackApproved($stale, $this->admin, 'Too late'), $status);

            $this->assertSame($before, $this->releaseState(), $status);
        }
    }

    public function test_the_refusal_for_a_paid_down_approved_restructure_says_to_send_it_back(): void
    {
        $this->assertSame(self::PAID_DOWN, LoanService::sourceFellMessage('approved'));

        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $this->payOn($source, 11800);

        $this->getJson("/api/loans/{$new->id}/release-preview")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => self::PAID_DOWN]);
    }

    public function test_can_send_back_approved_is_true_only_for_an_approved_restructure_and_a_caller_holding_loans_release(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $ordinary = $this->approvedLoan();

        $bookkeeper = $this->userWithRole('general_bookkeeper');
        $this->actingAs($bookkeeper)->getJson("/api/loans/{$new->id}")->assertOk()->assertJsonPath('data.can_send_back_approved', true);
        $this->actingAs($bookkeeper)->getJson("/api/loans/{$ordinary->id}")->assertOk()->assertJsonPath('data.can_send_back_approved', false);
        $this->actingAs($this->userWithRole('manager'))->getJson("/api/loans/{$new->id}")->assertOk()->assertJsonPath('data.can_send_back_approved', false);
        $this->actingAs($this->admin);

        $inReview = $this->draftRestructureOf($this->createReleasedLoan());
        $this->getJson("/api/loans/{$inReview->id}")->assertOk()->assertJsonPath('data.can_send_back_approved', false);
        $this->patchJson("/api/loans/{$inReview->id}/submit")->assertOk();
        $this->getJson("/api/loans/{$inReview->id}")->assertOk()->assertJsonPath('data.can_send_back_approved', false);

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
        $this->getJson("/api/loans/{$new->id}")->assertOk()->assertJsonPath('data.can_send_back_approved', false);
        $this->getJson("/api/loans/{$source->id}")->assertOk()->assertJsonPath('data.can_send_back_approved', false);
    }

    public function test_a_restructure_approved_directly_is_sent_back_to_the_submit_step(): void
    {
        $source = $this->createReleasedLoan();
        // The single-shot approve closes the chain out of band.
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');

        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])->assertOk();

        $round2 = $new->approvalSteps()->where('round', 2)->orderBy('step_order')->get();
        $this->assertSame(LoanApprovalStep::STATUS_PENDING, $round2->first()->status);
        $this->assertSame(LoanApprovalStep::KIND_SUBMIT, $round2->first()->kind);
        $this->assertSame(LoanApprovalStep::STATUS_SENT_BACK, $new->approvalSteps()->where('round', 1)->where('kind', LoanApprovalStep::KIND_RELEASE)->sole()->status);
        $this->assertSame('for_review', $new->fresh()->status);
    }

    public function test_a_release_between_the_send_back_and_the_re_approval_is_refused_with_422(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])->assertOk();
        $before = $this->releaseState();

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => "Loan must be in 'approved' status to release."]);

        $this->assertSame($before, $this->releaseState());
        $this->assertContains($source->fresh()->status, ['released', 'ongoing']);
    }

    public function test_in_the_new_round_a_single_shot_approve_by_another_role_is_refused(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])->assertOk();
        // loan_officer holds loans:approve and didn't create the restructure.
        $officer = $this->userWithRole('loan_officer');
        $before = $this->releaseState();

        $this->actingAs($officer)
            ->patchJson("/api/loans/{$new->id}/approve", ['approval_remarks' => 'ok'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'This loan is still moving through its approval chain. Sign off on the current step instead.']);

        $this->assertSame($before, $this->releaseState());
        $this->assertSame('for_review', $new->fresh()->status);
    }

    public function test_a_caller_from_another_branch_gets_404_and_nothing_is_written(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->chainApprovedRestructureOf($source);
        // A cashier holds loans:release but not branches:view_all.
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $cashier = User::factory()->create(['branch_id' => $far->id]);
        $cashier->assignRole(Role::where('name', 'cashier')->firstOrFail());
        $this->assertTrue($cashier->can('loans:release'));
        $before = $this->releaseState();

        $this->actingAs($cashier)
            ->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])
            ->assertNotFound();

        $this->assertSame($before, $this->releaseState());
        $this->assertSame('approved', $new->fresh()->status);
    }

    private function assertRefusedByStatus(Loan $loan, string $message): void
    {
        $before = $this->releaseState();

        $this->patchJson("/api/loans/{$loan->id}/send-back-approved", ['reason' => 'Figures changed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => $message]);

        $this->assertSame($before, $this->releaseState(), $loan->fresh()->status);
    }

    private function assertConflict(callable $send, string $context = ''): void
    {
        try {
            $send();
            $this->fail('Expected a 409. '.$context);
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode(), $context);
            $this->assertSame(['message' => self::CONFLICT], json_decode($e->getResponse()->getContent(), true), $context);
        }
    }

    /** A ₱50,000.00 restructure of `$source`, created as super_admin and left a draft. */
    private function draftRestructureOf(Loan $source): Loan
    {
        $id = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 50000,
            'start_date' => now()->toDateString(),
            'remarks' => 'Board approved write-off',
        ])->assertCreated()->json('data.id');

        return Loan::findOrFail($id);
    }

    /** A ₱50,000.00 restructure of `$source`, submitted and signed off step by step. */
    private function chainApprovedRestructureOf(Loan $source): Loan
    {
        $new = $this->draftRestructureOf($source);
        $this->patchJson("/api/loans/{$new->id}/submit")->assertOk();

        while (($step = $this->pendingStep($new))->kind === LoanApprovalStep::KIND_APPROVE) {
            $this->approveStepAs($step->role, $new);
        }

        $this->assertSame('approved', $new->fresh()->status);

        return $new->fresh();
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

    private function approveStepAs(string $role, Loan $loan): void
    {
        $step = $this->pendingStep($loan);
        $this->assertSame($role, $step->role);

        $this->actingAs($this->userWithRole($role))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertOk();
        $this->actingAs($this->admin);
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }

    private function currentRoundOf(Loan $loan): int
    {
        return (int) $loan->approvalSteps()->max('round');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    /** @return list<int> */
    private function recipients(string $type, Loan $loan, ?int $round = null): array
    {
        return DatabaseNotification::query()
            ->where('type', $type)
            ->where('notifiable_type', User::class)
            ->get()
            ->filter(fn (DatabaseNotification $n) => ($n->data['data']['loan_id'] ?? null) === $loan->id)
            ->filter(fn (DatabaseNotification $n) => $round === null || ($n->data['data']['round'] ?? null) === $round)
            ->pluck('notifiable_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }
}
