<?php

namespace App\Services;

use App\Models\AutoCreditRun;
use Illuminate\Support\Facades\DB;

/**
 * Which share capital auto-credit runs count for a caller (round 9), by the
 * branches each run covered (`auto_credit_run_branches`, written with the run).
 *
 * - A branch user (`$access`, their BranchAccess branches) counts a run only
 *   for the branches it recorded.
 * - A `branches:view_all` caller (`$access` null) counts a run for the
 *   branches it recorded too, and a run made before branches were recorded
 *   (a legacy run) as covering every branch, as the status read it before.
 *
 * A legacy run is one with no recorded branches made before the first run
 * that has some. A later run with none (its caller had no branch) covers
 * nothing. No run is ever backfilled.
 */
final class AutoCreditRunCoverage
{
    private ?int $firstRecordedRunId = null;

    private bool $firstRecordedRunRead = false;

    /**
     * @param  list<int>|null  $access  the caller's branches, null for view_all
     */
    public function __construct(private readonly ?array $access) {}

    /** Whether `$run` covered `$branchId` for this caller. */
    public function covers(AutoCreditRun $run, int $branchId): bool
    {
        if ($this->access !== null && ! in_array($branchId, $this->access, true)) {
            return false;
        }

        return in_array($branchId, $this->branchIdsOf($run), true) || ($this->access === null && $this->isLegacy($run));
    }

    /** Whether `$run` covered any of the caller's branches: any run for view_all. */
    public function coversAny(AutoCreditRun $run): bool
    {
        return $this->access === null || array_intersect($this->branchIdsOf($run), $this->access) !== [];
    }

    /**
     * Whether the caller may see `$run`'s totals: view_all always; a branch
     * user only when every branch the run covered is one of theirs.
     */
    public function totalsVisible(AutoCreditRun $run): bool
    {
        if ($this->access === null) {
            return true;
        }

        $covered = $this->branchIdsOf($run);

        return $covered !== [] && array_diff($covered, $this->access) === [];
    }

    /** The latest run that covered one of the caller's branches: any run for view_all. */
    public function latestCovering(): ?AutoCreditRun
    {
        if ($this->access === []) {
            return null;
        }

        return AutoCreditRun::query()
            ->with('branches:branches.id')
            ->when($this->access !== null, fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereIn('branches.id', $this->access)))
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

    private function isLegacy(AutoCreditRun $run): bool
    {
        if ($this->branchIdsOf($run) !== []) {
            return false;
        }

        if (! $this->firstRecordedRunRead) {
            $first = DB::table('auto_credit_run_branches')->min('auto_credit_run_id');
            $this->firstRecordedRunId = $first === null ? null : (int) $first;
            $this->firstRecordedRunRead = true;
        }

        return $this->firstRecordedRunId === null || $run->id < $this->firstRecordedRunId;
    }
}
