<?php

namespace App\Services\Reminders;

use App\Models\ReminderRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The Manila day a rule fires for a period.
 *
 * - before_due: the due date less the rule's days.
 * - on_due: the due date.
 * - after_due: the later of the due date plus the rule's days and the first
 *   day past the grace period (due + grace + 1), so no overdue reminder goes
 *   out while the member is still inside the grace the contract grants. Due
 *   Sep 15 with 3 days' grace: the 3-day rule fires Sep 19.
 */
final class FireDate
{
    public static function for(ReminderRule $rule, CarbonInterface $dueDate, ?int $graceDays): Carbon
    {
        $due = Carbon::parse($dueDate->format('Y-m-d'), ReminderClock::manila()->getTimezone())->startOfDay();

        return match ($rule->trigger) {
            'before_due' => $due->copy()->subDays($rule->days),
            'after_due' => $due->copy()->addDays(max($rule->days, max(0, (int) $graceDays) + 1)),
            default => $due,
        };
    }
}
