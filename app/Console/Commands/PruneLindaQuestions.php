<?php

namespace App\Console\Commands;

use App\Models\LindaQuestion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retention for Linda's question log (Linda v1 plan, "Privacy and the log"):
 * rows asked more than --days ago (default `linda.retention_days`, 365) are
 * deleted, daily at 04:20 Manila.
 *
 * Deletes in chunks of CHUNK rows, each its own statement, so a first run on a
 * large table never holds one long lock while staff are asking questions.
 * Filters on `asked_at`, which the table indexes.
 */
#[Signature('linda:prune
    {--days= : Days after which a question log row is deleted (1 to 3650; default linda.retention_days)}
    {--dry-run : Count what would be deleted without touching anything}')]
#[Description('Delete Linda question log rows past the retention window')]
class PruneLindaQuestions extends Command
{
    private const CHUNK = 1000;

    /** Ten years: the longest window the option accepts. */
    private const MAX_DAYS = 3650;

    public function handle(): int
    {
        $days = $this->days();

        if ($days === null) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $expired = fn (): Builder => LindaQuestion::query()->where('asked_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info($this->summary('Would delete', $expired()->count(), $days));

            return self::SUCCESS;
        }

        $total = 0;

        do {
            $deleted = $expired()->limit(self::CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        $this->info($this->summary('Deleted', $total, $days));

        return self::SUCCESS;
    }

    /**
     * The window as whole days, from 1 to MAX_DAYS. Anything else is refused,
     * so a typo is reported instead of quietly deleting everything or nothing.
     */
    private function days(): ?int
    {
        $value = (string) ($this->option('days') ?? config('linda.retention_days'));

        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > self::MAX_DAYS) {
            $this->error('--days must be a whole number of days, from 1 to '.self::MAX_DAYS.'.');

            return null;
        }

        return (int) $value;
    }

    private function summary(string $verb, int $count, int $days): string
    {
        return "{$verb} {$count} Linda question log row(s) older than {$days} days.";
    }
}
