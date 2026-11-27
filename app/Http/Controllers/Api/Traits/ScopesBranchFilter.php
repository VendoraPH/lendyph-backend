<?php

namespace App\Http\Controllers\Api\Traits;

use App\Services\BranchAccess;

/**
 * The branch scope of a report whose builder takes `int|array|null $branchId`
 * (decision B2, least privilege).
 *
 * - A `branch_id` the caller can't see answers 403 (BranchAccess).
 * - A `branch_id` they can see: that branch.
 * - No `branch_id`: every branch the caller holds, or null (every branch,
 *   rows with no branch included) for `branches:view_all`. An empty list, a
 *   user with no branch, matches nothing.
 */
trait ScopesBranchFilter
{
    /**
     * @return int|list<int>|null
     */
    private function branchScope(mixed $branchId): int|array|null
    {
        $access = app(BranchAccess::class);
        $user = request()->user();

        $access->authorizeBranchFilter($user, $branchId);

        return filled($branchId) ? (int) $branchId : $access->for($user);
    }
}
