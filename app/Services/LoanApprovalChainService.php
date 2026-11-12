<?php

namespace App\Services;

use App\Models\ApprovalWorkflowSetting;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of `loan_approval_steps`.
 *
 * Owns the multi-step BOD approval chain that used to live in the browser's
 * localStorage. `loans.status` stays the authoritative loan state machine —
 * this service drives the chain and promotes `loans.status` through the
 * EXISTING LoanService transitions at the two points where they line up:
 *
 *   - the chain is seeded inside `draft -> for_review`;
 *   - clearing the last approver runs `for_review -> approved`, so
 *     `Loan::isReleasable` and PATCH /loans/{id}/release work unchanged.
 *
 * Everything between those two points is chain-only and leaves `loans.status`
 * at `for_review`, which is exactly what a send-back needs.
 */
class LoanApprovalChainService
{
    /**
     * Container key for the user a submit forwards its first pending step to.
     * LoanService::submitForReview() resolves this service on its own and
     * calls seed() with no room for it, so submitForwarding() leaves it here
     * for seed() to read, and removes it afterwards.
     */
    private const SUBMIT_FORWARD_KEY = 'loan-approval-chain.submit-forward-to';

    public function __construct(
        private LoanService $loanService,
        private Notifications $notifications,
        private ApprovalAssignees $assignees,
    ) {}

    /**
     * Submit a draft for review and forward its first pending step to a named
     * user (`forward_to_user_id`, PATCH /loans/{loan}/submit). The user is
     * checked in seed(), under the loan's lock, against the step that just
     * became pending: a refusal is a 422 on `forward_to_user_id` and the loan
     * stays a draft.
     */
    public function submitForwarding(Loan $loan, User $submitter, int $forwardToUserId): Loan
    {
        app()->instance(self::SUBMIT_FORWARD_KEY, $forwardToUserId);

        try {
            return $this->loanService->submitForReview($loan, $submitter);
        } finally {
            app()->forgetInstance(self::SUBMIT_FORWARD_KEY);
        }
    }

    /**
     * Snapshot the configured chain onto the loan as round 1.
     *
     * Called from inside LoanService::submitForReview's transaction, so the
     * chain and the `for_review` status commit together or not at all — a loan
     * sitting in for_review with no chain would be un-actionable by anyone.
     *
     * The first step is recorded as already `approved`: the person submitting
     * IS the submit step and they have just acted, so showing them a live
     * Submit button again straight after submitting would be wrong. The second
     * step becomes `pending`.
     *
     * @return Collection<int, LoanApprovalStep>
     */
    public function seed(Loan $loan, ?User $submitter = null): Collection
    {
        // `created_by` is the last resort rather than a nicety: submitForReview
        // is called with no explicit submitter by DemoSeeder and by a dozen
        // tests, and `auth()->user()` is null in console and queue context. The
        // loan's creator is the honest answer there — and without it the
        // promotion branch at the bottom of this method would silently skip.
        $submitter ??= auth()->user() ?? $loan->createdByUser;

        $chain = $this->chainFor($loan);

        // Destructive re-seed, NOT idempotent: this drops any existing chain
        // for the loan, and being a query-builder delete it fires no model
        // events, so the Auditable `deleted` hook never runs. Unreachable today
        // — submitForReview is the only caller and it guards on `draft`, which
        // no path returns a loan to — but do not call this on a live chain.
        $loan->approvalSteps()->delete();

        $now = now();

        foreach ($chain as $order => $step) {
            LoanApprovalStep::create([
                'loan_id' => $loan->id,
                'round' => 1,
                'step_order' => $order,
                'step_id' => $step['id'],
                'name' => $step['name'],
                // The built-in default chains carry no label.
                'status_label' => $step['status'] ?? null,
                'role' => $step['role'],
                'kind' => $step['kind'],
                'status' => $order === 0
                    ? LoanApprovalStep::STATUS_APPROVED
                    : ($order === 1 ? LoanApprovalStep::STATUS_PENDING : LoanApprovalStep::STATUS_WAITING),
                'acted_by' => $order === 0 ? $submitter?->id : null,
                'acted_at' => $order === 0 ? $now : null,
            ]);
        }

        $steps = $this->reload($loan);

        // A two-step chain (submit -> release) is configurable today:
        // ApprovalWorkflowController::validateChain only requires submit first
        // and release last, so the step that just became pending can BE the
        // release step. Promote the loan the same way approve() would, or it
        // would sit in for_review with a pending release step that
        // Loan::isReleasable refuses to release and nothing can move.
        $pending = $steps->firstWhere('status', LoanApprovalStep::STATUS_PENDING);

        $forwardToUserId = app()->bound(self::SUBMIT_FORWARD_KEY) ? app(self::SUBMIT_FORWARD_KEY) : null;

        if ($forwardToUserId !== null) {
            app()->forgetInstance(self::SUBMIT_FORWARD_KEY);
            $assignee = $this->assignForward($loan, $pending, (int) $forwardToUserId, $submitter);

            AuditLogService::log(
                action: 'loan_step_forwarded',
                auditable: $loan,
                newValues: [
                    'round' => $pending->round,
                    'step_order' => $pending->step_order,
                    'step_id' => $pending->step_id,
                    'role' => $pending->role,
                    'forwarded_to' => $assignee->full_name,
                ],
                description: "Loan {$loan->application_number} submitted and forwarded to {$assignee->full_name} at the \"{$pending->name}\" step.",
                userId: $submitter?->id,
            );
        }

        // approveLocked(), not approve(): this runs inside submitForReview's
        // transaction, which locked the loan's row as its first statement, and
        // LoanWriteTransaction::run() must be the outermost.
        if ($pending?->kind === LoanApprovalStep::KIND_RELEASE && $submitter !== null) {
            $this->loanService->approveLocked($loan, $submitter, null);
        }

        $this->notifyApprovalNeeded($loan, $pending, $submitter);

        return $steps;
    }

    /**
     * Sign off the pending step and advance the chain by one.
     *
     * Accepts a `submit`-kind step as well as an `approve`-kind one: after a
     * send-back that targeted the submit step, re-submitting IS an approve on
     * that step. There is deliberately no separate re-submit verb, and no guard
     * on `kind` here.
     *
     * A sign-off that was wrong from the start is refused before the
     * transaction opens (assertActionable()), with the 404, 422 or 403 it
     * always was. Inside LoanWriteTransaction::run(), lockAndGuard() reads the
     * loan and the step again under their locks: one another request signed,
     * sent back or decided since this one read it is a 409, as is a deadlock
     * or a lock wait timeout, and the chain is left exactly as that request
     * left it.
     */
    public function approve(Loan $loan, LoanApprovalStep $step, User $user, ?string $remarks = null, ?int $forwardToUserId = null): LoanApprovalStep
    {
        $this->assertActionable($loan, $step, $user);

        return LoanWriteTransaction::run(function () use ($loan, $step, $user, $remarks, $forwardToUserId) {
            [$loan, $step] = $this->lockAndGuard($loan, $step, $user);

            // Re-submitting after a send-back: a restructure's figures are
            // taken again from what its source owes now.
            if ($step->kind === LoanApprovalStep::KIND_SUBMIT) {
                $this->loanService->retakeRestructureTerms($loan, 'submission', $user);
            }

            $step->update([
                'status' => LoanApprovalStep::STATUS_APPROVED,
                'acted_by' => $user->id,
                'acted_at' => now(),
                'remarks' => $remarks,
            ]);

            $next = $loan->approvalSteps()
                ->where('round', $step->round)
                ->where('step_order', '>', $step->step_order)
                ->orderBy('step_order')
                ->first();

            $next?->update(['status' => LoanApprovalStep::STATUS_PENDING]);

            // Checked against the step that just became pending, under the
            // locks, so the rule reads the chain as this sign-off left it. A
            // refusal rolls the sign-off back with it.
            $assignee = $forwardToUserId === null ? null : $this->assignForward($loan, $next, $forwardToUserId, $user);

            // The chain has cleared every approver — either the next step is
            // the release step, or a misconfigured chain has simply run out.
            // Either way hand over to the existing loan transition so
            // `loans.status`, not this table, remains what the rest of the
            // system reads. Without the null branch a chain whose last step is
            // not a `release` would dead-end: no pending step, no way forward.
            //
            // approveLocked(): this transaction already holds the loan's row.
            if ($next === null || $next->kind === LoanApprovalStep::KIND_RELEASE) {
                $this->loanService->approveLocked($loan, $user, $remarks);
            }

            $this->notifyApprovalNeeded($loan, $next, $user);

            AuditLogService::log(
                action: 'loan_step_approved',
                auditable: $loan,
                newValues: [
                    'round' => $step->round,
                    'step_order' => $step->step_order,
                    'step_id' => $step->step_id,
                    'role' => $step->role,
                    'kind' => $step->kind,
                    'remarks' => $remarks,
                    ...($assignee === null ? [] : ['forwarded_to' => $assignee->full_name]),
                ],
                description: "Approval step \"{$step->name}\" signed off on loan {$loan->application_number} (round {$step->round})"
                    .($assignee === null ? '.' : ", forwarded to {$assignee->full_name} at the \"{$next->name}\" step."),
                userId: $user->id,
            );

            return $step->refresh();
        });
    }

    /**
     * Send the loan back to an earlier step for revision.
     *
     * Freezes the current round as history — the acting step becomes
     * `sent_back` and keeps who/when/why — then opens round N+1 with the target
     * step pending. `loans.status` is untouched and stays `for_review`: a
     * send-back is a move WITHIN review, not a rejection. Terminal rejection is
     * a different action (PATCH /loans/{id}/reject) and voiding is the escape
     * hatch for drafts.
     *
     * Checked before the transaction and again under the locks, as approve()
     * is: a step signed, sent back or decided since it was read is a 409.
     *
     * @return Collection<int, LoanApprovalStep> the new round
     */
    public function sendBack(
        Loan $loan,
        LoanApprovalStep $step,
        int $targetStepOrder,
        User $user,
        string $remarks,
    ): Collection {
        $this->assertActionable($loan, $step, $user);

        return LoanWriteTransaction::run(function () use ($loan, $step, $targetStepOrder, $user, $remarks) {
            [$loan, $step] = $this->lockAndGuard($loan, $step, $user);

            if ($targetStepOrder >= $step->step_order) {
                throw ValidationException::withMessages([
                    'target_step_order' => ['A loan can only be sent back to an earlier step in the chain.'],
                ]);
            }

            $currentRound = $loan->approvalSteps()
                ->where('round', $step->round)
                ->orderBy('step_order')
                ->get();

            if (! $currentRound->contains('step_order', $targetStepOrder)) {
                throw ValidationException::withMessages([
                    'target_step_order' => ['That step does not exist in this loan\'s approval chain.'],
                ]);
            }

            $now = now();

            $step->update([
                'status' => LoanApprovalStep::STATUS_SENT_BACK,
                'acted_by' => $user->id,
                'acted_at' => $now,
                'remarks' => $remarks,
                // Persisted rather than derived: once round N+1 starts moving,
                // which step this round was sent back TO is no longer
                // recoverable from the new round's shape, and the loan detail
                // page prints it in the round summary.
                'sent_back_to_step_order' => $targetStepOrder,
            ]);

            $nextRound = $step->round + 1;

            foreach ($currentRound as $previous) {
                // Steps ahead of the target keep their signoff: sending a loan
                // back to the Loan Processor does not un-approve the people
                // below the person who sent it, and their names have to survive
                // into the new round or round 2 would read as approved-by-nobody.
                $keepsSignoff = $previous->step_order < $targetStepOrder;

                LoanApprovalStep::create([
                    'loan_id' => $loan->id,
                    'round' => $nextRound,
                    'step_order' => $previous->step_order,
                    'step_id' => $previous->step_id,
                    'name' => $previous->name,
                    'status_label' => $previous->status_label,
                    'role' => $previous->role,
                    'kind' => $previous->kind,
                    'status' => match (true) {
                        $keepsSignoff => LoanApprovalStep::STATUS_APPROVED,
                        $previous->step_order === $targetStepOrder => LoanApprovalStep::STATUS_PENDING,
                        default => LoanApprovalStep::STATUS_WAITING,
                    },
                    'acted_by' => $keepsSignoff ? $previous->acted_by : null,
                    'acted_at' => $keepsSignoff ? $previous->acted_at : null,
                    'remarks' => $keepsSignoff ? $previous->remarks : null,
                ]);
            }

            AuditLogService::log(
                action: 'loan_step_sent_back',
                auditable: $loan,
                newValues: [
                    'round' => $step->round,
                    'from_step_order' => $step->step_order,
                    'from_step_id' => $step->step_id,
                    'target_step_order' => $targetStepOrder,
                    'new_round' => $nextRound,
                    'remarks' => $remarks,
                ],
                description: "Loan {$loan->application_number} sent back from \"{$step->name}\" for revision (round {$nextRound} opened).",
                userId: $user->id,
            );

            $newRound = $loan->approvalSteps()->where('round', $nextRound)->orderBy('step_order')->get();

            // The creator and the officer hear that it came back; the target
            // step's role hears that it is theirs to act on, unless they were
            // just told by the first notification.
            $target = $newRound->firstWhere('step_order', $targetStepOrder);
            $toldSentBack = $this->notifications->loanSentBack($loan, $target, $user, $remarks);
            $this->notifyApprovalNeeded($loan, $target, $user, $toldSentBack);

            return $newRound;
        });
    }

    /**
     * Reopen the chain of an approved restructure that is going back for
     * approval. Called only from inside LoanService::sendBackApproved()'s
     * transaction, which holds the restructure's and its source's row locks
     * and has already moved the loan back to `for_review`.
     *
     * Mirrors sendBack(), from the release step: that step is frozen as
     * `sent_back` with who, when and why, and round N+1 opens with the submit
     * step pending and every other step waiting. Nobody's sign-off carries
     * over, so the restructure is re-submitted and approved again, and its
     * figures are taken again at each of those steps. A restructure with no
     * chain on record gets round 1 from the configured chain, submit pending.
     *
     * The creator and the officer hear that it came back, and the submit
     * step's role that it is theirs, after the commit (Notifications).
     *
     * @return Collection<int, LoanApprovalStep> the new round
     */
    public function reopenApprovedRestructure(Loan $loan, User $user, string $reason): Collection
    {
        $round = $loan->approvalSteps()->max('round');
        $currentRound = $round === null
            ? collect()
            : $loan->approvalSteps()->where('round', $round)->orderBy('step_order')->get();

        $template = $currentRound->isEmpty()
            ? collect($this->chainFor($loan))->map(fn (array $step, int $order): array => [
                'step_order' => $order,
                'step_id' => $step['id'],
                'name' => $step['name'],
                'status_label' => $step['status'] ?? null,
                'role' => $step['role'],
                'kind' => $step['kind'],
            ])
            : $currentRound->map(fn (LoanApprovalStep $step): array => $step->only([
                'step_order', 'step_id', 'name', 'status_label', 'role', 'kind',
            ]));

        $submit = $template->firstWhere('kind', LoanApprovalStep::KIND_SUBMIT) ?? $template->sortBy('step_order')->first();
        $targetStepOrder = (int) $submit['step_order'];

        $currentRound
            ->first(fn (LoanApprovalStep $step): bool => $step->kind === LoanApprovalStep::KIND_RELEASE
                && $step->status !== LoanApprovalStep::STATUS_APPROVED)
            ?->update([
                'status' => LoanApprovalStep::STATUS_SENT_BACK,
                'acted_by' => $user->id,
                'acted_at' => now(),
                'remarks' => $reason,
                'sent_back_to_step_order' => $targetStepOrder,
            ]);

        $nextRound = $round === null ? 1 : (int) $round + 1;

        foreach ($template as $step) {
            LoanApprovalStep::create([
                ...$step,
                'loan_id' => $loan->id,
                'round' => $nextRound,
                'status' => (int) $step['step_order'] === $targetStepOrder
                    ? LoanApprovalStep::STATUS_PENDING
                    : LoanApprovalStep::STATUS_WAITING,
            ]);
        }

        $newRound = $loan->approvalSteps()->where('round', $nextRound)->orderBy('step_order')->get();

        $target = $newRound->firstWhere('step_order', $targetStepOrder);
        $toldSentBack = $this->notifications->loanSentBack($loan, $target, $user, $reason);
        $this->notifyApprovalNeeded($loan, $target, $user, $toldSentBack);

        return $newRound;
    }

    /**
     * Close out the chain's release step once the loan has actually been
     * released. Called from inside LoanService::release's transaction.
     *
     * Deliberately matches the release step in ANY unfinished state, not just
     * `pending`. The legacy single-shot PATCH /loans/{id}/approve does not
     * advance the chain, so a loan approved that way reaches release with its
     * release step still `waiting` — filtering on `pending` would silently
     * no-op and leave a released loan whose chain reads as unfinished. That
     * path is live: SetupLendyPH::createReleasedLoan takes it, so most of the
     * test suite produces exactly this shape.
     *
     * No-op for loans released before this table existed, which have no chain.
     */
    /**
     * Close out the chain when the loan was approved by the single-shot
     * endpoint rather than step by step.
     *
     * `admin` and `super_admin` may still take a loan straight to `approved`
     * (LoanService::guardApprovalChainIsClear exempts them). Without this the
     * chain is left disagreeing with the loan: the approve steps stay
     * `pending` while `loans.status` says `approved`, and because `can_act`
     * requires `for_review` for an approve step, that step becomes actionable
     * by nobody — including the admin who just approved. The UI reads the
     * first pending step as the current one, so it renders "waiting for
     * Manager" forever and never reaches the release panel. The loan is
     * releasable by API and unreleasable through the app.
     *
     * Marking the remaining approvers with the actor who overrode them is the
     * honest record: it says the chain was short-circuited and by whom, rather
     * than leaving signatures that were never given.
     */
    public function markApprovedOutOfBand(Loan $loan, User $approver): void
    {
        $round = $this->currentRound($loan);

        $outstanding = $loan->approvalSteps()
            ->where('round', $round)
            ->whereIn('kind', [LoanApprovalStep::KIND_SUBMIT, LoanApprovalStep::KIND_APPROVE])
            ->whereIn('status', [LoanApprovalStep::STATUS_WAITING, LoanApprovalStep::STATUS_PENDING])
            ->orderBy('step_order')
            ->get();

        if ($outstanding->isEmpty()) {
            return;
        }

        foreach ($outstanding as $step) {
            $step->update([
                'status' => LoanApprovalStep::STATUS_APPROVED,
                'acted_by' => $approver->id,
                'acted_at' => now(),
                'remarks' => $step->remarks ?? 'Approved directly, bypassing the remaining chain.',
            ]);
        }

        $loan->approvalSteps()
            ->where('round', $round)
            ->where('kind', LoanApprovalStep::KIND_RELEASE)
            ->where('status', LoanApprovalStep::STATUS_WAITING)
            ->update(['status' => LoanApprovalStep::STATUS_PENDING]);

        AuditLogService::log(
            action: 'loan_chain_short_circuited',
            auditable: $loan,
            newValues: [
                'round' => $round,
                'steps_closed' => $outstanding->pluck('step_id')->all(),
            ],
            description: "Approval chain on loan {$loan->application_number} was closed out by a direct approval "
                ."({$outstanding->count()} step(s) bypassed).",
            userId: $approver->id,
        );
    }

    /**
     * Cancel what is left of the chain of a loan being voided (Lead's call 4):
     * every `waiting` and `pending` step of the live round reads `cancelled`,
     * stamped with who voided it and when, so a voided loan never reads as
     * waiting on an approver and no step of it can be acted on. A step already
     * signed off keeps its signature, and an earlier round is history.
     *
     * Each step's change writes its own audit row (Auditable), and one
     * `loan_chain_cancelled` row against the loan names the steps cancelled
     * (`steps_closed`, which the Audit Trail reads as step names). A loan with
     * nothing unfinished writes nothing. Called from inside
     * LoanService::voidLoan()'s transaction, after the loan row is locked.
     */
    public function cancelOpenSteps(Loan $loan, ?User $actor): void
    {
        $round = $this->currentRound($loan);

        $open = $loan->approvalSteps()
            ->where('round', $round)
            ->whereIn('status', [LoanApprovalStep::STATUS_WAITING, LoanApprovalStep::STATUS_PENDING])
            ->orderBy('step_order')
            ->get();

        if ($open->isEmpty()) {
            return;
        }

        foreach ($open as $step) {
            $step->update([
                'status' => LoanApprovalStep::STATUS_CANCELLED,
                'acted_by' => $actor?->id,
                'acted_at' => now(),
            ]);
        }

        AuditLogService::log(
            action: 'loan_chain_cancelled',
            auditable: $loan,
            newValues: [
                'round' => $round,
                'steps_closed' => $open->pluck('step_id')->all(),
            ],
            description: "Approval chain on loan {$loan->application_number} was cancelled when the loan was voided "
                ."({$open->count()} step(s) cancelled).",
            userId: $actor?->id,
        );
    }

    public function markReleased(Loan $loan, User $releaser): void
    {
        $step = $loan->approvalSteps()
            ->where('round', $this->currentRound($loan))
            ->where('kind', LoanApprovalStep::KIND_RELEASE)
            ->where('status', '!=', LoanApprovalStep::STATUS_APPROVED)
            ->orderBy('step_order')
            ->first();

        if ($step === null) {
            return;
        }

        $step->update([
            'status' => LoanApprovalStep::STATUS_APPROVED,
            'acted_by' => $releaser->id,
            'acted_at' => now(),
        ]);

        AuditLogService::log(
            action: 'loan_step_released',
            auditable: $loan,
            newValues: [
                'round' => $step->round,
                'step_order' => $step->step_order,
                'step_id' => $step->step_id,
                'role' => $step->role,
            ],
            description: "Release step \"{$step->name}\" completed on loan {$loan->application_number}.",
            userId: $releaser->id,
        );
    }

    /**
     * Whether this user may act on this step: the WHO half of the question
     * only — assertActionable adds the state half.
     *
     * Mirrors the client's canUserActOnStep: the role named on the step, with
     * admin/super_admin able to act on anything. A step forwarded to a named
     * user is that user's alone while ApprovalAssignees says they still hold
     * it; otherwise it falls back to the role.
     *
     * The step's own role is matched against the user's role NAMES directly
     * rather than through `hasRole($string)`, because Spatie splits a string
     * containing `|` into alternatives and treats a ULID-shaped string as a
     * role ID. `loan_approval_steps.role` is free text copied from a settings
     * blob, so neither behaviour should be reachable from it.
     *
     * Only active roles count: a deactivated role grants nothing, so a user
     * holding only that role can't act on its step until it's reactivated.
     * admin and super_admin can't be deactivated (Role::PROTECTED_KEYS).
     */
    public function canAct(LoanApprovalStep $step, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->bypasses($user)) {
            return true;
        }

        // A step forwarded to a named user is theirs alone while they could
        // still have been given it (ApprovalAssignees); once they can't, it is
        // the role's again.
        $assignee = $this->assignees->effectiveAssignee($step);

        if ($assignee !== null) {
            return (int) $assignee->getKey() === (int) $user->getKey();
        }

        return $user->activeRoles()->contains('name', $step->role);
    }

    /**
     * Refuse a release by anyone but the user the loan's release step is
     * forwarded to, while that user could still be given it. admin and
     * super_admin may release it anyway; an unassigned release step changes
     * nothing (`loans:release` decides, as before). Called by
     * LoanController::release before its transaction.
     *
     * @throws AuthorizationException
     */
    public function assertMayRelease(Loan $loan, User $user): void
    {
        if ($this->bypasses($user)) {
            return;
        }

        $step = $loan->approvalSteps()
            ->where('round', $this->currentRound($loan))
            ->where('kind', LoanApprovalStep::KIND_RELEASE)
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->first();

        $assignee = $step === null ? null : $this->assignees->effectiveAssignee($step, $loan);

        if ($assignee !== null && (int) $assignee->getKey() !== (int) $user->getKey()) {
            throw new AuthorizationException("This loan was forwarded to {$assignee->full_name} for release.");
        }
    }

    /**
     * The step a caller would forward to from where the loan is now, for
     * GET /loans/{loan}/approval/forward-candidates.
     *
     *   - A draft: the step its submit would make pending, from the chain its
     *     submit would snapshot (chainFor()). The caller must be able to
     *     submit it (SubmitLoanRequest's rule).
     *   - In review: the step after the pending one. The caller must be able
     *     to act on the pending one (canAct()).
     *
     * Null when the pending step is the round's last. An unsaved step for a
     * draft, never written.
     *
     * @return array{step: LoanApprovalStep, last_approve: bool}|null
     *
     * @throws AuthorizationException when the caller can't act
     * @throws ValidationException when the loan is not a draft or in review
     */
    public function forwardTarget(Loan $loan, User $user): ?array
    {
        if ($loan->status === 'draft') {
            $canSubmit = $user->can('loans:update') || ($loan->isRestructure() && $user->can('loans:restructure'));

            if (! $canSubmit) {
                throw new AuthorizationException('You can\'t submit this loan for approval.');
            }

            $chain = $this->chainFor($loan);
            $next = $chain[1] ?? null;

            if ($next === null) {
                return null;
            }

            $step = new LoanApprovalStep([
                'loan_id' => $loan->id,
                'round' => 1,
                'step_order' => 1,
                'step_id' => $next['id'],
                'name' => $next['name'],
                'role' => $next['role'],
                'kind' => $next['kind'],
            ]);

            $later = array_slice($chain, 2);

            return [
                'step' => $step,
                'last_approve' => $step->kind === LoanApprovalStep::KIND_APPROVE
                    && ! collect($later)->contains(fn (array $s) => in_array($s['kind'], [LoanApprovalStep::KIND_SUBMIT, LoanApprovalStep::KIND_APPROVE], true)),
            ];
        }

        if ($loan->status !== 'for_review') {
            throw ValidationException::withMessages([
                'status' => ['Only a draft or a loan for approval can be forwarded.'],
            ]);
        }

        $round = $this->currentRound($loan);
        $current = $loan->approvalSteps()
            ->where('round', $round)
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->first();

        if ($current === null || ! $this->canAct($current, $user)) {
            throw new AuthorizationException('You can\'t act on this loan\'s current approval step.');
        }

        $next = $loan->approvalSteps()
            ->where('round', $round)
            ->where('step_order', '>', $current->step_order)
            ->orderBy('step_order')
            ->first();

        return $next === null ? null : ['step' => $next, 'last_approve' => $this->assignees->isLastApproveStep($next)];
    }

    /**
     * The users `$user` may forward `$step` to, sorted by name.
     *
     * @return SupportCollection<int, User>
     */
    public function forwardCandidates(Loan $loan, LoanApprovalStep $step, bool $lastApprove, User $user): SupportCollection
    {
        return $this->assignees->candidates($loan, $step, $lastApprove, $user);
    }

    /**
     * Check `$userId` against the step that just became pending and store
     * them as its assignee. Runs under the loan's lock (seed() and approve()).
     *
     * @throws ValidationException 422 on `forward_to_user_id`, in words
     */
    private function assignForward(Loan $loan, ?LoanApprovalStep $next, int $userId, ?User $actor): User
    {
        if ($next === null) {
            throw ValidationException::withMessages([
                'forward_to_user_id' => ['This is the last step of the approval chain, so there is nobody to forward it to.'],
            ]);
        }

        $candidate = User::query()->with(['roles', 'permissions'])->find($userId);
        $refusal = $this->assignees->refusal($candidate, $loan, $next, $this->assignees->isLastApproveStep($next), $actor);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['forward_to_user_id' => [$refusal]]);
        }

        $next->update(['assigned_to' => $candidate->id]);

        return $candidate;
    }

    private function bypasses(User $user): bool
    {
        return $user->activeRoles()->contains(fn ($role) => in_array($role->name, LoanApprovalStep::BYPASS_ROLES, true));
    }

    /**
     * Tell the holders of the step that just became pending that it is theirs.
     * Only for a step that is still part of review (submit or approve): a
     * pending release step means the loan is approved, which
     * LoanService::approve() announces as `loan_approved`.
     *
     * @param  list<int>  $skipUserIds
     */
    private function notifyApprovalNeeded(Loan $loan, ?LoanApprovalStep $step, ?User $actor, array $skipUserIds = []): void
    {
        if ($step === null || $step->kind === LoanApprovalStep::KIND_RELEASE) {
            return;
        }

        $this->notifications->approvalNeeded($loan, $step, $actor, $skipUserIds);
    }

    /**
     * The round the loan is currently in — the highest one on record.
     */
    public function currentRound(Loan $loan): int
    {
        return (int) ($loan->approvalSteps()->max('round') ?? 1);
    }

    /**
     * Take the row locks, then run every guard against the LOCKED state.
     *
     * The guards have to be inside the transaction and behind a lock, not only
     * in front of it. Without the lock two requests on the same pending step
     * both read `pending` and both commit; the damaging pair is approve +
     * send-back, which leaves a pending step in the old round AND a pending
     * step in the new one, so the chain forks and the abandoned branch can
     * still be walked all the way to `approved` while the UI shows the loan
     * back in revision.
     *
     * The loan is locked before the step, always in that order and as the
     * first statements of the transaction (the order LoanWriteTransaction
     * describes: the loan row, then the operation's own row), so two chain
     * actions on one loan, or one and a direct approve or reject, queue up
     * instead of deadlocking.
     *
     * The caller ran assertActionable() on what it read before the lock, so a
     * loan that is no longer in review, or a step that is no longer the pending
     * step of the current round, was moved by another request since: a 409,
     * never the 422 a request that was wrong from the start gets.
     *
     * @return array{0: Loan, 1: LoanApprovalStep}
     *
     * @throws HttpResponseException 409 when the loan or the step changed since it was read
     */
    private function lockAndGuard(Loan $loan, LoanApprovalStep $step, User $user): array
    {
        $lockedLoan = Loan::whereKey($loan->getKey())->lockForUpdate()->first();
        $lockedStep = LoanApprovalStep::whereKey($step->getKey())->lockForUpdate()->first();

        if ($lockedLoan === null
            || $lockedStep === null
            || $lockedLoan->status !== 'for_review'
            || $lockedStep->status !== LoanApprovalStep::STATUS_PENDING
            || $lockedStep->round !== $this->currentRound($lockedLoan)
        ) {
            throw LoanWriteTransaction::conflict();
        }

        $this->assertActionable($lockedLoan, $lockedStep, $user);

        return [$lockedLoan, $lockedStep];
    }

    /**
     * Every guard an action has to clear, in the order that gives the most
     * honest error: wrong loan is a routing mistake (404), wrong state is a
     * request the caller could retry later (422), wrong role is a refusal (403).
     */
    private function assertActionable(Loan $loan, LoanApprovalStep $step, User $user): void
    {
        if ((int) $step->loan_id !== (int) $loan->id) {
            throw (new ModelNotFoundException)->setModel(LoanApprovalStep::class, [$step->getKey()]);
        }

        if ($loan->status !== 'for_review') {
            throw ValidationException::withMessages([
                'status' => ["Loan must be in 'for approval' status to act on its approval chain."],
            ]);
        }

        // A step from a round a send-back already closed must stay closed. Its
        // status alone is not enough: the step the send-back did not touch
        // keeps whatever status it had, so a `pending` row can outlive its round.
        if ($step->round !== $this->currentRound($loan)) {
            throw ValidationException::withMessages([
                'step' => ['This approval step belongs to a closed revision round.'],
            ]);
        }

        if ($step->status !== LoanApprovalStep::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'step' => ['This approval step is not awaiting action.'],
            ]);
        }

        if (! $this->canAct($step, $user)) {
            $assignee = $this->assignees->effectiveAssignee($step, $loan);

            throw new AuthorizationException($assignee === null
                ? "This step must be actioned by the \"{$step->role}\" role."
                : "This step was forwarded to {$assignee->full_name}.");
        }
    }

    /**
     * The chain to snapshot: the admin-configured one for this loan's type,
     * falling back to the built-in default when nothing usable has been saved.
     *
     * `policy_exception` loans take the long BOD chain, everything else the
     * short one — the same split ApprovalWorkflowController exposes.
     *
     * @return list<array{id: string, name: string, role: string, kind: string}>
     */
    private function chainFor(Loan $loan): array
    {
        $type = $loan->policy_exception
            ? ApprovalWorkflowSetting::TYPE_POLICY_EXCEPTION
            : ApprovalWorkflowSetting::TYPE_NORMAL;

        $configured = ApprovalWorkflowSetting::where('type', $type)->value('steps');

        $steps = $this->sanitiseChain(is_array($configured) ? $configured : []);

        return $steps === [] ? ApprovalWorkflowSetting::defaultStepsFor($type) : $steps;
    }

    /**
     * Reject a stored chain that would not survive being written to the table.
     *
     * ApprovalWorkflowController::validateChain guards the API write path, but
     * nothing guards the READ: `approval_workflow_settings.steps` is a JSON
     * blob that a console command, a restored dump or an older release could
     * have left malformed. Indexing a missing key would throw inside
     * submitForReview's transaction — a 500 on submitting a loan — and a `kind`
     * outside the enum would be a MySQL strict-mode error. Anything not
     * perfectly shaped falls back to the built-in default instead.
     *
     * `status` is an optional label, so a missing, blank or non-string value
     * becomes null rather than throwing the whole chain back to the default.
     *
     * @param  array<int|string, mixed>  $steps
     * @return list<array{id: string, name: string, role: string, kind: string, status: ?string}>
     */
    private function sanitiseChain(array $steps): array
    {
        $kinds = [
            LoanApprovalStep::KIND_SUBMIT,
            LoanApprovalStep::KIND_APPROVE,
            LoanApprovalStep::KIND_RELEASE,
        ];

        $clean = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                return [];
            }

            foreach (['id', 'name', 'role', 'kind'] as $key) {
                if (! isset($step[$key]) || ! is_scalar($step[$key]) || (string) $step[$key] === '') {
                    return [];
                }
            }

            if (! in_array($step['kind'], $kinds, true)) {
                return [];
            }

            $clean[] = [
                'id' => (string) $step['id'],
                'name' => (string) $step['name'],
                'role' => (string) $step['role'],
                'kind' => (string) $step['kind'],
                'status' => is_string($step['status'] ?? null) && trim($step['status']) !== ''
                    ? mb_substr($step['status'], 0, 100)
                    : null,
            ];
        }

        return $clean;
    }

    /**
     * @return Collection<int, LoanApprovalStep>
     */
    private function reload(Loan $loan): Collection
    {
        return $loan->approvalSteps()->where('round', 1)->orderBy('step_order')->get();
    }
}
