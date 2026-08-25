<?php

namespace App\Services;

use App\Models\Borrower;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a borrower and everything that hangs off it.
 *
 * This sequence is not optional and not obvious, which is why it lives in one
 * place rather than being written out at each call site:
 *
 *  - `Borrower::booted()` creates a ShareCapitalPledge for EVERY borrower, and
 *    that foreign key is `restrictOnDelete`. So `$borrower->delete()` on its own
 *    always throws — there is no borrower without a pledge.
 *  - `documents` is a morph with no foreign key, so nothing cascades; the rows
 *    and the files behind them orphan silently unless removed here.
 *  - Co-maker documents hang off the co-maker, whose own row cascades — so the
 *    files must be unlinked before the cascade removes the rows pointing at them.
 *
 * `co_makers` and `collaterals` cascade on delete and are left to the database.
 */
class BorrowerPurgeService
{
    /**
     * @param  bool  $audit  When false the borrower is removed with
     *                       `deleteQuietly()`, suppressing the Auditable trait.
     *                       The trait writes the model's full attributes into
     *                       `audit_logs.old_values` — name, birthdate, address,
     *                       contact number, income. That is right for an
     *                       operator deleting a record, and wrong for a
     *                       retention prune, which would otherwise preserve
     *                       forever the personal data it exists to remove. The
     *                       caller is expected to log a summary in its place.
     */
    public function purge(Borrower $borrower, bool $audit = true): void
    {
        $disk = Storage::disk('private');

        /*
         * Files are collected here and unlinked only after the transaction
         * commits.
         *
         * They used to be deleted inline, inside the transaction, which is not
         * safe: the filesystem has no rollback. Any failure after the first
         * unlink — most obviously `$borrower->delete()` throwing on a
         * restrictOnDelete foreign key such as `loans` — restored every database
         * row and left the files gone, so the borrower survived pointing at a
         * photo that no longer existed. On the portfolio box that was three real
         * borrower photos, one 03:30 run away.
         *
         * Deleting after the commit inverts the failure mode: a crash now leaves
         * files whose rows are gone, which is recoverable and detectable, rather
         * than rows whose files are gone, which is neither.
         */
        $paths = [];
        $directories = [];

        DB::transaction(function () use ($borrower, $audit, &$paths, &$directories) {
            foreach ($borrower->coMakers as $coMaker) {
                foreach ($coMaker->documents as $document) {
                    $paths[] = $document->file_path;
                }
                $coMaker->documents()->delete();
            }

            foreach ($borrower->documents as $document) {
                $paths[] = $document->file_path;
            }
            $borrower->documents()->delete();

            if ($borrower->photo_path) {
                $paths[] = $borrower->photo_path;
            }

            // The per-borrower directories, not just the files. Uploads land in
            // documents/valid_id/borrower/{id}/ and borrowers/photos/{id}/, and
            // deleting only the files leaves an empty tree behind that grows by
            // one directory per abandoned application.
            $directories[] = "documents/valid_id/borrower/{$borrower->id}";
            $directories[] = "borrowers/photos/{$borrower->id}";

            // Both are restrictOnDelete; the pledge always exists.
            $borrower->shareCapitalLedger()->delete();
            $borrower->shareCapitalPledge()->delete();

            $audit ? $borrower->delete() : $borrower->deleteQuietly();
        });

        /*
         * afterCommit, not a plain call here: the prune command wraps its audit
         * row and this purge in one transaction, which makes the transaction
         * above a savepoint rather than the outermost one. Unlinking straight
         * after it would still run with the caller's transaction open and
         * reintroduce the same hazard on the outer rollback. With no
         * transaction active — a direct call from the controllers — this runs
         * immediately.
         */
        DB::afterCommit(function () use ($disk, $paths, $directories) {
            foreach (array_filter($paths) as $path) {
                $disk->delete($path);
            }

            foreach ($directories as $directory) {
                $disk->deleteDirectory($directory);
            }
        });
    }
}
