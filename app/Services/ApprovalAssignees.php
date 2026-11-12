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
     * Why `$candidate` can't be given `$step`, in words, or null when they can.
     *
     * @param  bool  $lastApprove  whether `$step` is the round's last approve step
     */
    public function refusal(?User $candidate, Loan $loan, LoanApprovalStep $step, bool $lastApprove, ?User $actor = null): ?string
    {
        if ($candidate === null) {
            return 'The selected user was not found.';
        }

        $name = trim((string) $candidate->full_name);

        if ($candidate->status !== 'active') {
            return "{$name}'s account is not active.";
        }

        if ($actor !== null && (int) $candidate->getKey() === (int) $actor->getKey()) {
            return 'You can\'t forward a loan to yourself.';
        }

        if (! $candidate->activeRoles()->contains('name', $step->role)) {
            return "{$name} does not hold the role of the \"{$step->name}\" step.";
        }

        if (! $candidate->can('loans:view')) {
            return "{$name} can't view loans.";
        }

        if (! $this->branchAccess->canSee($candidate, $loan->branch_id === null ? null : (int) $loan->branch_id)) {
            return "{$name} can't see this loan's branch.";
        }

        if ($lastApprove
            && $loan->source_loan_id !== null
            && (int) $loan->created_by === (int) $candidate->getKey()
            && ! $candidate->hasRole('super_admin')
        ) {
            return "{$name} created this restructure, so someone else must approve it.";
        }

        if ($step->kind === LoanApprovalStep::KIND_RELEASE && ! $candidate->can('loans:release')) {
            return "{$name} can't release loans.";
        }

        return null;
    }

    /**
     * The step's assignee while they still could be given it, else null: the
     * step is then for its role's holders again.
     */
    public function effectiveAssignee(LoanApprovalStep $step, ?Loan $loan = null): ?User
    {
        if ($step->assigned_to === null) {
            return null;
        }

        $loan ??= $step->loan;
        $assignee = $step->relationLoaded('assignee') ? $step->assignee : $step->assignee()->first();

        if ($loan === null || $assignee === null) {
            return null;
        }

        return $this->refusal($assignee, $loan, $step, $this->isLastApproveStep($step)) === null ? $assignee : null;
    }

    /**
     * The users `$actor` may forward `$step` to, sorted by name.
     *
     * @return Collection<int, User>
     */
    public function candidates(Loan $loan, LoanApprovalStep $step, bool $lastApprove, User $actor): Collection
    {
        return $this->roleHolders($step->role)
            ->filter(fn (User $user) => $this->refusal($user, $loan, $step, $lastApprove, $actor) === null)
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
