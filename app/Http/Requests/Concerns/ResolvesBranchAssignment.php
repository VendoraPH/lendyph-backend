<?php

namespace App\Http\Requests\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Rules\BranchTakesNew;
use App\Services\BranchAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Reconcile the two branch-assignment shapes this API accepts for one release.
 *
 * `branch_id` (an integer, what every client sends today) and `branch_ids` (an
 * array, what the multi-branch client sends) both arrive here, sometimes in the
 * same body, and one set of branches has to come out.
 *
 * ## The rule
 *
 * **`branch_ids` wins whenever it is present.** `branch_id` is the legacy
 * spelling of a one-element set, and a legacy value that repeats what is
 * already stored is a repost, not an instruction.
 *
 *   | payload                          | branches synced             | users.branch_id |
 *   |----------------------------------|-----------------------------|-----------------|
 *   | `branch_ids: [5,7]`              | {5,7}                       | 5               |
 *   | `branch_id: 9`                   | {9}                         | 9               |
 *   | `branch_ids: [5,7], branch_id:9` | {5,7} — the array wins      | 5               |
 *   | `branch_id:` (unchanged value)   | untouched                   | unchanged       |
 *   | neither key                      | untouched                   | unchanged       |
 *
 * ## Why the array wins rather than a union, or a 422 on disagreement
 *
 * The frontend form posts the WHOLE record back on save, so the client being
 * unblocked here will routinely send both keys in one body. Refusing a
 * disagreement would 422 the exact caller this dual contract exists to support.
 *
 * A union is worse than it looks: it makes the old field un-removable. As long
 * as any client keeps sending `branch_id: 5`, branch 5 could never be taken off
 * that user — the one edit a multi-branch screen most obviously has to be able
 * to make. Last-writer-wins on the richer shape is the only rule under which
 * the new client is fully expressive and the old one is unchanged.
 *
 * ## Why an unchanged `branch_id` is not an instruction
 *
 * An old client renders `branch` — one branch — and posts back what it was
 * shown. For a user assigned to {5,7} that is `branch_id: 5`. Treating it as
 * "set the assignment to {5}" would make every save from a not-yet-updated
 * screen silently strip branches it never knew existed. A CHANGED value (5 → 9)
 * is a deliberate reassignment and does replace the set, exactly as it did
 * before this feature.
 *
 * ## Which id ends up in the legacy column
 *
 * `users.branch_id` keeps its current value when that branch is still in the
 * set, and otherwise takes the first of the incoming set. It is therefore
 * always a MEMBER of the set, never a contradiction of it — the singular
 * `branch` the old localStorage sessions render can never name a branch the
 * user is no longer assigned to.
 *
 * Keeping it stable under reordering is also what makes `[5,7] → [7,5]` a
 * genuine no-op. Deriving it as "first of the incoming array" instead would
 * make a pure reorder dirty the users row, and the API would report a change to
 * a payload that assigns exactly the same branches.
 *
 * ## The chosen primary branch (Phase 6 MB-BE6, multi-branch D9)
 *
 * `primary_branch_id`, when sent, IS the column: admins choose it. It must be
 * one of the user's assigned branches (the incoming set, else the stored one).
 * Null reads "Organisation-wide" and is allowed only for a user who, after
 * this request, sees every branch (`branches:view_all`). Absent, the rules
 * above still apply, except that an Organisation-wide user who still sees
 * every branch stays Organisation-wide when an old screen reposts branches.
 */
trait ResolvesBranchAssignment
{
    /**
     * The branches this payload wants assigned, or null when it says nothing
     * about branches at all and the current assignment must not be touched.
     *
     * Reads raw input rather than `validated()` on purpose: `after()` hooks run
     * while the validator is still mid-flight, and `validated()` throws there.
     * Values are coerced defensively for the same reason — when the payload is
     * malformed the request is already failing on `branch_ids.*`, and this must
     * answer without raising on the way.
     *
     * @return list<int>|null
     */
    public function branchAssignment(?User $target = null): ?array
    {
        if ($this->has('branch_ids')) {
            $ids = $this->input('branch_ids');

            if (! is_array($ids)) {
                return [];
            }

            return array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                array_filter($ids, 'is_scalar'),
            )));
        }

        if (! $this->has('branch_id')) {
            return null;
        }

        $branchId = (int) $this->input('branch_id');

        // A legacy value identical to the stored one is a repost of what the
        // old screen displayed, not a request to narrow the assignment to it.
        if ($target !== null && $branchId === (int) $target->branch_id) {
            return null;
        }

        return [$branchId];
    }

    /**
     * The member of the assignment that goes in the legacy `users.branch_id`.
     *
     * Null only when the payload assigns no branches and the target has none,
     * which the `required_without` / `min:1` rules make unreachable through the
     * API today.
     */
    public function primaryBranchId(?User $target = null): ?int
    {
        if ($this->has('primary_branch_id')) {
            $chosen = $this->input('primary_branch_id');

            return is_numeric($chosen) ? (int) $chosen : null;
        }

        $ids = $this->branchAssignment($target);
        $current = $target?->branch_id !== null ? (int) $target->branch_id : null;

        if ($ids === null || $ids === []) {
            return $current;
        }

        if ($target !== null && $current === null && $this->resultSeesAllBranches($target)) {
            return null;
        }

        return $current !== null && in_array($current, $ids, true)
            ? $current
            : $ids[0];
    }

    /**
     * Whether this payload changes the stored primary branch.
     */
    public function changesPrimaryBranch(User $target): bool
    {
        $current = $target->branch_id !== null ? (int) $target->branch_id : null;

        return $this->primaryBranchId($target) !== $current;
    }

    /**
     * The primary branch rules, run from the request's `after()` hook once the
     * fields themselves are valid.
     *
     * The Organisation-wide check runs only when this request decides the
     * primary branch (it sends `primary_branch_id`, creates the user, or
     * changes their role), so a user saved without a branch long ago can
     * still have their name edited.
     */
    protected function validatePrimaryBranch(Validator $validator, ?User $target = null): void
    {
        if ($validator->errors()->hasAny(['primary_branch_id', 'branch_id', 'branch_ids', 'branch_ids.*', 'role'])) {
            return;
        }

        $primary = $this->primaryBranchId($target);

        if ($primary !== null) {
            if ($this->has('primary_branch_id') && ! in_array($primary, $this->resultBranchIds($target), true)) {
                $validator->errors()->add('primary_branch_id', 'The primary branch must be one of the branches this user is assigned to.');
            }

            return;
        }

        $decidesPrimary = $target === null
            || $this->has('primary_branch_id')
            || ($this->has('role') && $this->input('role') !== $target->getRoleNames()->first());

        if ($decidesPrimary && ! $this->resultSeesAllBranches($target)) {
            $validator->errors()->add(
                'primary_branch_id',
                'Only a user whose role sees all branches can be Organisation-wide. Choose one of their branches as the primary branch.',
            );
        }
    }

    /**
     * The branches the user is assigned to once this request is saved.
     *
     * @return list<int>
     */
    private function resultBranchIds(?User $target): array
    {
        $incoming = $this->branchAssignment($target);

        if ($incoming !== null) {
            return $incoming;
        }

        return $target === null ? [] : $target->branches()->pluck('branches.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Whether the user sees every branch once this request is saved: through
     * the role it assigns (an active one, or admin and super_admin, which
     * always count), else their current permissions, plus any permission
     * given to them directly.
     */
    private function resultSeesAllBranches(?User $target): bool
    {
        if ($target !== null && ! $this->has('role')) {
            return $target->can(BranchAccess::VIEW_ALL);
        }

        $direct = $target !== null && $target->getDirectPermissions()->contains('name', BranchAccess::VIEW_ALL);
        $role = Role::query()->where('name', (string) $this->input('role'))->where('guard_name', 'web')->first();

        if ($role === null) {
            return $direct;
        }

        if ($role->name === 'super_admin') {
            return true;
        }

        $active = $role->is_active !== false || in_array($role->name, Role::PROTECTED_KEYS, true);

        return $direct || ($active && $role->permissions->contains('name', BranchAccess::VIEW_ALL));
    }

    /**
     * `exists` on branches, narrowed to the caller's own branches unless they
     * hold `branches:view_all` (BranchAccess). A caller can only hand out
     * access they have.
     */
    protected function assignableBranchRule(): Exists
    {
        return app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user());
    }

    /**
     * A sent `primary_branch_id` that names a closed branch is refused in
     * words, whatever else is allowed: even for a user still assigned to it,
     * or whose stored primary branch it is (Phase 6 MB-BE6 follow-up). A
     * suspended branch can be chosen, as it can be assigned (openOnly()). A
     * branch the caller can't see isn't named: validatePrimaryBranch() refuses
     * it as one the user isn't assigned to.
     */
    protected function primaryBranchRule(): BranchTakesNew
    {
        return BranchTakesNew::openOnly($this->user(), consequence: BranchTakesNew::NO_PRIMARY);
    }
}
