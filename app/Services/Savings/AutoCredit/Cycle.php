<?php

namespace App\Services\Savings\AutoCredit;

use App\Models\SavingsAutoCreditInstruction;
use Carbon\CarbonImmutable;

/**
 * An auto-credit cycle (gap round S3): a 15th (slot `15`) or a month's last
 * day (slot `30`, "the 30th": Feb 28 or 29, Nov 30, Dec 31), the slots share
 * capital pledges use. A run may be for today or a cycle up to
 * {@see self::LOOKBACK_DAYS} days ago (a late run), never a future one.
 */
final class Cycle
{
    /** How far back a late run can reach: a quarter, like a reconciliation period. */
    public const LOOKBACK_DAYS = 92;

    /** The slot a date is, or null when it is neither a 15th nor a month end. */
    public static function slotOf(string $date): ?string
    {
        $day = CarbonImmutable::parse($date);

        return match (true) {
            $day->day === 15 => SavingsAutoCreditInstruction::SCHEDULE_15,
            $day->day === $day->daysInMonth => SavingsAutoCreditInstruction::SCHEDULE_30,
            default => null,
        };
    }

    /** "Nov 15, 2026", for remarks and messages. */
    public static function words(string $date): string
    {
        return CarbonImmutable::parse($date)->format('M j, Y');
    }
}
