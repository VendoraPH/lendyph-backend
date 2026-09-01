<?php

namespace App\Console\Commands;

use App\Models\CsvImportRun;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\ImportErrorDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Advance every in-flight CSV import run by whatever fits in one minute.
 *
 * ## Why this is scheduled and not queued
 *
 * `QUEUE_CONNECTION=database`, the `jobs` and `failed_jobs` tables exist, and
 * they are empty — because THERE IS NO QUEUE WORKER ON ANY BOX. No
 * `queue:work`, no Horizon, no supervisor program, on any of the five
 * deployments. A dispatched job would insert a row into `jobs` and sit there
 * forever while the import screen polled a run that never moved: no error, no
 * failed job, no log line, nothing to see. Total silent failure, and the first
 * anyone would know is an admin asking why the import has been at 0% since
 * Tuesday.
 *
 * `schedule:run` already runs every minute from root cron on all five, so a
 * scheduled command is the only background mechanism on these servers that is
 * actually running. If a worker is ever deployed this can become a job; until
 * then, dispatching one would be a bug that looks like a feature.
 *
 * CONVERTING IT TO A JOB REQUIRES RE-REVIEWING AuditLogService's SUPPRESSION
 * FLAG, and this is not a formality. `AuditLogService::$modelAuditingSuppressed`
 * is a static, so its scope is the PHP PROCESS, not the run — while
 * CsvImportProcessor::writeChunk() holds it, nothing anywhere in that process
 * writes a model audit row. It cannot leak today for one reason only: the
 * scheduler forks its own OS process per command and there is no queue worker,
 * so the only thing alive in that process is this import. A queue worker is the
 * opposite — one long-lived process handling many jobs, and if a job ever runs
 * concurrently with the import (or the flag is left set by an unusual exit), a
 * teller's borrower edit handled in the same worker loses its audit row
 * silently. Before this becomes a job, that flag has to move to something with
 * a narrower scope than the process.
 *
 * ## Why it returns quickly
 *
 * `withoutOverlapping(10)` takes a cache lock for the length of the run and
 * releases it on exit. A command that ran for twenty minutes would hold that
 * lock and block every tick behind it; worse, a command that DIED holding it
 * blocks everything for the full ten-minute expiry. So this exits on a budget
 * — 50 seconds or 500 rows, whichever comes first — with the cursor committed
 * and SUCCESS. The next minute's tick picks the work straight back up, because
 * the resume point is re-derived from the rows themselves and never from where
 * this process thought it had got to.
 *
 * ## Why everything goes through Log
 *
 * The scheduler runs as `schedule:run >> /dev/null 2>&1` and the exit code is
 * discarded, so `$this->info()` is written to nowhere and a FAILURE return
 * tells nobody anything (see PruneAbandonedRegistrations for the same finding).
 * Laravel's log is the only channel that survives, so every meaningful event —
 * start, per-tick progress, every caught row exception, phase transitions,
 * completion — is logged there. Console output is kept as well, for the case
 * where an operator runs this by hand.
 *
 * ## Why it never CREATES a file
 *
 * The scheduler runs as root; php-fpm runs as www-data. Anything root creates
 * under `storage/app/private` is root-owned 0600 and the web process can never
 * read it back — and that only shows up in production. So this command, and
 * everything it calls, creates no file on the private disk.
 *
 * CREATES is the whole of the rule, and the two things it does NOT say are both
 * true of this command today:
 *
 *  - IT RESOLVES THE `private` DISK, AND RESOLVING IT MKDIRS THE ROOT.
 *    FilesystemManager::createLocalDriver() builds the adapter with
 *    `$lazyRootCreation = false`, so `Storage::disk('private')` creates
 *    `storage/app/private` if it is absent — no read and no write required.
 *    CsvImportReader::readFromDisk() does exactly that, from this command. On a
 *    box where that directory already exists this is a no-op; on a fresh
 *    deployment where the scheduler ticks before the first upload, root creates
 *    the disk root itself and www-data is locked out of it. Deployment must
 *    therefore keep creating and chowning `storage/app/private` before anything
 *    runs, and this is why.
 *  - IT DELETES FROM THAT DISK, VIA A LISTENER IT DOES NOT CALL DIRECTLY.
 *    CsvImportUploadService listens for a run reaching a terminal phase and
 *    releases its files, so self::writeOff() and self::pruneAbandonedRuns()
 *    both cause real unlinks when they save a run. self::releaseOrphanedStorage()
 *    then reconciles what the listener missed.
 *
 * Deleting is safe where creating is not: unlinking creates nothing and needs
 * only write permission on the parent directory, which root has unconditionally.
 * The uid reasoning above is still the right rule — it is a rule about
 * ownership of NEW files, and nothing more. What is unsafe is deleting the row
 * that points at them.
 *
 * THE LOG FILE IS THE ONE EXCEPTION, and it is a standing hazard rather than a
 * present fault. This command runs as root and logs heavily by design (above),
 * so it writes `storage/logs/laravel.log` as root. That is harmless while the
 * file already exists — appending does not change ownership — and all five
 * deployments were confirmed `www-data:www-data 644`, with www-data verified
 * able to write, on 2026-08-27.
 *
 * The danger is the file being ABSENT the first time root logs: after a
 * rotation, or on a fresh deployment. Root then CREATES it and owns it, and
 * php-fpm can never append again — so every web request silently stops logging,
 * with no error anywhere, while this command carries on writing happily. An
 * `ls -l` only ever proves the day it was run, so the durable rule is the one
 * to hold: `storage/logs/laravel.log` must stay www-data-owned, and any
 * logrotate or deploy step that recreates it must recreate it as www-data.
 */
#[Signature('imports:process
    {--run= : Advance only this run id}
    {--max-seconds=50 : Wall-clock budget before returning, so the overlap lock is released promptly}
    {--max-rows=500 : Row budget before returning}
    {--prune-days=14 : Days without an update before an unfinished run is written off as abandoned}
    {--no-prune : Skip both housekeeping steps — the abandoned-run sweep and the orphaned-storage release}')]
#[Description('Advance in-flight CSV import runs: stage uploaded files, then write borrowers and loans')]
class ProcessCsvImports extends Command
{
    /**
     * The upload engineer's reconciling storage sweep, named rather than
     * imported. See self::releaseOrphanedStorage() for why both halves are
     * strings and when the indirection should be removed.
     */
    private const UPLOAD_SERVICE = 'App\\Services\\CsvImport\\CsvImportUploadService';

    private const STORAGE_SWEEP = 'releaseAbandonedStorage';

    public function handle(CsvImportProcessor $processor): int
    {
        $startedAt = microtime(true);
        $maxSeconds = max(1, (int) $this->option('max-seconds'));
        $maxRows = max(1, (int) $this->option('max-rows'));

        $runs = $this->workableRuns();

        Log::info('imports:process starting', [
            'runs' => $runs->pluck('id')->all(),
            'max_seconds' => $maxSeconds,
            'max_rows' => $maxRows,
        ]);

        $rows = 0;
        $budgetExhausted = false;

        foreach ($runs as $run) {
            if ($this->outOfBudget($startedAt, $maxSeconds, $rows, $maxRows)) {
                $budgetExhausted = true;
                break;
            }

            $rows += $this->advanceRun($processor, $run, $startedAt, $maxSeconds, $maxRows, $rows, $budgetExhausted);
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        Log::info('imports:process finished', [
            'runs' => $runs->count(),
            'rows' => $rows,
            'seconds' => $elapsed,
            // A budget exit is a normal, healthy outcome, not a partial failure.
            // Saying so in the log is what stops somebody "fixing" it later.
            'budget_exhausted' => $budgetExhausted,
        ]);

        $this->info("Advanced {$runs->count()} run(s), {$rows} row(s), in {$elapsed}s.");

        if (! $this->option('no-prune')) {
            $this->pruneAbandonedRuns($processor, (int) $this->option('prune-days'));
            $this->releaseOrphanedStorage();
        }

        return self::SUCCESS;
    }

    /**
     * Advance one run until it goes idle or the budget runs out.
     *
     * A run that throws is written off as `failed` with the reason on it, rather
     * than left in a phase the next tick will pick up and throw on again every
     * minute forever. The other runs carry on.
     */
    private function advanceRun(
        CsvImportProcessor $processor,
        CsvImportRun $run,
        float $startedAt,
        int $maxSeconds,
        int $maxRows,
        int $rowsAlready,
        bool &$budgetExhausted,
    ): int {
        $rows = 0;

        try {
            while (true) {
                if ($this->outOfBudget($startedAt, $maxSeconds, $rowsAlready + $rows, $maxRows)) {
                    $budgetExhausted = true;

                    Log::info('imports:process paused on budget', [
                        'csv_import_run_id' => $run->id,
                        'phase' => $run->phase,
                        'rows_this_tick' => $rows,
                        'cursor_row_id' => $run->cursor_row_id,
                    ]);

                    break;
                }

                $tick = $processor->advance($run);
                $rows += $tick->rowsProcessed;

                $this->line("  run #{$run->id}: {$tick->phase} (+{$tick->rowsProcessed} rows)".
                    ($tick->note === null ? '' : " — {$tick->note}"));

                if ($tick->idle) {
                    break;
                }

                $run->refresh();
            }
        } catch (Throwable $e) {
            // Never $e->getMessage(). A QueryException's message is the failing
            // SQL with the bindings substituted in, so on this command that is a
            // member's whole record — and it would land in the log, in
            // `failure_reason`, and on an operator's terminal. See
            // ImportErrorDigest.
            $reason = ImportErrorDigest::forRun($e, $run->id);

            Log::error('imports:process could not advance a run', [
                'csv_import_run_id' => $run->id,
                'phase' => $run->phase,
            ] + ImportErrorDigest::context($e));

            ImportErrorDigest::recordDiagnostics($e, [
                'csv_import_run_id' => $run->id,
                'phase' => $run->phase,
            ]);

            $this->error("  run #{$run->id} failed: {$reason}");

            $this->writeOff($processor, $run, $reason);
        }

        return $rows;
    }

    /**
     * @return Collection<int, CsvImportRun>
     */
    private function workableRuns(): Collection
    {
        return CsvImportRun::query()
            ->whereIn('phase', CsvImportProcessor::WORKABLE_PHASES)
            ->when($this->option('run'), fn ($query) => $query->whereKey((int) $this->option('run')))
            // Oldest first: a run that has been waiting must not be starved by a
            // newer one that keeps arriving with work.
            ->orderBy('id')
            ->get();
    }

    private function outOfBudget(float $startedAt, int $maxSeconds, int $rows, int $maxRows): bool
    {
        return (microtime(true) - $startedAt) >= $maxSeconds || $rows >= $maxRows;
    }

    /**
     * Write a run off as `failed`, with its summary audit row.
     *
     * Through CsvImportProcessor::finalise() rather than a bare save, because
     * this path is reachable with thousands of members already created and it
     * used to leave `audit_logs` completely empty when it was. The processor
     * owns the shape of that row; this only supplies the outcome.
     *
     * The fallback is not belt-and-braces. finalise() writes an audit row, and
     * this method is called precisely when the database has just misbehaved — so
     * if it throws, the run must STILL reach a terminal phase, or the next tick
     * picks it up, throws again, and the run wedges the scheduler every minute
     * forever. A written-off run with no audit row is bad; an un-writable-off
     * run is worse.
     *
     * @param  string  $reason  Already sanitised by ImportErrorDigest — this is
     *                          persisted to a column the status endpoint returns.
     */
    private function writeOff(CsvImportProcessor $processor, CsvImportRun $run, string $reason): void
    {
        try {
            $processor->finalise($run, 'failed', $reason);

            return;
        } catch (Throwable $e) {
            Log::error('imports:process could not write the summary for a failed run', [
                'csv_import_run_id' => $run->id,
                'consequence' => 'The run is being marked failed without its audit row.',
            ] + ImportErrorDigest::context($e));
        }

        try {
            $run->forceFill([
                'phase' => 'failed',
                'finished_at' => now(),
                'failure_reason' => mb_substr($reason, 0, 2000),
            ])->save();
        } catch (Throwable $e) {
            Log::error('imports:process could not even mark a run failed', [
                'csv_import_run_id' => $run->id,
            ] + ImportErrorDigest::context($e));
        }
    }

    /**
     * Write off runs nobody has touched in a fortnight.
     *
     * Aged on `updated_at`, which every phase transition, cursor move and note
     * bumps — so a run that is progressing, however slowly, is never a
     * candidate, and one parked on the mapping screen for two weeks is.
     *
     * They are marked `failed`, NOT deleted, and the difference is deliberate —
     * though not for the reason it first appears:
     *
     *  - THE ROW IS THE POINTER. Deleting it cascades to the run's files, chunks
     *    and staged rows, and the bytes on disk are not in that cascade, so the
     *    assembled CSVs and chunk parts would survive with nothing left in the
     *    database naming them. Those bytes are plaintext member names,
     *    birthdates, contact numbers and incomes. Retaining the row is exactly
     *    what keeps them findable, and it is what
     *    self::releaseOrphanedStorage() reconciles against. IF THIS STEP EVER
     *    BECOMES A HARD DELETE, that sweep has nothing to find and the files
     *    become unreachable for good.
     *  - A run is also the record that a cooperative's personal data was
     *    uploaded to this server. Housekeeping should not erase that quietly.
     *
     * Note what is NOT the reason, because the wrong rule is easy to re-derive
     * from the class docblock: the root/www-data trap is about CREATING files.
     * Unlinking creates nothing and needs only write permission on the parent
     * directory, which root has unconditionally, so deleting these files from
     * the scheduler is perfectly safe. It is deleting the ROW that is not.
     *
     * Guarded and logged: a failure here must not take the import work with it,
     * because the import work is the point of the command.
     */
    private function pruneAbandonedRuns(CsvImportProcessor $processor, int $days): void
    {
        if ($days < 1) {
            return;
        }

        try {
            $cutoff = now()->subDays($days);

            $abandoned = CsvImportRun::query()
                ->whereNotIn('phase', CsvImportProcessor::TERMINAL_PHASES)
                ->where('updated_at', '<', $cutoff)
                ->get();

            $swept = 0;

            foreach ($abandoned as $run) {
                // Captured before the save: Eloquent re-syncs `original` on
                // write, so reading them afterwards would report the values this
                // very loop just put there.
                $wasPhase = $run->phase;
                $lastSeen = $run->updated_at?->toDateTimeString();

                // Through the processor, so a run swept after importing two
                // thousand members still leaves the one audit row saying so. It
                // used to leave none: this sweep wrote `failed` and nothing
                // else, and the per-model rows had already been suppressed
                // during the import.
                $finalised = $processor->finalise(
                    $run,
                    'failed',
                    "Abandoned: no activity for {$days} days while in phase [{$wasPhase}]. "
                    .'The uploaded files and staged rows were left in place; start a new run to continue.',
                );

                if ($finalised === null) {
                    // Somebody else reached it first between the SELECT above
                    // and the lock inside finalise() — a cancel, or an
                    // overlapping tick. Nothing to do and nothing wrong.
                    continue;
                }

                $swept++;

                Log::warning('imports:process wrote off an abandoned run', [
                    'csv_import_run_id' => $run->id,
                    'phase' => $wasPhase,
                    'last_updated_at' => $lastSeen,
                ]);
            }

            if ($swept > 0) {
                $this->warn("Wrote off {$swept} abandoned run(s).");
            }
        } catch (Throwable $e) {
            Log::error('imports:process abandoned-run sweep failed', ImportErrorDigest::context($e));

            ImportErrorDigest::recordDiagnostics($e, ['sweep' => 'abandoned-runs']);
        }
    }

    /**
     * Release the uploaded files still held by runs that are finished with.
     *
     * ## Why this lives here
     *
     * The upload service releases a run's storage when the run reaches a
     * terminal phase, driven by a model event. This command's paths DO fire that
     * listener — self::writeOff() and self::pruneAbandonedRuns() both go through
     * CsvImportProcessor::finalise(), which saves the model — so the listener is
     * the primary mechanism here and this is not a replacement for it.
     *
     * It is a RECONCILING backstop, and that is the right shape: it looks at
     * what is actually on disk instead of trusting that an event fired. The
     * upload engineer built it after proving the gap on real disk, with
     * `Model::query()->update()` — a mass update, for which Eloquent dispatches
     * NO model events at all. Nothing here uses one today, but a future
     * `whereIn(...)->update(['phase' => 'failed'])` looks like an obvious
     * optimisation of the loop below and would silently take the listener out of
     * the picture. This sweep is what makes that a slow cleanup rather than
     * member PII left on disk indefinitely.
     *
     * He calls it when a new import is created, so the disk is clean at the
     * start of every run. That leaves exactly one gap, and it is the one this
     * closes: if nobody ever starts another import, a failed run's files sit
     * there indefinitely — and they are a cooperative's membership roll in
     * plaintext. This command is the only thing on these boxes that runs
     * unattended, so the case where nothing else runs is precisely the case it
     * is here for.
     *
     * Deleting files as root is safe; see the note on
     * self::pruneAbandonedRuns() for why that is not a contradiction of the
     * class docblock.
     *
     * ## Why it is guarded, and why the indirection STAYS
     *
     * Guarded twice over, and both guards earn their place permanently:
     *
     *  - RESOLUTION. Skipped LOUDLY when the sweep cannot be reached — a
     *    warning naming the consequence in words, because a silent no-op is how
     *    this turns into member PII sitting on disk forever, which is the exact
     *    failure the method exists to prevent. It began as merge-window
     *    scaffolding, but that is not why it is still here: a typed hard
     *    dependency would fatal the whole tick, taking the IMPORT down with the
     *    housekeeping, and this command's standing rule is that housekeeping is
     *    never allowed to do that. Failing loudly and carrying on is strictly
     *    better than failing fatally.
     *  - EXECUTION. Somebody else's abandoned upload failing to clean up must
     *    never fail this tick or block the next import.
     *
     * AN EARLIER VERSION OF THIS COMMENT TOLD YOU TO REPLACE THE INDIRECTION
     * WITH A CONSTRUCTOR-INJECTED CsvImportUploadService ONCE THE BRANCHES MET.
     * That instruction was wrong and has been withdrawn — it was tried against
     * the merged tree and reverted. A typed constructor parameter rejects the
     * anonymous-class doubles the tests bind to self::UPLOAD_SERVICE (they are
     * deliberately not subclasses: the point of those tests is that this command
     * calls a NAMED METHOD on whatever is bound, and a subclass would test
     * inheritance instead). Resolving through `app()` with a typed property
     * changes nothing at all. So the choice is between rewriting working tests
     * to permit a change with no benefit, or keeping a seam that already
     * degrades correctly. Keep the seam.
     */
    private function releaseOrphanedStorage(): void
    {
        $sweep = $this->resolveStorageSweep();

        if ($sweep === null) {
            Log::warning('imports:process could not reach the orphaned-storage sweep', [
                'service' => self::UPLOAD_SERVICE,
                'method' => self::STORAGE_SWEEP,
                'consequence' => 'Uploaded migration CSVs belonging to finished runs stay on the private disk. '
                    .'They hold member names, birthdates, contact numbers and incomes.',
            ]);

            return;
        }

        try {
            $sweep->{self::STORAGE_SWEEP}();
        } catch (Throwable $e) {
            Log::error('imports:process orphaned-storage sweep failed', ImportErrorDigest::context($e));

            ImportErrorDigest::recordDiagnostics($e, ['sweep' => 'orphaned-storage']);
        }
    }

    /**
     * The upload service, if this deployment has one that can sweep.
     *
     * Container binding first so the behaviour is testable before the two
     * branches meet; the class check is what takes over afterwards. Resolved by
     * name rather than through an import, because an import of a class that is
     * not on this branch yet is a lie to every reader and every tool.
     */
    private function resolveStorageSweep(): ?object
    {
        $resolvable = app()->bound(self::UPLOAD_SERVICE) || class_exists(self::UPLOAD_SERVICE);

        if (! $resolvable) {
            return null;
        }

        $service = app(self::UPLOAD_SERVICE);

        return method_exists($service, self::STORAGE_SWEEP) ? $service : null;
    }
}
