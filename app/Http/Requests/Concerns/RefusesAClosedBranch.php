<?php

namespace App\Http\Requests\Concerns;

use App\Models\Branch;
use App\Rules\BranchTakesNew;
use App\Services\Branches\BranchWriteGuard;
use Illuminate\Validation\Validator;

/**
 * A write that posts into an existing record's branch (a reconciliation
 * action, paying an unpaid expense) is refused while that branch is closed:
 * a closed branch takes nothing new (Phase 6 MB-BE2). The record itself is
 * already scoped to the caller's branches, so naming its branch reveals
 * nothing. Used through FormRequest::after().
 */
trait RefusesAClosedBranch
{
    protected function refuseAClosedBranch(Validator $validator, ?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $branch = Branch::query()->whereKey($branchId)->first(['id', 'name', 'code', 'status']);

        if ($branch?->status === Branch::STATUS_CLOSED) {
            $validator->errors()->add('branch', BranchWriteGuard::refusal($branch, BranchTakesNew::NOTHING_NEW));
        }
    }
}
