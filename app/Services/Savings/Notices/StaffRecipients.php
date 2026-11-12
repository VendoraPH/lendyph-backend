<?php

namespace App\Services\Savings\Notices;

use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a savings staff notice goes to: active users holding a permission
 * through an active role (admin and super_admin always count) or directly,
 * narrowed in SQL as Notifications::permissionHolders() does, then to those
 * who can see the branch, never the people in `$skip`. Shared by the
 * large-transaction alert and the gap round S4 notices.
 */
class StaffRecipients
{
    public function __construct(private readonly BranchAccess $branches) {}

    /**
     * @param  list<int>  $skip
     * @return Collection<int, User>
     */
    public function holding(string $permission, ?int $branchId, array $skip = []): Collection
    {
        $grants = fn (Builder $permissions) => $permissions->where('name', $permission);

        return User::query()->active()
            ->whereNotIn('id', $skip)
            ->where(fn (Builder $users) => $users
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->where(fn (Builder $active) => $active->where('roles.is_active', true)->orWhereIn('roles.name', Role::PROTECTED_KEYS))
                    ->whereHas('permissions', $grants))
                ->orWhereHas('permissions', $grants))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $this->branches->canSee($user, $branchId))
            ->values();
    }
}
