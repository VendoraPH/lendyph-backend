<?php

namespace App\Http\Requests\Concerns;

use App\Services\BranchAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A `branch_id` on a row the caller writes (journal, expense, fund transfer)
 * must be one of the caller's own branches (BranchAccess; any branch with
 * `branches:view_all`). A caller without `view_all` who sends none gets their
 * primary branch, so they never write a row with no branch that they could
 * not read back.
 */
trait AssignsCallerBranch
{
    protected function callerBranchRule(): Exists
    {
        return app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user());
    }

    protected function defaultToCallerBranch(): void
    {
        if ($this->filled('branch_id') || $this->user() === null) {
            return;
        }

        $default = app(BranchAccess::class)->defaultBranchFor($this->user());

        if ($default !== null) {
            $this->merge(['branch_id' => $default]);
        }
    }
}
