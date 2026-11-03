<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Whether a caller may manage a user account: the one rule behind the masked
 * 404s on edit, reset password, deactivate and reactivate
 * (App\Http\Requests\Concerns\MasksUserExistence), and behind `can_manage` on
 * the `GET /users` rows, so the list and the four requests can't disagree.
 *
 * Two boundaries, both required:
 *  - User::canManageAccount(): only a super_admin manages a super_admin.
 *  - reaches(): a caller without `branches:view_all` can't act on an account
 *    holding a permission they lack (so never on a `view_all` account), or one
 *    whose branches aren't all inside their own. Otherwise resetting that
 *    account's password, or editing it, is a way to borrow its reach. Mirrors
 *    AssignableRole. Callers with `view_all` reach everyone, and a caller
 *    always reaches themselves.
 *
 * The caller's permissions and branches are worked out once, here. Each
 * target is read from its relations (`roles.permissions`, `permissions`,
 * `branches`), loaded only when missing, so a list that eager-loads them asks
 * nothing per row.
 */
final class UserReach
{
    /**
     * @param  list<int>|null  $callerBranches  null: `branches:view_all`
     * @param  Collection<int, string>  $callerPermissions
     */
    private function __construct(
        private readonly User $caller,
        private readonly ?array $callerBranches,
        private readonly Collection $callerPermissions,
    ) {}

    public static function of(User $caller): self
    {
        $branches = app(BranchAccess::class)->for($caller);

        return new self(
            $caller,
            $branches,
            $branches === null ? collect() : $caller->getAllPermissions()->pluck('name'),
        );
    }

    /**
     * Whether the caller may edit, reset, deactivate or reactivate $target.
     */
    public function canManage(User $target): bool
    {
        return $this->caller->canManageAccount($target) && $this->reaches($target);
    }

    /**
     * The branch and permission half of canManage().
     */
    public function reaches(User $target): bool
    {
        if ($this->callerBranches === null || (int) $this->caller->getKey() === (int) $target->getKey()) {
            return true;
        }

        $target->loadMissing('roles.permissions', 'permissions', 'branches');

        $targetPermissions = $target->getAllPermissions()->pluck('name');

        if ($targetPermissions->diff($this->callerPermissions)->isNotEmpty()) {
            return false;
        }

        return array_diff($this->branchesOf($target, $targetPermissions), $this->callerBranches) === [];
    }

    /**
     * $target's branches as BranchAccess::for() reads them, from the loaded
     * relations: none to compare for `branches:view_all` (or a super_admin),
     * else the assigned branches, falling back to the legacy `users.branch_id`.
     *
     * @param  Collection<int, string>  $permissions
     * @return list<int>
     */
    private function branchesOf(User $target, Collection $permissions): array
    {
        if ($target->hasRole('super_admin') || $permissions->contains(BranchAccess::VIEW_ALL)) {
            return [];
        }

        $ids = $target->branches
            ->map(fn ($branch) => (int) $branch->getKey())
            ->unique()
            ->values()
            ->all();

        if ($ids === [] && $target->branch_id !== null) {
            $ids = [(int) $target->branch_id];
        }

        return $ids;
    }
}
