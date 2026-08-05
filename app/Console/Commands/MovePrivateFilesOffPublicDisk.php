<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('files:move-private {--dry-run : List what would move without touching anything}')]
#[Description('Move borrower documents and photos off the web-reachable public disk onto the private disk')]
class MovePrivateFilesOffPublicDisk extends Command
{
    /**
     * Directories that must not be reachable without a signed URL.
     *
     * `branding/` is deliberately absent: the organisation logo is rendered on
     * the sign-in and registration pages before anyone has authenticated, so it
     * has to stay on the public disk.
     */
    private const PRIVATE_DIRECTORIES = ['documents', 'borrowers'];

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('private');

        $moved = 0;
        $skipped = 0;
        $failed = 0;

        foreach (self::PRIVATE_DIRECTORIES as $directory) {
            foreach ($public->allFiles($directory) as $path) {
                // Relative paths are preserved so the file_path / photo_path
                // already stored on each row stays correct — only the disk
                // underneath it changes, so no database update is needed.
                if ($private->exists($path)) {
                    $this->line("  skip (already private) {$path}");
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("  would move {$path}");
                    $moved++;

                    continue;
                }

                $stream = $public->readStream($path);

                if ($stream === null || ! $private->writeStream($path, $stream)) {
                    $this->error("  FAILED {$path}");
                    $failed++;

                    continue;
                }

                // Only unlink once the copy is confirmed on the private disk —
                // a half-finished move would take a borrower's ID with it.
                if ($private->exists($path)) {
                    $public->delete($path);
                    $moved++;
                } else {
                    $this->error("  FAILED (copy not verified) {$path}");
                    $failed++;
                }
            }
        }

        if ($this->option('dry-run')) {
            $this->info("Would move {$moved} file(s); {$skipped} already private.");

            return self::SUCCESS;
        }

        $this->info("Moved {$moved} file(s); {$skipped} already private; {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
