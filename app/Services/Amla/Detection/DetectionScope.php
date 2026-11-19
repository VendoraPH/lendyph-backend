<?php

namespace App\Services\Amla\Detection;

use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which alerts and CTR candidates a user sees (AMLA design §4.12): head
 * office (`branches:view_all`) sees all; a branch user sees only their
 * branches' records about members. A record with no branch (head office's,
 * such as a cross-branch alert) and every walk-in record need head-office
 * access, as walk-ins' CDD does. A hidden record answers 404 like a missing
 * one.
 */
final class DetectionScope
{
    public function __construct(private readonly BranchAccess $branches) {}

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrain(Builder $query, User $user): Builder
    {
        $ids = $this->branches->for($user);

        return $ids === null
            ? $query
            : $query->whereIn($query->getModel()->qualifyColumn('branch_id'), $ids)->whereNull($query->getModel()->qualifyColumn('gcash_non_member_id'));
    }
}
