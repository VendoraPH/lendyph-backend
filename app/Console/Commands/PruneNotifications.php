<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Retention for the staff notification bell (round r7, decision 3).
 *
 * Every release, approval and past-due notice writes one row per recipient and
 * nothing removed them, so the table only grew. Read notifications go once
 * they're older than --read-days; any notification, read or not, goes once
 * it's older than --days, so an account nobody signs in to doesn't keep its
 * rows forever.
 *
 * Deletes in chunks of CHUNK rows, each its own statement, so a first run on a
 * large table never holds one long lock while every signed-in browser polls
 * the unread count. Both passes filter on `created_at`, which the
 * 2026_11_03_120000 migration indexes.
 */
#[Signature('notifications:prune
    {--read-days=90 : Days after which a read notification is deleted}
    {--days=365 : Days after which any notification is deleted, read or not}
    {--dry-run : Count what would be deleted without touching anything}')]
#[Description('Delete read notifications past the read window and any notification past the outer window')]
class PruneNotifications extends Command
{
    private const CHUNK = 1000;

    public function handle(): int
    {
        $readDays = $this->days('read-days');
        $days = $this->days('days');

        if ($readDays === null || $days === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $outerCutoff = $now->copy()->subDays($days);
        $readCutoff = $now->copy()->subDays($readDays);

        $expired = fn (): Builder => DatabaseNotification::query()
            ->where('created_at', '<', $outerCutoff);

        // Rows past the outer window are counted there, never twice.
        $readExpired = fn (): Builder => DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', $readCutoff)
            ->where('created_at', '>=', $outerCutoff);

        if ($dryRun) {
            $this->info($this->summary('Would delete', $expired()->count(), $days, $readExpired()->count(), $readDays));

            return self::SUCCESS;
        }

        $expiredDeleted = $this->deleteInChunks($expired);
        $readDeleted = $this->deleteInChunks($readExpired);

        $this->info($this->summary('Deleted', $expiredDeleted, $days, $readDeleted, $readDays));

        return self::SUCCESS;
    }

    /**
     * @param  callable(): Builder  $query
     */
    private function deleteInChunks(callable $query): int
    {
        $total = 0;

        do {
            $deleted = $query()->limit(self::CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }

    /**
     * A window option as whole days, at least 1. Anything else is refused, so
     * a typo can't turn into "delete everything".
     */
    private function days(string $option): ?int
    {
        $value = (string) $this->option($option);

        if (! ctype_digit($value) || (int) $value < 1) {
            $this->error("--{$option} must be a whole number of days, at least 1.");

            return null;
        }

        return (int) $value;
    }

    private function summary(string $verb, int $expired, int $days, int $read, int $readDays): string
    {
        return "{$verb} {$expired} notification(s) older than {$days} days and {$read} read notification(s) older than {$readDays} days.";
    }
}
