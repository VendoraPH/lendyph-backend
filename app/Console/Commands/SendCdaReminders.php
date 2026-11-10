<?php

namespace App\Console\Commands;

use App\Services\Cda\Reminders\DeadlineReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled at 00:50 Manila (routes/console/cda/reminders.php). Sends the
 * CDA deadline milestones crossed today, each once per report and deadline.
 * In-app staff notices only; it writes no files. Safe to run twice.
 */
#[Signature('cda:send-reminders')]
#[Description('Send in-app reminders for CDA report deadlines (30, 15, 7 and 3 days before, on the day, and overdue)')]
class SendCdaReminders extends Command
{
    public function handle(DeadlineReminders $reminders): int
    {
        $result = $reminders->run();

        $this->info("CDA reminders: {$result['sent']} sent, {$result['already_sent']} already sent, {$result['reports']} open reports checked.");

        return self::SUCCESS;
    }
}
