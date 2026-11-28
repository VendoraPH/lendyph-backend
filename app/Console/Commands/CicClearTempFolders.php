<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicCache;
use App\Services\Cic\File\CicEncryptor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The CIC module's hourly clean-up of what a killed run or an expired cache
 * entry leaves behind. It runs whether CIC is on or off, since leftovers may
 * be from when it was on.
 *
 * - The CIC encryptor's temporary folders (under the private
 *   `storage/app/cic-tmp`) over an hour old (review S-L3). Each encryption
 *   removes its own; this covers a worker killed before it could. Never
 *   follows a symlink.
 * - Captures stuck in `capturing` for over an hour are failed, and their
 *   partial records deleted (re-review SN4).
 * - Expired `cic:` rows of the database cache store, which removes an expired
 *   row only when its key is read again (re-review SN1).
 */
#[Signature('cic:clear-temp')]
#[Description('Remove CIC leftovers: old encryption folders, stuck captures and expired cache rows')]
class CicClearTempFolders extends Command
{
    public function handle(CicEncryptor $encryptor, CicCaptureService $captures): int
    {
        $removed = $encryptor->clearStale();

        $this->info($removed === 0
            ? 'No stale CIC temporary folders.'
            : "Removed {$removed} stale CIC temporary ".str('folder')->plural($removed).'.');

        $failed = $captures->failStuck();

        $this->info($failed === 0
            ? 'No stuck CIC captures.'
            : "Marked {$failed} stuck CIC ".str('capture')->plural($failed).' failed.');

        $pruned = CicCache::pruneExpired();

        $this->info($pruned === 0
            ? 'No expired CIC cache entries.'
            : "Removed {$pruned} expired CIC cache ".str('entry')->plural($pruned).'.');

        return self::SUCCESS;
    }
}
