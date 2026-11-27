<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderMessageRedactor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention for reminder text: blanks each message's recipient, subject and
 * body once its loan has been closed for two years, like imports:redact-rows
 * does for staged import rows. Daily at 04:15 Manila
 * (routes/console/reminders.php). `--dry-run` counts and writes nothing.
 *
 * The scheduler's output goes nowhere, so the Log line is the record that it ran.
 */
#[Signature('reminders:redact-messages
    {--dry-run : Report what would be redacted without writing anything}')]
#[Description('Blank the recipient and text of reminder messages two years after their loan closed')]
class RedactReminderMessages extends Command
{
    public function handle(ReminderMessageRedactor $redactor): int
    {
        $years = max(1, (int) config('reminders.retention_years', 2));
        $cutoff = now()->subYears($years);
        $dryRun = (bool) $this->option('dry-run');

        $count = $dryRun ? $redactor->pending($cutoff) : $redactor->redact($cutoff);
        $verb = $dryRun ? 'Would redact' : 'Redacted';
        $this->info("{$verb} {$count} message(s) whose loan closed more than {$years} year(s) ago.");

        Log::info('reminders:redact-messages completed', ['dry_run' => $dryRun, 'messages' => $count, 'years' => $years]);

        return self::SUCCESS;
    }
}
