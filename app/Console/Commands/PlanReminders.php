<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderPlanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Plans payment reminders and cancels the ones no longer needed. Hourly at
 * :30 Manila (routes/console/reminders.php). Sends nothing.
 */
#[Signature('reminders:plan')]
#[Description('Plan payment reminders for the coming days and cancel the ones no longer needed (sends nothing)')]
class PlanReminders extends Command
{
    public function handle(ReminderPlanner $planner): int
    {
        $result = $planner->plan();

        $line = $result['enabled']
            ? "Planned {$result['planned']} reminder(s), planned {$result['revived']} again, cancelled {$result['cancelled']} no longer needed and {$result['superseded']} superseded."
            : "Reminders are switched off: planned nothing; cancelled {$result['cancelled']} no longer needed.";
        $this->info($line);

        Log::info('reminders:plan completed', $result);

        return self::SUCCESS;
    }
}
