<?php

namespace App\Console\Commands;

use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Services\CsvImport\CsvImportRowRedactor;
use App\Services\CsvImport\ImportErrorDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('imports:redact-rows
    {--days=30 : Days a run must have been finished before its staged rows are redacted}
    {--dry-run : Report what would be redacted without writing anything}')]
#[Description('Blank the personal data staged in csv_import_rows for long-finished import runs')]
class RedactCsvImportRows extends Command
{
    /**
     * Retention for the one copy of a cooperative's membership register that
     * nothing else removes.
     *
     * The uploaded CSV is already deleted the moment a run closes — see
     * CsvImportUploadService::releaseStorage(), which does it precisely because
     * an assembled customers file is every member's name, birthdate, contact
     * number and income in plaintext. But staging copied all of it into
     * `csv_import_rows.raw` and `.normalized` first, and those columns had no
     * retention decision at all: they sit there for the life of the deployment,
     * readable through `GET /api/imports/{run}/errors` and its CSV download by
     * anyone who can reach the import screens.
     *
     * REDACTION, NOT DELETION. The rows are also the arithmetic. Every count on
     * the status endpoint and every group on the error summary is derived from
     * `line_number`, `status`, `result` and `result_category`, and deleting rows
     * would quietly change numbers an operator may have to reconcile against a
     * cooperative's own books years later. Blanking the personal columns leaves
     * all four in place — the run still reports 4,812 rows, 312 of them
     * `product_not_mapped` — and takes the people out of it. What the report
     * loses is the per-field detail and the offending cell, which is the point.
     *
     * WHAT IT DOES NOT COVER, stated so nobody assumes otherwise: this is a
     * clock, not an erasure path. A member who asks to be forgotten before the
     * clock expires is handled by BorrowerPurgeService, which redacts their
     * staged rows through the same definition at the moment they are deleted —
     * and which finds them whether or not the import ever linked them to a
     * borrower, because `csv_import_rows.external_account_no` is recorded for
     * every staged row. What is left for this command is the row that names
     * nobody on file at all: a line whose account number never became a member
     * and never will.
     *
     * THE WINDOW IS NOT 30 DAYS FOR EVERY RUN, and the difference is worth
     * writing down rather than rediscovering. `--days` counts from the run's
     * TERMINAL transition, not from the upload. A run an admin simply abandoned
     * has no terminal transition until `imports:process` writes it off, and that
     * sweep runs at `--prune-days=14`. So an abandoned run's staged rows — a
     * full membership register, readable by any admin through the error report —
     * live for roughly 14 + 30 = 44 days, not 30. That is policy rather than a
     * bug, and both numbers are operator-tunable, but a retention window quoted
     * as "30 days" is wrong for the case most likely to produce one.
     */
    public function handle(CsvImportRowRedactor $redactor): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $runs = $this->candidates($cutoff);

        if ($runs->isEmpty()) {
            $this->info("No import run has been finished for more than {$days} day(s) with staged rows still to redact.");

            return self::SUCCESS;
        }

        $rowsAffected = 0;
        $runsAffected = 0;
        $failed = 0;

        foreach ($runs as $run) {
            $fileIds = $run->files->pluck('id')->all();
            $pending = $this->unredactedCount($fileIds);

            $this->line(sprintf(
                '  %s run #%d (%s, finished %s): %d row(s)',
                $dryRun ? 'would redact' : 'redacting',
                $run->id,
                $run->phase,
                $this->terminalAt($run)->toDateString(),
                $pending,
            ));

            if ($dryRun) {
                $rowsAffected += $pending;
                $runsAffected++;

                continue;
            }

            try {
                $rowsAffected += $this->redact($redactor, $fileIds);
                $this->markRunRedacted($run);
                $runsAffected++;
            } catch (Throwable $e) {
                /*
                 * Never $e->getMessage(), on either line. A QueryException's
                 * message is the failing SQL with its bindings substituted in,
                 * and this command's statements bind rows out of the widest
                 * table of personal data in the schema — so interpolating it
                 * would print a member onto an operator's terminal and write
                 * them into a log that never rotates. That the redactor's own
                 * bindings happen to be file ids, nulls and timestamps today is
                 * not a rule anything enforces; the rule is this class. See
                 * ImportErrorDigest.
                 */
                $this->error("  failed run #{$run->id}: {$this->describe($e)}");

                Log::warning('imports:redact-rows failed to redact a run', [
                    'csv_import_run_id' => $run->id,
                ] + ImportErrorDigest::context($e));

                // The full message is not discarded — it goes to the dedicated,
                // opt-in, restricted channel, which is the only sanctioned place
                // for it.
                ImportErrorDigest::recordDiagnostics($e, [
                    'csv_import_run_id' => $run->id,
                    'command' => 'imports:redact-rows',
                ]);

                $failed++;
            }
        }

        $verb = $dryRun ? 'Would redact' : 'Redacted';
        $this->info("{$verb} {$rowsAffected} staged row(s) across {$runsAffected} run(s); {$failed} failed.");

        /*
         * The scheduler runs from root cron as `schedule:run >> /dev/null 2>&1`,
         * so everything printed above is written to nothing and the exit code is
         * discarded with it. A retention job nobody can confirm ever ran is not
         * a retention job — Laravel's log is the only channel that survives the
         * redirect, and this line is the evidence that the personal data went.
         */
        if (! $dryRun) {
            Log::info('imports:redact-rows completed', [
                'days' => $days,
                'cutoff' => $cutoff->toDateTimeString(),
                'runs_redacted' => $runsAffected,
                'rows_redacted' => $rowsAffected,
                'runs_failed' => $failed,
            ]);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Runs that are over, old enough, and still holding personal data.
     *
     * @return Collection<int, CsvImportRun>
     */
    private function candidates(Carbon $cutoff): Collection
    {
        return CsvImportRun::query()
            ->with('files:id,csv_import_run_id')
            ->whereIn('phase', CsvImportRun::closedPhases())
            /*
             * `rows_redacted_at` is what keeps this from re-reading a finished
             * run's rows every night forever. The per-row guard in redact()
             * decides WHAT to blank and is the one that makes a half-finished
             * sweep resumable; this decides whether the run has to be looked at
             * at all, and it is the difference between a nightly index lookup
             * over a few dozen run rows and a nightly scan of the widest table
             * in the schema.
             */
            ->whereNull('rows_redacted_at')
            /*
             * COALESCE, not `finished_at` alone. Both the importer and the
             * upload service stamp `finished_at` on every terminal transition
             * today, but a run that reached a closed phase without one — hand
             * corrected, or a path added later — would have a NULL here, and
             * `NULL < ?` is never true. That run would become immortal: closed,
             * unredactable, holding a full membership register. `updated_at` is
             * the conservative fallback because it can only ever be LATER than
             * the real finish, so the worst it costs is a few more days of
             * retention, never an early redaction.
             */
            ->whereRaw('COALESCE(`finished_at`, `updated_at`) < ?', [$cutoff])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $fileIds
     */
    private function unredactedCount(array $fileIds): int
    {
        if ($fileIds === []) {
            return 0;
        }

        return CsvImportRow::query()
            ->whereIn('csv_import_file_id', $fileIds)
            ->unredacted()
            ->count();
    }

    /**
     * Blank one run's rows.
     *
     * What "blank" means — which columns go, which stay and why the counts
     * survive — lives in CsvImportRowRedactor, because BorrowerPurgeService
     * needs the identical operation for a right to erasure and two copies of it
     * would drift. This command supplies the clock and the scope; it does not
     * get its own opinion about the columns.
     *
     * No transaction around it, on purpose: an interruption leaves the rows
     * already redacted redacted and the run unstamped, so the next night
     * resumes rather than repeating.
     *
     * @param  list<int>  $fileIds
     */
    private function redact(CsvImportRowRedactor $redactor, array $fileIds): int
    {
        if ($fileIds === []) {
            return 0;
        }

        return $redactor->redact(
            CsvImportRow::query()->whereIn('csv_import_file_id', $fileIds),
        );
    }

    /**
     * Stamped even when the run had nothing left to redact — that is what takes
     * an already-clean run out of the candidate set for good instead of
     * recounting its rows every night.
     *
     * Through the query builder rather than `$run->update()`, for two reasons
     * that both bite. Eloquent would bump `updated_at`, and RunStatusReader
     * derives `last_advanced_at` from exactly that column — a retention sweep
     * would make a run that finished three months ago report as having advanced
     * seconds ago. And a model save fires `CsvImportRun::saved`, the retention
     * listener that releases a run's storage; it guards on `wasChanged('phase')`
     * and would return early today, but a scheduled job has no business
     * depending on that.
     */
    private function markRunRedacted(CsvImportRun $run): void
    {
        DB::table('csv_import_runs')
            ->where('id', $run->id)
            ->update(['rows_redacted_at' => now()]);
    }

    private function terminalAt(CsvImportRun $run): Carbon
    {
        return $run->finished_at ?? $run->updated_at ?? now();
    }

    /**
     * One line naming a failure, with nothing in it that came out of the
     * database.
     *
     * Both halves are safe by construction and are the same two ImportErrorDigest
     * itself composes in forRun(): a driver error NUMBER, e.g. `1205`, and an
     * exception CLASS, which is a symbol out of this repository. Neither can be
     * a value out of a member's row.
     *
     * Written here rather than delegated to ImportErrorDigest::forRun() only
     * because that method's prose belongs to the importer — "This run was
     * stopped… nothing further was written" — and a redaction sweep that failed
     * part way through has, by design, already redacted everything it reached.
     * Telling an operator otherwise would be wrong in the one direction that
     * matters for a retention job.
     */
    private function describe(Throwable $e): string
    {
        $code = ImportErrorDigest::driverCode($e);

        return $code === null
            ? 'unexpected error ('.class_basename($e).')'
            : "database error ({$code})";
    }
}
