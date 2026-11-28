<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cic\File\CicEncryptor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Removes the CIC encryptor's temporary folders (under the private
 * `storage/app/cic-tmp`) that a killed run left behind, once over an hour old
 * (review S-L3). Each encryption removes its own folder when it ends; this
 * hourly sweep covers a worker killed before it could. It runs whether CIC is
 * on or off, since a folder may be left from when it was on. Never follows a
 * symlink.
 */
#[Signature('cic:clear-temp')]
#[Description('Remove CIC encryption temporary folders over an hour old')]
class CicClearTempFolders extends Command
{
    public function handle(CicEncryptor $encryptor): int
    {
        $removed = $encryptor->clearStale();

        $this->info($removed === 0
            ? 'No stale CIC temporary folders.'
            : "Removed {$removed} stale CIC temporary ".str('folder')->plural($removed).'.');

        return self::SUCCESS;
    }
}
