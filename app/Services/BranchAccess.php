<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Exists;

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
     * Narrow a query to rows whose `$column` is one of $user's branches. Rows
     * with no branch drop out unless $user holds `branches:view_all`.
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrain($query, User $user, string $column)
    {
        $branchIds = $this->for($user);

        return $branchIds === null ? $query : $query->whereIn($column, $branchIds);
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
     * Narrow an `exists` rule on a table with its own `branch_id` (borrowers,
     * loans) to the rows $user may see, so an id in another branch fails with
     * the same "doesn't exist" message as a missing one. Unchanged without a
     * user or with `branches:view_all`.
     */
    public function constrainExists(Exists $rule, ?User $user): Exists
    {
        $branchIds = $user === null ? null : $this->for($user);

        return $branchIds === null ? $rule : $rule->whereIn('branch_id', $branchIds);
    }

    /**
     * Narrow an `exists` rule on `branches` to $user's own branches, for any
     * field that assigns a branch (a user's, a member's, a journal's): a
     * caller can only place things in branches they can see.
     */
    public function constrainBranchExists(Exists $rule, ?User $user): Exists
    {
        $branchIds = $user === null ? null : $this->for($user);

        return $branchIds === null ? $rule : $rule->whereIn('id', $branchIds);
    }

    /**
     * Narrow an `exists` rule on `users` to the users $user may see (those
     * sharing one of their branches, as User::scopeVisibleTo()).
     */
    public function constrainUserExists(Exists $rule, ?User $user): Exists
    {
        $branchIds = $user === null ? null : $this->for($user);

        if ($branchIds === null) {
            return $rule;
        }

        return $rule->where(fn ($query) => $query
            ->whereIn('users.branch_id', $branchIds)
            ->orWhereExists(fn ($pivot) => $pivot->from('branch_user')
                ->whereColumn('branch_user.user_id', 'users.id')
                ->whereIn('branch_user.branch_id', $branchIds)));
    }

    /**
     * The branch a restricted user's new row defaults to: their legacy
     * primary branch, else their first assigned one. Null for
     * `branches:view_all` (an organisation-wide row) and for a user with no
     * branch.
     */
    public function defaultBranchFor(User $user): ?int
    {
        $branchIds = $this->for($user);

        if ($branchIds === null || $branchIds === []) {
            return null;
        }

        $primary = $user->branch_id === null ? null : (int) $user->branch_id;

        return in_array($primary, $branchIds, true) ? $primary : $branchIds[0];
    }

    /**
     * @return list<int>|null
     */
    private function resolve(User $user): ?array
    {
        if ($user->can(self::VIEW_ALL)) {
            return null;
        }

        return self::assignedBranchIds($user, $user->branches()->pluck('branches.id'));
    }

    /**
     * A restricted user's branches from their `branch_user` ids: those ids,
     * or the legacy `users.branch_id` when there are none. The one reading of
     * an assignment, shared by resolve() (which queries the pivot) and
     * UserReach (which reads the loaded `branches` relation), so the two
     * can't drift.
     *
     * @param  iterable<int|string>  $pivotBranchIds
     * @return list<int>
     */
    public static function assignedBranchIds(User $user, iterable $pivotBranchIds): array
    {
        $ids = collect($pivotBranchIds)
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
