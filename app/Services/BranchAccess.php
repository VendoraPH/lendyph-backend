<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which branches a user may see (decision 5b, least privilege).
 *
 * A user holding `branches:view_all` sees every branch: `for()` answers null,
 * meaning "no restriction". Everyone else sees the branches they are assigned
 * to through the `branch_user` pivot, falling back to the legacy
 * `users.branch_id` column for an account that has no pivot rows. A user with
 * neither sees nothing: `for()` answers an empty array, and every scope built
 * on it matches no rows.
 *
 * Read by the `visibleTo()` local scopes on Loan, Borrower and User and by the
 * controllers that list or bind branch data. Never by a global scope: jobs,
 * commands and reports that aggregate the whole organisation stay unscoped.
 *
 * Memoised per request, so a request that scopes several queries and binds a
 * route model pays for the permission check and the pivot read once.
 */
final class BranchAccess
{
    public const VIEW_ALL = 'branches:view_all';

    private const MEMO_PREFIX = 'branch_access.visible_branch_ids.';

    /**
     * The branch ids $user may see, or null when they may see every branch.
     *
     * @return list<int>|null
     */
    public function for(User $user): ?array
    {
        $request = $this->currentRequest();
        $key = self::MEMO_PREFIX.$user->getKey();

        if ($request !== null && $user->exists && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $ids = $this->resolve($user);

        if ($request !== null && $user->exists) {
            $request->attributes->set($key, $ids);
        }

        return $ids;
    }

    /**
     * Whether $user may see a row belonging to $branchId.
     *
     * A row with no branch is visible only to `branches:view_all`.
     */
    public function canSee(User $user, ?int $branchId): bool
    {
        $ids = $this->for($user);

        if ($ids === null) {
            return true;
        }

        return $branchId !== null && in_array($branchId, $ids, true);
    }

    /**
     * Refuse a list's `?branch_id=` filter naming a branch $user can't see.
     *
     * 403 rather than an empty page: the caller asked for a branch outside
     * their assignment, and an empty result would read as "that branch has no
     * rows". Inside their branches the filter narrows the list as before.
     */
    public function authorizeBranchFilter(User $user, mixed $branchId): void
    {
        if (! filled($branchId)) {
            return;
        }

        abort_unless($this->canSee($user, (int) $branchId), 403, 'You do not have access to this branch.');
    }

    /**
     * @return list<int>|null
     */
    private function resolve(User $user): ?array
    {
        if ($user->can(self::VIEW_ALL)) {
            return null;
        }

        $ids = $user->branches()
            ->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === [] && $user->branch_id !== null) {
            $ids = [(int) $user->branch_id];
        }

        return $ids;
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
