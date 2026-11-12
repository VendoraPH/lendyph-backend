<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a loan's approval step may be forwarded to, and whether a step's
 * assignee still holds it (gap round B2).
 *
 * One rule, read in three places: forwarding (LoanApprovalChainService, under
 * the loan's lock), acting (LoanApprovalChainService::canAct() and the release
 * check) and telling (Notifications). A user may hold a step when they:
 *
 *   - are active;
 *   - hold the step's role, and that role is active;
 *   - hold `loans:view`;
 *   - can see the loan's branch (BranchAccess::canSee);
 *   - are not the restructure's creator, on the last approve step (the
 *     dual-control rule LoanService::guardApproval() applies, super_admin
 *     exempt);
 *   - hold `loans:release` too, on the release step.
 *
 * Forwarding adds one more: nobody forwards a step to themselves.
 */
final class ApprovalAssignees
{
    public function __construct(private BranchAccess $branchAccess) {}

    /**
     * Whether `$candidate` can be given `$step`.
     *
     * A yes or no only, never the reason: the answer to a refused forward is
     * the same whatever failed, so it never tells the caller whether an
     * account exists, is active, or what it holds.
     *
     * @param  bool  $lastApprove  whether `$step` is the round's last approve step
     */
    public function canBeGiven(?User $candidate, Loan $loan, LoanApprovalStep $step, bool $lastApprove, ?User $actor = null): bool
    {
        if ($candidate === null || $candidate->status !== 'active') {
            return false;
        }

        if ($actor !== null && (int) $candidate->getKey() === (int) $actor->getKey()) {
            return false;
        }

        if (! $candidate->activeRoles()->contains('name', $step->role)
            || ! $candidate->can('loans:view')
            || ! $this->branchAccess->canSee($candidate, $loan->branch_id === null ? null : (int) $loan->branch_id)
        ) {
            return false;
        }

        if ($lastApprove
            && $loan->source_loan_id !== null
            && (int) $loan->created_by === (int) $candidate->getKey()
            && ! $candidate->hasRole('super_admin')
        ) {
            return false;
        }

        return $step->kind !== LoanApprovalStep::KIND_RELEASE || $candidate->can('loans:release');
    }

    /**
     * The step's assignee while they still could be given it, else null: the
     * step is then for its role's holders again. A caller that already knows
     * whether the step is its round's last approve step passes it, which
     * saves a query.
     */
    public function effectiveAssignee(LoanApprovalStep $step, ?Loan $loan = null, ?bool $lastApprove = null): ?User
    {
        if ($step->assigned_to === null) {
            return null;
        }

        $loan ??= $step->loan;
        $assignee = $step->relationLoaded('assignee') ? $step->assignee : $step->assignee()->first();

        if ($loan === null || $assignee === null) {
            return null;
        }

        return $this->canBeGiven($assignee, $loan, $step, $lastApprove ?? $this->isLastApproveStep($step)) ? $assignee : null;
    }

    /**
     * The users `$actor` may forward `$step` to, sorted by name.
     *
     * @return Collection<int, User>
     */
    public function candidates(Loan $loan, LoanApprovalStep $step, bool $lastApprove, User $actor): Collection
    {
        return $this->roleHolders($step->role)
            ->filter(fn (User $user) => $this->canBeGiven($user, $loan, $step, $lastApprove, $actor))
            ->sortBy(fn (User $user) => mb_strtolower(trim((string) $user->full_name)))
            ->values();
    }

    /**
     * Active users holding the role named `$roleName` while that role is
     * active (admin and super_admin always count, as in User::activeRoles()),
     * with their roles and direct permissions loaded for the `loans:view`
     * check. Matched on the name directly, never through hasRole($string),
     * for the reason LoanApprovalChainService::canAct() gives.
     *
     * @return Collection<int, User>
     */
    public function roleHolders(string $roleName): Collection
    {
        return User::query()->active()->with(['roles', 'permissions'])
            ->whereHas('roles', fn (Builder $roles) => $roles
                ->where(fn (Builder $active) => $active
                    ->where('roles.is_active', true)
                    ->orWhereIn('roles.name', Role::PROTECTED_KEYS))
                ->where('name', $roleName))
            ->get()
            ->toBase();
    }

    /**
     * Whether `$step` is the last approve step of its round: the one whose
     * sign-off takes the loan to approved, where the restructure rule applies.
     */
    public function isLastApproveStep(LoanApprovalStep $step): bool
    {
        if ($step->kind !== LoanApprovalStep::KIND_APPROVE) {
            return false;
        }

        return ! LoanApprovalStep::query()
            ->where('loan_id', $step->loan_id)
            ->where('round', $step->round)
            ->where('step_order', '>', $step->step_order)
            ->whereIn('kind', [LoanApprovalStep::KIND_SUBMIT, LoanApprovalStep::KIND_APPROVE])
            ->exists();
    }
}
