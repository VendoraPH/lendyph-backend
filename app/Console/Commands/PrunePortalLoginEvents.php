<?php

namespace App\Console\Commands;

use App\Models\PortalLoginEvent;
use App\Models\PortalUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Member Portal sign-in history is kept 365 days (portal design §2). Older
 * rows are deleted in chunks; expired portal tokens go with them, since a
 * session list never shows one.
 */
#[Signature('portal:prune-login-events
    {--dry-run : Count what would be deleted without touching anything}')]
#[Description('Delete member portal sign-in history older than 365 days, and expired portal tokens')]
class PrunePortalLoginEvents extends Command
{
    private const CHUNK = 1000;

    public function handle(): int
    {
        $cutoff = now()->subDays(PortalLoginEvent::RETENTION_DAYS);
        $events = fn () => PortalLoginEvent::query()->where('created_at', '<', $cutoff);
        $tokens = fn () => PersonalAccessToken::query()
            ->where('tokenable_type', PortalUser::class)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        if ($this->option('dry-run')) {
            $this->info(sprintf('Would delete %d sign-in events older than %d days and %d expired portal tokens.', $events()->count(), PortalLoginEvent::RETENTION_DAYS, $tokens()->count()));

            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $ids = $events()->orderBy('id')->limit(self::CHUNK)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : PortalLoginEvent::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        $expiredTokens = $tokens()->delete();

        $this->info(sprintf('Deleted %d sign-in events older than %d days and %d expired portal tokens.', $deleted, PortalLoginEvent::RETENTION_DAYS, $expiredTokens));

        return self::SUCCESS;
    }
}
