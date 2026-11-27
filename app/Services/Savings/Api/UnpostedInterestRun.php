<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;

/**
 * Whether an account is held by an interest run that hasn't posted yet
 * (draft or approved). Closing it, or reversing a row on it, would change
 * the figures the run's checker approved, and the run's post would then
 * answer 409, so both are refused until the run is posted or rejected.
 *
 * "Held" is what the run's post re-checks (Interest\RunPoster::post()): the
 * accounts it has lines for, and every account its product, branch and
 * period cover (Interest\RunBuilder::accounts(): opened by the period's end
 * and not closed before its start), whether or not it has a line yet.
 */
final class UnpostedInterestRun
{
    public const STATUSES = ['draft', 'approved'];

    public static function holds(SavingsAccount $account): bool
    {
        $hasLine = SavingsInterestRunLine::query()
            ->where('account_id', $account->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', self::STATUSES))
            ->exists();

        if ($hasLine) {
            return true;
        }

        $closedOn = $account->isClosed() ? $account->closed_on?->toDateString() : null;

        return SavingsInterestRun::query()
            ->whereIn('status', self::STATUSES)
            ->where('product_id', $account->product_id)
            ->where('branch_id', $account->branch_id)
            ->where('period_end', '>=', $account->opened_on->toDateString())
            ->when($closedOn !== null, fn ($q) => $q->where('period_start', '<=', $closedOn))
            ->exists();
    }
}
