<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsInterestRunLine;

/**
 * Whether an account sits in an interest run that hasn't posted yet (draft or
 * approved). Closing it, or reversing a row on it, would change the figures
 * the run's checker approved, and the run's post would then answer 409, so
 * both are refused until the run is posted or rejected.
 */
final class UnpostedInterestRun
{
    public const STATUSES = ['draft', 'approved'];

    public static function holds(int $accountId): bool
    {
        return SavingsInterestRunLine::query()
            ->where('account_id', $accountId)
            ->whereHas('run', fn ($q) => $q->whereIn('status', self::STATUSES))
            ->exists();
    }
}
