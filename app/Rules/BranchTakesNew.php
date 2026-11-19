<?php

namespace App\Rules;

use App\Models\Branch;
use App\Services\Branches\BranchWriteGuard;
use App\Support\WholeNumberId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A `branch_id` that places something new in a branch (Phase 6, multi-branch
 * decision 2), refused in words when the branch's status doesn't allow it:
 * - active only (the default): new members, loans, imports;
 * - open (`self::openOnly()`): anything else new, which only a closed branch
 *   refuses (a user's assignment, a journal, an expense, a transfer, a GCash
 *   transaction).
 *
 * `$unchanged` is the record's current branch: sending it back is never a
 * new placement. The id's own existence and branch-scope checks stay with the
 * field's `exists` rule (BranchAccess::constrainBranchExists()).
 */
final class BranchTakesNew implements ValidationRule
{
    public const NOTHING_NEW = 'nothing new can be added there';

    private function __construct(
        private readonly string $consequence,
        private readonly bool $suspendedRefuses,
        private readonly ?int $unchanged,
    ) {}

    /** Refused by a suspended or closed branch. */
    public static function activeOnly(string $consequence, ?int $unchanged = null): self
    {
        return new self($consequence, true, $unchanged);
    }

    /** Refused by a closed branch only. */
    public static function openOnly(?int $unchanged = null): self
    {
        return new self(self::NOTHING_NEW, false, $unchanged);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = WholeNumberId::from($value);

        if ($id === null || $id === $this->unchanged) {
            return;
        }

        $branch = Branch::query()->whereKey($id)->first(['id', 'name', 'code', 'status']);

        // A missing branch is the `exists` rule's to report.
        if ($branch === null || $branch->status === Branch::STATUS_ACTIVE) {
            return;
        }

        if ($branch->status === Branch::STATUS_CLOSED || $this->suspendedRefuses) {
            $fail(BranchWriteGuard::refusal($branch, $this->consequence));
        }
    }
}
