<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanApprovalStep;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff in-app notifications (decision N, Arvin, 2026-11-02): the one place
 * that decides who hears about a loan event and what it says.
 *
 * Every recipient list goes through the same four filters, whoever the event
 * names: the user is active, holds `loans:view` (through an active role or
 * directly; super_admin always), can see the loan's branch
 * (BranchAccess::canSee, so a loan with no branch reaches only
 * `branches:view_all`), and is not the person who just did the thing. Each
 * user gets one notification per event. The checks run when the notification
 * is sent, so a role switched off or a permission taken away since the loan
 * was created counts.
 *
 * Delivery runs after the surrounding transaction commits (DB::afterCommit),
 * so a release, void or approval that rolls back notifies nobody; outside a
 * transaction it runs at once. Recipients and wording are worked out inside
 * that callback too, so the recipient queries run after the caller's locks
 * are released. A failure while notifying is logged and swallowed: the business
 * action has already committed and must never answer an error because a bell
 * could not be rung. The one exception is loanPastDue(), which the daily
 * command calls inside its own per-loan transaction (see there).
 *
 * Staff only. Nothing here reaches a borrower: the database channel is the
 * only channel, and there is no mail or SMS.
 */
final class Notifications
{
    public function __construct(private BranchAccess $branchAccess) {}

    /**
     * A loan reached an approval step: tell the holders of that step's role.
     * Only active roles count, as in LoanApprovalChainService::canAct(); admin
     * and super_admin are not told about every step they could bypass.
     *
     * @param  list<int>  $skipUserIds  users already told about this move by another notification
     */
    public function approvalNeeded(Loan $loan, LoanApprovalStep $step, ?User $actor = null, array $skipUserIds = []): void
    {
        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->roleHolders($step->role)->reject(fn (User $user) => in_array((int) $user->getKey(), $skipUserIds, true)),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::APPROVAL_NEEDED,
                'Loan awaiting your approval',
                sprintf('%s for %s (%s) is waiting at the "%s" step.', $this->loanLabel($loan), $this->borrowerName($loan), $this->peso($loan->principal_amount), $step->name),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey(), 'approval_step_id' => (int) $step->getKey(), 'round' => (int) $step->round, 'step_order' => (int) $step->step_order],
            ),
        );
    }

    public function loanApproved(Loan $loan, ?User $actor = null): void
    {
        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->creatorAndOfficer($loan),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::LOAN_APPROVED,
                'Loan approved',
                sprintf('%s for %s (%s) was approved%s.', $this->loanLabel($loan), $this->borrowerName($loan), $this->peso($loan->principal_amount), $this->byActor($actor)),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey()],
            ),
        );
    }

    public function loanRejected(Loan $loan, ?User $actor = null, ?string $remarks = null): void
    {
        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->creatorAndOfficer($loan),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::LOAN_REJECTED,
                'Loan rejected',
                sprintf('%s for %s (%s) was rejected%s.%s', $this->loanLabel($loan), $this->borrowerName($loan), $this->peso($loan->principal_amount), $this->byActor($actor), $this->reason($remarks)),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey()],
            ),
        );
    }

    /**
     * @return list<int> the creator's and officer's ids, so the caller can skip
     *                   them for an approval_needed on the same move
     */
    public function loanSentBack(Loan $loan, LoanApprovalStep $targetStep, ?User $actor = null, ?string $remarks = null): array
    {
        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->creatorAndOfficer($loan),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::LOAN_SENT_BACK,
                'Loan sent back for revision',
                sprintf('%s for %s was sent back to the "%s" step%s.%s', $this->loanLabel($loan), $this->borrowerName($loan), $targetStep->name, $this->byActor($actor), $this->reason($remarks)),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey(), 'approval_step_id' => (int) $targetStep->getKey(), 'round' => (int) $targetStep->round, 'step_order' => (int) $targetStep->step_order],
            ),
        );

        return array_values(array_filter([
            $loan->created_by === null ? null : (int) $loan->created_by,
            $loan->account_officer_id === null ? null : (int) $loan->account_officer_id,
        ]));
    }

    /**
     * A loan was released. A restructure's release is the moment the
     * restructure takes effect, so it is announced once, as
     * `restructure_applied`, and not also as `loan_released`.
     */
    public function loanReleased(Loan $loan, ?User $actor = null): void
    {
        if ($loan->source_loan_id !== null) {
            $this->restructuredIntoNewLoan($loan, (int) $loan->source_loan_id, $actor);

            return;
        }

        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->creatorAndOfficer($loan),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::LOAN_RELEASED,
                'Loan released',
                sprintf('%s for %s: %s released%s.', $this->loanLabel($loan), $this->borrowerName($loan), $this->peso($loan->principal_amount), $this->byActor($actor)),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey()],
            ),
        );
    }

    /**
     * A restructure into a new loan took effect: the new loan was released and
     * its source closed. Told to the new loan's creator and assigned officer,
     * the people loan_released reaches, so a loan with no officer still
     * reaches someone. The source is read by id inside the deferred send, so a
     * failing read after the release has committed is logged, never answered
     * as an error.
     */
    private function restructuredIntoNewLoan(Loan $newLoan, int $sourceLoanId, ?User $actor): void
    {
        $this->deliver(
            $newLoan,
            $actor,
            fn (): Collection => $this->creatorAndOfficer($newLoan),
            function () use ($newLoan, $sourceLoanId, $actor): StaffNotification {
                $source = Loan::query()->find($sourceLoanId);

                return new StaffNotification(
                    StaffNotification::RESTRUCTURE_APPLIED,
                    'Loan restructured',
                    sprintf('%s for %s was restructured into %s (%s)%s.', $source === null ? 'The source loan' : $this->loanLabel($source), $this->borrowerName($newLoan), $this->loanLabel($newLoan), $this->peso($newLoan->principal_amount), $this->byActor($actor)),
                    $this->loanUrl($newLoan),
                    ['loan_id' => (int) $newLoan->getKey(), 'source_loan_id' => $sourceLoanId],
                );
            },
        );
    }

    /**
     * An in-place restructure adjustment was applied. For
     * LoanAdjustmentService::applyAdjustment() to call; the actor defaults to
     * the signed-in user.
     */
    public function restructureApplied(Loan $loan, LoanAdjustment $adjustment, ?User $actor = null): void
    {
        $this->adjustmentApplied(StaffNotification::RESTRUCTURE_APPLIED, 'Loan restructured', 'restructured', $loan, $adjustment, $actor);
    }

    /**
     * A term extension was applied. For LoanAdjustmentService to call; the
     * actor defaults to the signed-in user.
     */
    public function extensionApplied(Loan $loan, LoanAdjustment $adjustment, ?User $actor = null): void
    {
        $this->adjustmentApplied(StaffNotification::EXTENSION_APPLIED, 'Loan extended', 'extended', $loan, $adjustment, $actor);
    }

    /**
     * A posted payment was voided: tell the loan's assigned officer and the
     * user who recorded the payment.
     */
    public function paymentVoided(Repayment $repayment, ?User $actor = null, ?string $reason = null): void
    {
        $loan = $repayment->loan;

        if ($loan === null) {
            return;
        }

        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->usersById([$loan->account_officer_id, $repayment->received_by]),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::PAYMENT_VOIDED,
                'Payment voided',
                sprintf('Payment %s of %s on %s for %s was voided%s.%s', $repayment->receipt_number, $this->peso($repayment->amount_paid), $this->loanLabel($loan), $this->borrowerName($loan), $this->byActor($actor), $this->reason($reason)),
                '/payments/'.$repayment->getKey(),
                ['loan_id' => (int) $loan->getKey(), 'repayment_id' => (int) $repayment->getKey()],
            ),
        );
    }

    /**
     * A loan adjustment was requested and waits for approval: tell the holders
     * of `loan_adjustments:approve` (through an active role or directly).
     */
    public function adjustmentPending(LoanAdjustment $adjustment, ?User $actor = null): void
    {
        $loan = $adjustment->loan;

        if ($loan === null) {
            return;
        }

        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->permissionHolders('loan_adjustments:approve'),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::ADJUSTMENT_PENDING,
                'Adjustment awaiting approval',
                sprintf('%s: a %s on %s for %s needs approval.', $adjustment->adjustment_number, $this->adjustmentTypeLabel($adjustment->adjustment_type), $this->loanLabel($loan), $this->borrowerName($loan)),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey(), 'adjustment_id' => (int) $adjustment->getKey()],
            ),
        );
    }

    /**
     * A loan has just passed its grace period. Sent by `notifications:past-due`,
     * which has no actor and owns the once-per-episode rule.
     *
     * Unlike every other event this sends AT ONCE and lets a failure through:
     * the command writes its once-per-episode marker and calls this inside
     * one transaction per loan, so a failed send rolls the marker back and the
     * next run tries the loan again instead of losing its notice.
     */
    public function loanPastDue(Loan $loan, ?string $oldestLateDueDate = null): void
    {
        $this->send(
            $loan,
            null,
            fn (): Collection => $this->usersById([$loan->account_officer_id]),
            fn (): StaffNotification => new StaffNotification(
                StaffNotification::LOAN_PAST_DUE,
                'Loan past due',
                $oldestLateDueDate === null
                    ? sprintf('%s for %s has an instalment past its grace period.', $this->loanLabel($loan), $this->borrowerName($loan))
                    : sprintf('%s for %s has an instalment due %s that is past its grace period.', $this->loanLabel($loan), $this->borrowerName($loan), $oldestLateDueDate),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey()],
            ),
        );
    }

    private function adjustmentApplied(string $type, string $title, string $verb, Loan $loan, LoanAdjustment $adjustment, ?User $actor): void
    {
        $this->deliver(
            $loan,
            $actor,
            fn (): Collection => $this->usersById([$loan->account_officer_id]),
            fn (): StaffNotification => new StaffNotification(
                $type,
                $title,
                sprintf('%s %s %s for %s%s.', $adjustment->adjustment_number, $verb, $this->loanLabel($loan), $this->borrowerName($loan), $this->byActor($actor ?? auth()->user())),
                $this->loanUrl($loan),
                ['loan_id' => (int) $loan->getKey(), 'adjustment_id' => (int) $adjustment->getKey()],
            ),
        );
    }

    /**
     * Send after commit, to the candidates who pass the four filters, and
     * never let a failure reach the caller.
     *
     * @param  Closure(): Collection<int, User>  $candidates
     * @param  Closure(): StaffNotification  $notification
     */
    private function deliver(Loan $loan, ?User $actor, Closure $candidates, Closure $notification): void
    {
        try {
            DB::afterCommit(function () use ($loan, $actor, $candidates, $notification): void {
                // Load-bearing. This runs after the caller's transaction has
                // committed but still inside its LoanWriteTransaction::run()
                // call, which turns a deadlock or lock wait timeout into a 409.
                // Without this catch, a deadlock while sending would answer 409
                // for an extension or payment that already committed, and the
                // client's retry could collect the money twice.
                try {
                    $this->send($loan, $actor, $candidates, $notification);
                } catch (Throwable $e) {
                    $this->logFailure($loan, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($loan, $e);
        }
    }

    /**
     * Send now, to the candidates who pass the four filters. Throws on failure.
     *
     * @param  Closure(): Collection<int, User>  $candidates
     * @param  Closure(): StaffNotification  $notification
     */
    private function send(Loan $loan, ?User $actor, Closure $candidates, Closure $notification): void
    {
        $actorId = ($actor ?? auth()->user())?->getKey();
        $recipients = $this->eligible($loan, $actorId === null ? null : (int) $actorId, $candidates());

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification());
        }
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    private function eligible(Loan $loan, ?int $actorId, Collection $candidates): Collection
    {
        $branchId = $loan->branch_id === null ? null : (int) $loan->branch_id;

        return $candidates
            ->filter(fn ($user) => $user instanceof User && $user->status === 'active')
            ->unique(fn (User $user) => (int) $user->getKey())
            ->reject(fn (User $user) => $actorId !== null && (int) $user->getKey() === $actorId)
            // Through the Gate: super_admin passes (Gate::before), and a role
            // switched off grants nothing (User::hasPermissionViaRole()).
            ->filter(fn (User $user) => $user->can('loans:view'))
            ->filter(fn (User $user) => $this->branchAccess->canSee($user, $branchId))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function creatorAndOfficer(Loan $loan): Collection
    {
        return $this->usersById([$loan->created_by, $loan->account_officer_id]);
    }

    /**
     * @param  array<int, int|string|null>  $ids
     * @return Collection<int, User>
     */
    private function usersById(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => $id !== null))));

        return $ids === [] ? collect() : $this->recipientQuery()->whereKey($ids)->get()->toBase();
    }

    /**
     * Active users holding the role named `$roleName` while that role is
     * active (admin and super_admin always count, as in User::activeRoles()).
     * Matched on the name directly, never through hasRole($string), for the
     * reason LoanApprovalChainService::canAct() gives.
     *
     * @return Collection<int, User>
     */
    private function roleHolders(string $roleName): Collection
    {
        return $this->recipientQuery()
            ->whereHas('roles', fn (Builder $roles) => $this->activeRole($roles)->where('name', $roleName))
            ->get()
            ->toBase();
    }

    /**
     * Active users holding `$permission` through an active role or directly.
     *
     * @return Collection<int, User>
     */
    private function permissionHolders(string $permission): Collection
    {
        return $this->recipientQuery()
            ->where(fn (Builder $users) => $users
                ->whereHas('roles', fn (Builder $roles) => $this->activeRole($roles)
                    ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('name', $permission)))
                ->orWhereHas('permissions', fn (Builder $permissions) => $permissions->where('name', $permission)))
            ->get()
            ->toBase();
    }

    /**
     * Active users with their roles and direct permissions loaded up front,
     * the two relations the `loans:view` check reads (User::activeRoles() and
     * the direct grant), so that check is not two queries per recipient.
     * BranchAccess::canSee() still reads each recipient's branches with a
     * query of its own: it does not use a loaded `branches` relation.
     */
    private function recipientQuery(): Builder
    {
        return User::query()->active()->with(['roles', 'permissions']);
    }

    private function activeRole(Builder $roles): Builder
    {
        return $roles->where(fn (Builder $active) => $active
            ->where('roles.is_active', true)
            ->orWhereIn('roles.name', Role::PROTECTED_KEYS));
    }

    private function logFailure(Loan $loan, Throwable $e): void
    {
        Log::error('An in-app notification could not be sent; the action it reports was kept.', [
            'loan_id' => $loan->getKey(),
            'exception' => $e,
        ]);
    }

    private function loanLabel(Loan $loan): string
    {
        return (string) ($loan->loan_account_number ?: $loan->application_number);
    }

    private function borrowerName(Loan $loan): string
    {
        $name = trim((string) $loan->borrower?->full_name);

        return $name === '' ? 'the borrower' : $name;
    }

    private function loanUrl(Loan $loan): string
    {
        return '/loans/'.$loan->getKey();
    }

    private function peso(mixed $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }

    private function byActor(?User $actor): string
    {
        $name = trim((string) $actor?->full_name);

        return $name === '' ? '' : ' by '.$name;
    }

    private function reason(?string $remarks): string
    {
        $remarks = trim((string) $remarks);

        return $remarks === '' ? '' : ' Reason: '.Str::limit($remarks, 200);
    }

    private function adjustmentTypeLabel(?string $type): string
    {
        return match ($type) {
            'restructure' => 'restructure',
            'penalty_waiver' => 'penalty waiver',
            'balance_adjustment' => 'balance adjustment',
            'term_extension' => 'term extension',
            default => 'loan adjustment',
        };
    }
}
