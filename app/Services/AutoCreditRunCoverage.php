<?php

namespace App\Services;

use App\Models\AutoCreditRun;

/**
 * Which share capital auto-credit runs count for a caller (round 9), by what
 * each run covered: its `scope` and its recorded branches
 * (`auto_credit_run_branches`), both written with the run.
 *
 * - `all` (a `branches:view_all` run): every branch, and members with no
 *   branch.
 * - `branches` (a branch user's run): exactly its recorded branches.
 * - null (a run made before round 9): counts only for a `branches:view_all`
 *   caller (`$access` null), as covering everything, as the status read it
 *   before. Never for a branch user. Nothing is backfilled.
 *
 * A branch user (`$access`, their BranchAccess branches) counts a run only for
 * their own branches.
 */
final class AutoCreditRunCoverage
{
    /**
     * @param  list<int>|null  $access  the caller's branches, null for view_all
     */
    public function __construct(private readonly ?array $access) {}

    /**
     * Whether `$run` covered `$branchId` for this caller. A null `$branchId` is
     * the members with no branch, seen by view_all only.
     */
    public function covers(AutoCreditRun $run, ?int $branchId): bool
    {
        if ($this->access !== null && ($branchId === null || ! in_array($branchId, $this->access, true))) {
            return false;
        }

        // An `all` run covers every branch without reading its rows, which are
        // kept only as the record of the branches that existed at run time. So
        // a branch created later reads that cycle as covered; the once-per-cycle
        // rule still skips anyone the run credited.
        return match ($run->scope) {
            AutoCreditRun::SCOPE_ALL => true,
            AutoCreditRun::SCOPE_BRANCHES => $branchId !== null && in_array($branchId, $this->branchIdsOf($run), true),
            default => $this->access === null,
        };
    }

    /** Whether `$run` covered any of the caller's branches: any run for view_all. */
    public function coversAny(AutoCreditRun $run): bool
    {
        if ($this->access === null) {
            return true;
        }

        return match ($run->scope) {
            AutoCreditRun::SCOPE_ALL => $this->access !== [],
            AutoCreditRun::SCOPE_BRANCHES => array_intersect($this->branchIdsOf($run), $this->access) !== [],
            default => false,
        };
    }

    /**
     * Whether the caller may see `$run`'s totals: view_all always; a branch
     * user only for a branch-scoped run whose recorded branches all lie within
     * theirs, never for an `all` run or a run made before.
     */
    public function totalsVisible(AutoCreditRun $run): bool
    {
        if ($this->access === null) {
            return true;
        }

        $covered = $this->branchIdsOf($run);

        return $run->scope === AutoCreditRun::SCOPE_BRANCHES && $covered !== [] && array_diff($covered, $this->access) === [];
    }

    /** The latest run that covered one of the caller's branches: any run for view_all. */
    public function latestCovering(): ?AutoCreditRun
    {
        if ($this->access === []) {
            return null;
        }

        return AutoCreditRun::query()
            ->with('branches:branches.id')
            ->when($this->access !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('scope', AutoCreditRun::SCOPE_ALL)
                ->orWhere(fn ($b) => $b->where('scope', AutoCreditRun::SCOPE_BRANCHES)
                    ->whereHas('branches', fn ($r) => $r->whereIn('branches.id', $this->access)))))
            ->latest('processed_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return list<int>
     */
    private function branchIdsOf(AutoCreditRun $run): array
    {
        return $run->branches->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
