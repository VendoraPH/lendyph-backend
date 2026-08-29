<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\CsvImportRow;
use App\Services\CsvImport\CsvImportRowRedactor;
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
 *  - `csv_import_rows.borrower_id` is nullOnDelete, which is right for the import
 *    record and wrong for the person in it: the delete cut the link and left the
 *    member's whole CSV line intact in `raw`. So the staged rows are redacted
 *    here, and BEFORE the delete, while the foreign key still points at them.
 *
 * `co_makers` and `collaterals` cascade on delete and are left to the database.
 */
class BorrowerPurgeService
{
    public function __construct(private readonly CsvImportRowRedactor $redactor) {}

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

            /*
             * The member's staged CSV lines, blanked while they can still be
             * found.
             *
             * ORDER IS THE WHOLE POINT. `csv_import_rows.borrower_id` is
             * nullOnDelete — deliberately, so deleting an imported borrower does
             * not erase the record that the import created them. The cost of
             * that decision is that the delete below severs the only link to
             * these rows, and what it leaves behind is not a stub: `raw` holds
             * every cell of that member's line verbatim and `normalized` holds
             * the parsed version — name, birthdate, contact number, address,
             * income — still served by `GET /api/imports/{run}/errors` and its
             * CSV download for the life of the deployment. An orphaned row with
             * the data still in it is not an erasure. Running this after
             * `$borrower->delete()` would find nothing to redact.
             *
             * Redacted rather than deleted, through the same
             * CsvImportRowRedactor the scheduled `imports:redact-rows` uses —
             * which is where what a redaction blanks, what it keeps and why is
             * documented, and where the guard against restamping a row an
             * earlier sweep already blanked lives. Removing the rows outright
             * would change an import's arithmetic months after the fact; the
             * status endpoint and the error summary count them.
             *
             * Unconditional — it ignores `$audit`, and that is not an oversight.
             * The flag exists because the Auditable trait copies the borrower's
             * full attributes into `audit_logs.old_values`; this writes no
             * personal data anywhere, in either mode, so there is nothing for
             * the flag to decide. An operator deleting a member by hand has the
             * same right to have this data gone as a retention prune does.
             *
             * Inside the transaction because it must roll back with the delete:
             * a purge that throws on `loans.borrower_id` — restrictOnDelete —
             * must not leave a surviving borrower whose import history has been
             * wiped. Unlike the files below, this one CAN roll back, so it does.
             */
            $this->redactor->redact(
                CsvImportRow::query()->where('borrower_id', $borrower->id),
            );

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
