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
        DB::transaction(function () use ($borrower, $audit) {
            $disk = Storage::disk('private');

            foreach ($borrower->coMakers as $coMaker) {
                foreach ($coMaker->documents as $document) {
                    $disk->delete($document->file_path);
                }
                $coMaker->documents()->delete();
            }

            foreach ($borrower->documents as $document) {
                $disk->delete($document->file_path);
            }
            $borrower->documents()->delete();

            if ($borrower->photo_path) {
                $disk->delete($borrower->photo_path);
            }

            // The per-borrower directories, not just the files. Uploads land in
            // documents/valid_id/borrower/{id}/ and borrowers/photos/{id}/, and
            // deleting only the files leaves an empty tree behind that grows by
            // one directory per abandoned application.
            $disk->deleteDirectory("documents/valid_id/borrower/{$borrower->id}");
            $disk->deleteDirectory("borrowers/photos/{$borrower->id}");

            // Both are restrictOnDelete; the pledge always exists.
            $borrower->shareCapitalLedger()->delete();
            $borrower->shareCapitalPledge()->delete();

            $audit ? $borrower->delete() : $borrower->deleteQuietly();
        });
    }
}
