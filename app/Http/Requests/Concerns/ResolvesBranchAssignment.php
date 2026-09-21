<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;

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
        $ids = $this->branchAssignment($target);
        $current = $target?->branch_id !== null ? (int) $target->branch_id : null;

        if ($ids === null || $ids === []) {
            return $current;
        }

        return $current !== null && in_array($current, $ids, true)
            ? $current
            : $ids[0];
    }
}
