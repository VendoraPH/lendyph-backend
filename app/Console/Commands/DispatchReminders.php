<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hands due reminders to their driver. Every minute, in the background, for
 * at most 50 seconds or 200 reminders (routes/console/reminders.php). The
 * drivers are log-only: nothing reaches a member.
 */
#[Signature('reminders:dispatch')]
#[Description('Hand due payment reminders to their (log-only) driver, after a final eligibility check')]
class DispatchReminders extends Command
{
    public function handle(ReminderDispatcher $dispatcher): int
    {
        $totals = $dispatcher->run();

        $this->info(sprintf(
            'Processed %d reminder(s): %d sent, %d retrying, %d failed, %d cancelled, %d skipped; %d left by a stopped run.',
            $totals['processed'], $totals['sent'], $totals['retrying'], $totals['failed'], $totals['cancelled'], $totals['skipped'], $totals['recovered'],
        ));

        if ($totals['processed'] > 0 || $totals['recovered'] > 0) {
            Log::info('reminders:dispatch completed', $totals);
        }

        return self::SUCCESS;
    }
}
