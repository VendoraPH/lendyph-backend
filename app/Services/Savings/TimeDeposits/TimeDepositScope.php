<?php

namespace App\Services\Savings\TimeDeposits;

use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The time deposits a caller may see (design §0 "Branches"): those in their
 * branches, or every one with `branches:view_all`. A placement in another
 * branch answers 404, the same as a missing one.
 */
class TimeDepositScope
{
    public function __construct(private readonly BranchAccess $access) {}

    /** @return Builder<TimeDeposit> */
    public function query(User $user): Builder
    {
        return $this->access->constrain(TimeDeposit::query(), $user, 'time_deposits.branch_id');
    }

    /** A placement $user may see, or 404. */
    public function find(User $user, int $id): TimeDeposit
    {
        return $this->query($user)->whereKey($id)->firstOr(fn () => abort(404, 'Time deposit not found.'));
    }
}
