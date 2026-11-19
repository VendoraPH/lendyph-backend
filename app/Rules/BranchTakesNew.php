<?php

namespace App\Rules;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Branches\BranchWriteGuard;
use App\Support\WholeNumberId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A `branch_id` that places something new in a branch (Phase 6, multi-branch
 * decision 2), refused in words when the branch's status doesn't allow it:
 * - active only: new members, loans, imports;
 * - open (`self::openOnly()`): anything else new, which only a closed branch
 *   refuses (a user's assignment, a journal, an expense, a transfer, a GCash
 *   transaction).
 *
 * A branch the viewer can't see is left to the field's `exists` rule (which
 * BranchAccess::constrainBranchExists() narrows to the viewer's branches), so
 * this never names another branch; each field also lists `bail` first. An
 * anonymous caller (public registration) gets wording with no branch name or
 * code. `$unchanged` is the record's current branch: sending it back is never
 * a new placement.
 */
final class BranchTakesNew implements ValidationRule
{
    public const NOTHING_NEW = 'nothing new can be added there';

    public const PUBLIC_REFUSAL = "This branch isn't taking new members right now. Choose another branch.";

    private function __construct(
        private readonly string $consequence,
        private readonly bool $suspendedRefuses,
        private readonly ?User $viewer,
        private readonly ?int $unchanged,
    ) {}

    /** Refused by a suspended or closed branch. */
    public static function activeOnly(string $consequence, ?User $viewer, ?int $unchanged = null): self
    {
        return new self($consequence, true, $viewer, $unchanged);
    }

    /** Refused by a closed branch only. */
    public static function openOnly(?User $viewer, ?int $unchanged = null): self
    {
        return new self(self::NOTHING_NEW, false, $viewer, $unchanged);
    }

    /** A public registration's branch: active only, refused in generic words. */
    public static function forPublicRegistration(): self
    {
        return new self(BranchWriteGuard::MEMBERS, true, null, null);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = WholeNumberId::from($value);

        if ($id === null || $id === $this->unchanged) {
            return;
        }

        if ($this->viewer !== null && ! app(BranchAccess::class)->canSee($this->viewer, $id)) {
            return;
        }

        $branch = Branch::query()->whereKey($id)->first(['id', 'name', 'code', 'status']);

        // A missing branch is the `exists` rule's to report.
        if ($branch === null || $branch->status === Branch::STATUS_ACTIVE) {
            return;
        }

        if ($branch->status === Branch::STATUS_CLOSED || $this->suspendedRefuses) {
            $fail($this->viewer === null ? self::PUBLIC_REFUSAL : BranchWriteGuard::refusal($branch, $this->consequence));
        }
    }
}
