<?php

namespace App\Rules;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Branches\BranchWriteGuard;
use App\Support\WholeNumberId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A member id whose branch takes the new thing (Phase 6, multi-branch
 * decision 2): a loan, a restructure, a savings account or a time deposit is
 * booked in the member's branch, so a suspended or closed branch refuses it,
 * in words. A member the caller can't see is left to the field's `exists`
 * rule, so this never names another branch; a member with no branch is
 * MemberHasBranch's to report.
 */
final class MemberBranchTakesNew implements ValidationRule
{
    public function __construct(
        private readonly string $consequence,
        private readonly ?User $viewer,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = WholeNumberId::from($value);

        if ($id === null) {
            return;
        }

        $branchId = Borrower::query()->whereKey($id)->value('branch_id');

        if ($branchId === null || ($this->viewer !== null && ! app(BranchAccess::class)->canSee($this->viewer, (int) $branchId))) {
            return;
        }

        $branch = Branch::query()->whereKey($branchId)->first(['id', 'name', 'code', 'status']);

        if ($branch !== null && $branch->status !== Branch::STATUS_ACTIVE) {
            $fail(BranchWriteGuard::refusal($branch, $this->consequence));
        }
    }
}
