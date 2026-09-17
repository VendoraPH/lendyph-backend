<?php

namespace App\Services;

use App\Models\ApprovalWorkflowSetting;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private LoanService $loanService) {}

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

        if ($pending?->kind === LoanApprovalStep::KIND_RELEASE && $submitter !== null) {
            $this->loanService->approve($loan, $submitter, null);
        }

        return $steps;
    }

    /**
     * Sign off the pending step and advance the chain by one.
     *
     * Accepts a `submit`-kind step as well as an `approve`-kind one: after a
     * send-back that targeted the submit step, re-submitting IS an approve on
     * that step. There is deliberately no separate re-submit verb, and no guard
     * on `kind` here.
     */
    public function approve(Loan $loan, LoanApprovalStep $step, User $user, ?string $remarks = null): LoanApprovalStep
    {
        return DB::transaction(function () use ($loan, $step, $user, $remarks) {
            [$loan, $step] = $this->lockAndGuard($loan, $step, $user);

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

            // The chain has cleared every approver — either the next step is
            // the release step, or a misconfigured chain has simply run out.
            // Either way hand over to the existing loan transition so
            // `loans.status`, not this table, remains what the rest of the
            // system reads. Without the null branch a chain whose last step is
            // not a `release` would dead-end: no pending step, no way forward.
            if ($next === null || $next->kind === LoanApprovalStep::KIND_RELEASE) {
                $this->loanService->approve($loan, $user, $remarks);
            }

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
                ],
                description: "Approval step \"{$step->name}\" signed off on loan {$loan->application_number} (round {$step->round}).",
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
     * @return Collection<int, LoanApprovalStep> the new round
     */
    public function sendBack(
        Loan $loan,
        LoanApprovalStep $step,
        int $targetStepOrder,
        User $user,
        string $remarks,
    ): Collection {
        return DB::transaction(function () use ($loan, $step, $targetStepOrder, $user, $remarks) {
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

            return $loan->approvalSteps()->where('round', $nextRound)->orderBy('step_order')->get();
        });
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
     * Whether this user may act on this step, by role alone.
     *
     * Mirrors the client's canUserActOnStep: the role named on the step, with
     * admin/super_admin able to act on anything. This is the ROLE half of the
     * question only — assertActionable adds the state half.
     *
     * The step's own role is matched against the user's role NAMES directly
     * rather than through `hasRole($string)`, because Spatie splits a string
     * containing `|` into alternatives and treats a ULID-shaped string as a
     * role ID. `loan_approval_steps.role` is free text copied from a settings
     * blob, so neither behaviour should be reachable from it.
     */
    public function canAct(LoanApprovalStep $step, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasRole(LoanApprovalStep::BYPASS_ROLES)) {
            return true;
        }

        return $user->roles->contains('name', $step->role);
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
     * The guards have to be inside the transaction and behind a lock, not in
     * front of it. Without the lock two requests on the same pending step both
     * read `pending` and both commit; the damaging pair is approve + send-back,
     * which leaves a pending step in the old round AND a pending step in the
     * new one, so the chain forks and the abandoned branch can still be walked
     * all the way to `approved` while the UI shows the loan back in revision.
     *
     * The loan is locked before the step, always in that order, so two chain
     * actions on one loan queue up instead of deadlocking.
     *
     * @return array{0: Loan, 1: LoanApprovalStep}
     */
    private function lockAndGuard(Loan $loan, LoanApprovalStep $step, User $user): array
    {
        $lockedLoan = Loan::whereKey($loan->getKey())->lockForUpdate()->first();

        if ($lockedLoan === null) {
            throw (new ModelNotFoundException)->setModel(Loan::class, [$loan->getKey()]);
        }

        $lockedStep = LoanApprovalStep::whereKey($step->getKey())->lockForUpdate()->first();

        if ($lockedStep === null) {
            throw (new ModelNotFoundException)->setModel(LoanApprovalStep::class, [$step->getKey()]);
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
                'status' => ["Loan must be in 'for_review' status to act on its approval chain."],
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
            throw new AuthorizationException(
                "This step must be actioned by the \"{$step->role}\" role.",
            );
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
     * @param  array<int|string, mixed>  $steps
     * @return list<array{id: string, name: string, role: string, kind: string}>
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
