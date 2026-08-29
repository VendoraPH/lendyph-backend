<?php

namespace App\Services\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportFileChunk;
use App\Models\CsvImportRun;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The receiving end of a resumable, chunked CSV upload.
 *
 * A cooperative's legacy extract arrives over a Philippine mobile connection
 * that will drop, so the browser cuts each file into fixed-size parts and sends
 * them independently. This service owns everything from "the client says it is
 * about to send these two files" to "both files are on disk and hash to what
 * was promised": it opens the run, verifies and stores each part, answers which
 * parts are still missing, and concatenates them back into the original bytes.
 *
 * Two properties make the whole thing resumable, and both live here:
 *
 * 1. Storing a part is idempotent. A part whose digest matches the one already
 *    on file is a no-op, so a client that never learned whether its last PUT
 *    landed can simply send it again. That is what makes a dropped connection
 *    free to retry rather than a reason to start over.
 * 2. A part whose digest does NOT match the stored one is a conflict, not an
 *    overwrite. It means the client resumed against a different local file, and
 *    silently accepting it would assemble a chimera of two exports that still
 *    parses as valid CSV.
 */
class CsvImportUploadService
{
    /**
     * The disk chunks and assembled files live on.
     *
     * A constant rather than a config key, deliberately. These files are a
     * whole cooperative's membership register in plain text — names,
     * birthdates, incomes, every loan balance — and anything on the `public`
     * disk is served straight off the filesystem by nginx to anyone who asks,
     * with no authentication at all. A config key is something an operator can
     * set to `public` on one box at 2am; a constant is not.
     */
    public const DISK = 'private';

    /** The two files a run carries, in the order the importer must consume them. */
    public const KINDS = ['customers', 'loans'];

    /**
     * Phases where a run still owns the importer's attention.
     *
     * A second run may not be opened while any run is in one of these: two
     * concurrent imports write borrowers and loans into the same books with no
     * coordination between them, and `external_account_no` — the join key that
     * makes a re-run idempotent — cannot tell which run wrote a row.
     */
    public const OPEN_PHASES = [
        'uploading',
        'assembled',
        'staging',
        'awaiting_mapping',
        'importing_customers',
        'importing_loans',
    ];

    /**
     * Phases where a run is finished with, one way or another.
     *
     * A PUBLISHED CONTRACT. The status endpoint derives its `is_closed` flag
     * from this rather than keeping its own copy of the list, and the retention
     * listener keys off it, so this is the single definition of "done" for the
     * whole feature. `cancelled` was added here after the fact and that is
     * precisely the argument for the shared constant: every hardcoded copy of a
     * phase list is a thing that silently stops being true the next time a
     * phase is added. Narrow this only with the same care as a schema change.
     */
    public const CLOSED_PHASES = ['completed', 'failed', 'cancelled'];

    /**
     * Terminal phases whose files are released the moment the phase changes.
     *
     * A SUBSET of CLOSED_PHASES, and the difference is `failed`.
     *
     * `completed` and `cancelled` are decisions: the rows are staged, or the
     * operator asked for the run to go away. In both cases the source file has
     * done its job and every further minute it spends on the volume is a
     * cooperative's membership register sitting in plaintext for no reason.
     *
     * `failed` is not a decision, it is an accident. The processor writes a run
     * off as `failed` on ANY Throwable — a deadlock, a lock-wait timeout, a
     * momentary DB blip — so releasing on `failed` meant one transient error
     * destroyed both assembled files instantly, leaving a half-imported book
     * with no source to re-run from and nothing to explain the failure with.
     * Failed runs keep their files and are collected later by
     * releaseAbandonedStorage(), which is the same cleanup path, only on a
     * clock instead of a hair trigger.
     *
     * Anything ADDED to CLOSED_PHASES and not to this list inherits the
     * cautious behaviour — files kept, swept later — which is the right default
     * for a list nobody remembers to update.
     */
    public const STORAGE_RELEASE_PHASES = ['completed', 'cancelled'];

    /**
     * Phases a run may be cancelled from.
     *
     * All three sit before anything reaches `borrowers` or `loans`, and none of
     * them has a job mid-write behind it — `awaiting_mapping` is explicitly a
     * human stop. `staging` and `importing_*` are excluded for opposite
     * reasons: staging has a process actively writing rows this service does
     * not own, and the importing phases have already created real members and
     * real released debt, which cancelling would strand half-made. Undoing
     * those is a reversal, not a cancellation, and is not this endpoint's job.
     */
    public const CANCELLABLE_PHASES = ['uploading', 'assembled', 'awaiting_mapping'];

    /**
     * Hard bounds on the advertised chunk size, whatever `imports.chunk_size`
     * says.
     *
     * The ceiling is PHP's `upload_max_filesize` (12 MiB on every box), which
     * multipart parts are measured against one at a time — a larger chunk is
     * refused by PHP before any of this code runs, on every retry, forever. The
     * floor exists because a fat-fingered small value does not fail, it simply
     * turns one upload into tens of thousands of requests.
     */
    private const CHUNK_SIZE_CEILING = 12 * 1024 * 1024;

    private const CHUNK_SIZE_FLOOR = 64 * 1024;

    /** Zero-padding width for a chunk's filename. */
    private const CHUNK_INDEX_PAD = 6;

    /**
     * The importer's processor, and the method on it that ends a run.
     *
     * Named as strings rather than imported because the processor lands on a
     * different branch to this service; see self::finaliseRun(). Merge-window
     * scaffolding, to be replaced with a real import and constructor injection
     * once both halves are on `csv-import/backend`.
     */
    private const PROCESSOR = 'App\Services\CsvImport\CsvImportProcessor';

    private const FINALISER = 'finalise';

    /**
     * Most missing indexes listed in one response.
     *
     * The status endpoint is POLLED while an upload runs, so the list it
     * carries is serialised over and over. `missing_chunk_count` is always
     * exact and never truncated, so a capped list can only ever cost the client
     * an extra poll — never a wrong answer about how much work is left.
     *
     * 500 is above anything reachable today: `total_chunks` is computed by the
     * server, so it cannot exceed max_file_bytes / CHUNK_SIZE_FLOOR = 1600 at
     * the very smallest configured chunk size, and is 200 at the shipped one.
     * The cap is the guarantee, not the expectation.
     */
    private const MISSING_CHUNK_LIST_LIMIT = 500;

    /**
     * The chunk size to advertise, clamped to what the request chain can
     * actually carry.
     *
     * The client reads this off POST /api/imports rather than deciding for
     * itself, which is the only reason the number is tunable per deployment at
     * all. See config/imports.php for why it is 512 KiB.
     */
    public function chunkSize(): int
    {
        $configured = (int) config('imports.chunk_size');

        return max(self::CHUNK_SIZE_FLOOR, min($configured, self::CHUNK_SIZE_CEILING));
    }

    public function maxFileBytes(): int
    {
        return (int) config('imports.max_file_bytes');
    }

    /**
     * The largest chunk worth reading at all, for a cheap early stop in the
     * form request.
     *
     * Deliberately the ceiling rather than the advertised `chunkSize()`. Each
     * file freezes the chunk size its run was opened with, so lowering
     * `imports.chunk_size` while an upload is in flight must not start
     * rejecting the perfectly valid larger parts that run is still sending.
     * The exact per-index size is checked against the file's own frozen value
     * in storeChunk(), which is the only place that can be right about it.
     */
    public function maxAcceptableChunkBytes(): int
    {
        return self::CHUNK_SIZE_CEILING;
    }

    public function totalChunks(int $sizeBytes, int $chunkSize): int
    {
        return max(1, (int) ceil($sizeBytes / $chunkSize));
    }

    /**
     * The most bytes worth accepting for this index, for bounding a raw-body
     * read before anything has validated it.
     *
     * Falls back to the ceiling when the index is out of range, so an
     * out-of-range index is still answered by storeChunk()'s specific error
     * rather than by an arithmetic accident here.
     */
    public function chunkByteCeiling(CsvImportFile $file, int $index): int
    {
        if ($index < 0 || $index >= $file->total_chunks) {
            return $this->maxAcceptableChunkBytes();
        }

        return $this->expectedChunkBytes($file, $index);
    }

    /**
     * A run by id, or a 404 — the replacement for route-model binding on the
     * import routes.
     *
     * `SubstituteBindings` runs long before a controller action or a
     * FormRequest, so a bound `CsvImportRun $run` parameter answers "does run
     * #N exist?" to a caller who is about to be refused: 404 for an id nobody
     * has used, 403 for one somebody has. That is a free oracle for how many
     * migrations a deployment has run and when, handed to any authenticated
     * user — a viewer, a collector, a loan officer. Resolving here instead puts
     * the whole lookup behind `imports:process`, because nothing reaches this
     * method until the permission check has passed.
     *
     * The 404 body deliberately says only that the run was not found. Which of
     * "no such id" and "not yours" it means is not a distinction this feature
     * has — a run belongs to the deployment, not to a person — and spelling it
     * out would rebuild the oracle in the response body.
     */
    public function findRun(int $id): CsvImportRun
    {
        $run = CsvImportRun::query()->find($id);

        if ($run === null) {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$id} was not found.",
            ], 404));
        }

        return $run;
    }

    /**
     * The run currently occupying the importer, if any.
     *
     * Reads OPEN_PHASES — the same list createRun()'s concurrency guard reads,
     * and deliberately not a second copy of it. GET /api/imports answers from
     * here, so "what is open" and "what is blocking a new run" are one
     * question with one answer. A discovery endpoint that disagreed with the
     * guard would be worse than none: it would tell an operator there is
     * nothing to cancel while POST /api/imports keeps refusing them.
     *
     * `orderBy('id')` matches the guard's own ordering, so if the invariant
     * that at most one run is open were ever broken, both would name the same
     * run rather than pointing at different ones.
     */
    public function openRun(): ?CsvImportRun
    {
        return CsvImportRun::query()
            ->whereIn('phase', self::OPEN_PHASES)
            ->orderBy('id')
            ->first();
    }

    /**
     * Open a run and register the two files the client is about to send.
     *
     * @param  array{branch_id: int, as_of_date?: string|null, files: array<string, array{filename: string, size_bytes: int, sha256: string}>}  $data
     * @return array{run: CsvImportRun, warning: string|null}
     */
    public function createRun(array $data, ?int $actorId, ?string $actorIp): array
    {
        $chunkSize = $this->chunkSize();

        // Opportunistic, and here because this is a web request. See the method
        // for why that matters.
        $this->releaseAbandonedStorage();

        return DB::transaction(function () use ($data, $actorId, $actorIp, $chunkSize): array {
            /**
             * Serialised, not merely checked.
             *
             * Two browser tabs both pass a plain check-then-insert and the
             * loser's chunks are then dropped into a second run nobody is
             * watching. `phase` carries no index, so this locks every row in
             * the table plus the gap — which is exactly what is wanted here and
             * costs nothing, because the schema keeps this table to a few dozen
             * rows over a deployment's whole life.
             */
            $open = CsvImportRun::query()
                ->whereIn('phase', self::OPEN_PHASES)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $reclaimedRunId = null;

            if ($open !== null && $this->isStaleUpload($open)) {
                /**
                 * The stuck-run outage, closed at the only moment it matters.
                 *
                 * A browser that dies mid-upload leaves a run in `uploading`
                 * with nothing left to advance it, and the concurrency guard
                 * below would then refuse every future import at this
                 * cooperative forever, with no way to clear it from the UI.
                 * Reclaiming here — inside the same lock that enforces the
                 * guard, so it cannot race — turns a permanent outage into a
                 * pause, and does it without a scheduled job that could cancel
                 * an operator's work while they are away from the screen.
                 */
                $reclaimedRunId = $open->id;

                $this->reclaimStaleUpload($open);

                $open = null;
            }

            if ($open !== null) {
                throw new HttpResponseException(response()->json([
                    'message' => "Import run #{$open->id} is still in progress (phase: {$open->phase}). Finish or cancel it before starting another.",
                    'run_id' => $open->id,
                    'phase' => $open->phase,
                    'cancellable' => in_array($open->phase, self::CANCELLABLE_PHASES, true),
                ], 409));
            }

            $previouslyCompleted = CsvImportRun::query()->where('phase', 'completed')->count();

            $run = CsvImportRun::create([
                'branch_id' => $data['branch_id'],
                'as_of_date' => $data['as_of_date'] ?? now()->toDateString(),
                'phase' => 'uploading',
                'initiated_by' => $actorId,
                'initiated_ip' => $actorIp,
            ]);

            foreach (self::KINDS as $kind) {
                $declared = $data['files'][$kind];

                $run->files()->create([
                    'kind' => $kind,
                    // basename() because this string is operator-facing and
                    // arrives from a browser. Nothing here builds a path out of
                    // it — chunk and assembled paths are derived from ids — but
                    // a filename carrying `../` has no business being stored
                    // and echoed back either.
                    'original_filename' => $this->safeFilename($declared['filename']),
                    'size_bytes' => $declared['size_bytes'],
                    'sha256' => strtolower($declared['sha256']),
                    // Frozen onto the file, not read from config at PUT time.
                    // A deployment that retunes imports.chunk_size mid-upload
                    // must not invalidate the parts already sent, and resume
                    // has to agree with the client about where the boundaries
                    // are.
                    'chunk_size' => $chunkSize,
                    'total_chunks' => $this->totalChunks((int) $declared['size_bytes'], $chunkSize),
                ]);
            }

            return [
                'run' => $run->load('files'),
                'reclaimed_run_id' => $reclaimedRunId,
                'warning' => $previouslyCompleted > 0
                    ? "This deployment already has {$previouslyCompleted} completed import run(s). Re-importing the same export is safe — members are matched on their legacy account number — but confirm this is a different file before continuing."
                    : null,
            ];
        });
    }

    public function staleUploadAfterMinutes(): int
    {
        return (int) config('imports.stale_upload_after_minutes');
    }

    /**
     * How long a run that FAILED keeps its uploaded files before the sweep
     * takes them. See config/imports.php and STORAGE_RELEASE_PHASES.
     *
     * Clamped at zero rather than trusted: a negative value would put the
     * cutoff in the future and release every failed run's files on the next
     * sweep, silently restoring the behaviour this window exists to remove.
     */
    public function failedRunRetentionHours(): int
    {
        return max(0, (int) config('imports.failed_run_retention_hours'));
    }

    /**
     * The last moment anything actually happened on this run.
     *
     * Measured from the newest chunk to arrive, not from when the run was
     * opened. A 100 MiB upload over a bad mobile link may legitimately take
     * many hours; what matters is whether it is still making progress, and
     * every chunk that lands moves this forward. `updated_at` is folded in so a
     * run whose phase was just advanced by something other than an upload
     * counts as active too.
     */
    public function lastActivityAt(CsvImportRun $run): Carbon
    {
        $latestChunk = CsvImportFileChunk::query()
            ->whereIn('csv_import_file_id', CsvImportFile::query()
                ->where('csv_import_run_id', $run->id)
                ->select('id'))
            ->max('created_at');

        $candidates = array_values(array_filter([
            $run->updated_at,
            $latestChunk === null ? null : Carbon::parse($latestChunk),
        ]));

        return $candidates === [] ? now() : Carbon::parse(max($candidates));
    }

    /**
     * An upload nobody is driving any more.
     *
     * Deliberately only `uploading`. A run wedged in `staging` or
     * `importing_*` has a process mid-write behind it, and taking its slot
     * while it works would be far worse than the outage this prevents.
     */
    public function isStaleUpload(CsvImportRun $run): bool
    {
        if ($run->phase !== 'uploading') {
            return false;
        }

        return $this->lastActivityAt($run)
            ->lt(now()->subMinutes($this->staleUploadAfterMinutes()));
    }

    /**
     * Cancel a run and free everything it was holding.
     *
     * Idempotent: cancelling an already-cancelled run is a no-op, so a client
     * that retries a DELETE it never saw the response to is not punished for
     * it.
     *
     * Writes the run's summary audit row through the shared finaliser, exactly
     * as completion does — see self::finaliseRun(). Cancelling is not a quiet
     * operation: by the time an operator can reach this endpoint, staging may
     * already have loaded every member's record into `csv_import_rows`, and
     * without that row nothing in `audit_logs` records that a cooperative's
     * whole membership was uploaded, discarded, or by whom.
     *
     * @return array{cancelled: bool, chunks_removed: int, files_removed: int}
     */
    public function cancelRun(CsvImportRun $run, ?string $reason = null): array
    {
        if ($run->phase === 'cancelled') {
            return ['cancelled' => false, 'chunks_removed' => 0, 'files_removed' => 0];
        }

        if (! in_array($run->phase, self::CANCELLABLE_PHASES, true)) {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$run->id} is in phase '{$run->phase}' and cannot be cancelled. "
                    .'A run can only be cancelled before it has written anything to the members and loans tables.',
                'phase' => $run->phase,
                'cancellable_phases' => self::CANCELLABLE_PHASES,
            ], 409));
        }

        /**
         * Storage first, phase second.
         *
         * Not for the counts' sake — though the phase change fires the
         * terminal-phase listener, which would otherwise do this work and leave
         * the explicit call below reporting zero for everything it never had to
         * remove. It is also the safer order: interrupted here the run is still
         * `uploading`, so the TTL can reclaim it and a retried cancel finishes
         * the job. The reverse order would leave a terminal run holding files
         * that nothing ever looks at again.
         *
         * Called explicitly rather than left to the listener, so cancelling
         * never silently depends on that wiring; releaseStorage() is idempotent
         * and the listener's second pass is a no-op.
         */
        $released = $this->releaseStorage($run);

        $this->finaliseRun(
            $run,
            'cancelled',
            $reason ?? 'Cancelled by an operator before any members or loans were written.',
        );

        return ['cancelled' => true, ...$released];
    }

    /**
     * Move a run to a terminal phase through the importer's shared finaliser,
     * so the summary audit row is written the same way on every ending.
     *
     * ## Why this calls somebody else's method
     *
     * `audit_logs` gets exactly ONE row per import run — the per-model rows are
     * suppressed while the importer writes, because a cooperative's entire
     * membership arriving in one night would otherwise bury the log. That makes
     * the summary row the only trace an auditor can ask about, and it was being
     * written from a single private method on the processor, reachable only by
     * the one path that completes a run. Cancelling wrote nothing at all: an
     * operator could discard a run after staging had already loaded every
     * member's record and leave no record that it happened or who did it.
     *
     * The fix is to call the processor's finaliser rather than to write a
     * second one here. Two implementations of "the audit row for this run"
     * drift, and the one that drifts is the one nobody looks at until an audit.
     *
     * ## THE ACTOR IS PASSED EXPLICITLY, and must be
     *
     * finalise() does not fall through to AuditLogService's `auth()`/`request()`
     * defaults. Its own defaults are the run's `initiated_by` and
     * `initiated_ip`, which is right for the scheduler — the processor works
     * long after the admin who asked for the import has gone home — and wrong
     * here. Cancelling is a live web request, and the operator who cancels a
     * run is very often not the one who opened it. Leaving these null would
     * quietly attribute one admin's decision to another admin's name, in the
     * single audit row that exists to say who did this.
     *
     * `auth()->id()` and `request()->ip()` rather than the request object,
     * because this service is also reachable from the console; both are simply
     * null there, which is what finalise()'s own fallback is for.
     *
     * ## The transition
     *
     * finalise() owns it, and must be called while the run is still live: it
     * re-reads the run under a lock and returns without writing anything if it
     * is ALREADY terminal, so setting the phase here first would skip the audit
     * row and silently restore the gap this exists to close. The reason string
     * goes to it rather than being written first, for the same reason — it
     * belongs to the same locked write as the phase and the audit row.
     *
     * The check afterwards is what makes that contract safe to depend on rather
     * than assume: whatever happened above, this method does not return with
     * the run still live, because a run left in `uploading` blocks every future
     * import at this cooperative until the stale-upload TTL expires. It tests
     * for ANY terminal phase rather than the one asked for, so a run that a
     * concurrent tick finalised as `failed` in the meantime is left as `failed`
     * rather than overwritten with our answer.
     *
     * ## MERGE-WINDOW SCAFFOLDING
     *
     * Resolved by name, and the same shape ProcessCsvImports uses to reach this
     * service from its side — the two halves of this feature are on different
     * branches, and an import of a class that is not on this branch yet is a
     * lie to every reader and every tool. Skipped LOUDLY if it cannot be
     * reached, because a silent no-op is how a bad merge turns into an import
     * with no audit trail at all, which is the exact thing this method exists
     * to prevent.
     *
     * Arguments are NAMED, deliberately. Positional ones would keep working and
     * silently mean something else if a parameter were ever inserted ahead of
     * them, and "silently attributes the audit row to the wrong person" is the
     * worst available failure here; a renamed parameter throws instead, which
     * is the failure we want.
     *
     * Once both branches are on `csv-import/backend`, replace the resolution
     * with a constructor-injected CsvImportProcessor and a direct
     * `$this->processor->finalise(...)`; keep the post-condition check.
     */
    private function finaliseRun(CsvImportRun $run, string $phase, ?string $failureReason = null): void
    {
        $finaliser = $this->resolveFinaliser();

        if ($finaliser === null) {
            Log::warning('csv-import: could not reach the shared run finaliser', [
                'csv_import_run_id' => $run->id,
                'phase' => $phase,
                'service' => self::PROCESSOR,
                'method' => self::FINALISER,
                'consequence' => 'The run is finished without its summary audit row, so `audit_logs` holds no '
                    .'record that this cooperative\'s uploaded membership and loan data was discarded, or by whom.',
            ]);
        } else {
            $finaliser->{self::FINALISER}(
                $run,
                $phase,
                failureReason: $failureReason,
                userId: auth()->id(),
                ipAddress: request()->ip(),
            );
        }

        $run->refresh();

        if (in_array($run->phase, self::CLOSED_PHASES, true)) {
            return;
        }

        $run->forceFill([
            'phase' => $phase,
            'finished_at' => now(),
            'failure_reason' => $failureReason,
        ])->save();
    }

    /**
     * The importer's processor, if this deployment has one that can finalise a
     * run. See the note on self::finaliseRun().
     */
    private function resolveFinaliser(): ?object
    {
        if (! app()->bound(self::PROCESSOR) && ! class_exists(self::PROCESSOR)) {
            return null;
        }

        $processor = app(self::PROCESSOR);

        return method_exists($processor, self::FINALISER) ? $processor : null;
    }

    /**
     * Free everything a run that is finished with was holding on the private
     * volume.
     *
     * An assembled CSV is a cooperative's whole membership register in
     * plaintext — names, birthdates, contact numbers, incomes — and once its
     * rows are staged into `csv_import_rows` the file has done its job. Keeping
     * it afterwards is a standing disclosure risk with no remaining benefit.
     *
     * Which phases reach here is NOT this method's decision, and the
     * distinction matters: see self::STORAGE_RELEASE_PHASES for why a `failed`
     * run keeps its files for a while and a `completed` or `cancelled` one does
     * not.
     *
     * EVERY UNLINK IS DEFERRED TO `DB::afterCommit`, and it is deferred HERE
     * rather than at any one call site, because more than one call site needs
     * it. This method is reachable from inside somebody else's open transaction
     * from at least two directions:
     *
     *  - createRun() reaches it through reclaimStaleUpload(), inside the
     *    transaction that holds the concurrency lock;
     *  - the `saved` listener fires it from whatever transaction moved the
     *    phase, and every terminal transition now runs inside the processor's
     *    shared finalise(), which keeps a `lockForUpdate` and a terminal
     *    re-check in a transaction. That includes the 14-day prune, which saves
     *    per row and therefore does fire the listener.
     *
     * The filesystem has no rollback. Deleting inline meant a rolled-back
     * transaction restored the
     * chunk ROWS while their bytes stayed gone, which assembleFile() then
     * reports as "recorded but missing from disk" and which storeChunk()
     * cannot repair, because a matching re-send of a chunk that is still
     * recorded is answered as a duplicate no-op. The run could only be
     * cancelled. Same reasoning, and the same fix, as BorrowerPurgeService:
     * orphan files beat orphan rows, because an orphan file is a wasted
     * megabyte a sweep can find and an orphan row is a phantom nothing can.
     *
     * With no transaction open — a direct call from the controller — the
     * callbacks run immediately, so the counts returned below still describe
     * what has just been removed.
     *
     * `csv_import_rows` still holds the same personal data, row by row, and
     * needs its own retention decision. That is a policy call tracked
     * separately, not something this method can make.
     *
     * @return array{chunks_removed: int, files_removed: int}
     */
    public function releaseStorage(CsvImportRun $run): array
    {
        $chunks = $this->discardChunks($run);
        $files = $this->purgeAssembledFiles($run);

        // Sweeps up the run's directory once nothing is left in it. Ignores a
        // non-empty directory rather than forcing it: anything still in there
        // is unexpected and should be looked at, not silently destroyed.
        //
        // Registered last, and deferred like the deletions above, because it
        // can only be right once they have run: afterCommit callbacks fire in
        // registration order.
        $disk = $this->disk();
        $directory = rtrim((string) config('imports.path_prefix'), '/').'/'.$run->id;

        DB::afterCommit(function () use ($disk, $directory): void {
            if ($disk->exists($directory) && $disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        });

        return ['chunks_removed' => $chunks, 'files_removed' => $files];
    }

    /**
     * Delete files belonging to runs that are already finished but still
     * holding them.
     *
     * The retention listener catches a run the moment its phase changes, but
     * only when the phase is changed THROUGH THE MODEL — Eloquent fires no
     * events for `Model::query()->update()`. The importer's abandoned-run
     * cleanup marks runs `failed` that way, from a scheduled command, so the
     * listener never sees it and the run's assembled CSVs would otherwise sit
     * on the volume indefinitely. This is the reconciling sweep that catches
     * them.
     *
     * TWO CALL SITES, and the second is somebody else's:
     *
     *  - createRun(), so every import starts from a volume carrying no
     *    leftovers from the last one.
     *  - the importer's abandoned-run cleanup command, which calls this after
     *    marking runs `failed`. That closes the case createRun() cannot: a
     *    deployment where nobody ever starts another import, where a failed
     *    run's files would otherwise persist forever.
     *
     * Safe from that command even though it runs as root. Deleting is not the
     * operation the uid trap is about — unlinking needs write permission on the
     * parent directory, which root has unconditionally, and it CREATES nothing,
     * so it cannot leave behind the root-owned 0700 directory that makes a file
     * permanently unreadable to php-fpm. The hazard is writing as root, not
     * removing as root.
     *
     * DEPENDS ON THE ROWS SURVIVING. That command marks abandoned runs `failed`
     * rather than deleting them precisely so the files they left behind stay
     * findable — this sweep locates orphaned bytes by walking from the run row
     * to its files. Hard-deleting those rows instead would look like tidier
     * housekeeping and would strand every assembled CSV on the volume with
     * nothing left pointing at it: plaintext member data that nothing could
     * ever find again. The coupling is deliberate and is commented at both
     * ends.
     *
     * A `failed` RUN IS NOT SWEPT STRAIGHT AWAY, and that grace window is the
     * whole point of keeping its files in the first place. Both call sites are
     * things that happen within seconds of a failure — the scheduled command
     * runs this at the end of the same minute-ly tick that wrote the run off,
     * and an operator whose import just failed opens another one immediately —
     * so without the window, not releasing on the `failed` transition would buy
     * a run's source files roughly sixty seconds of life and change nothing
     * else. Aged from `finished_at`, falling back to `updated_at` for a run
     * failed by a mass update that set no finish time.
     *
     * `completed` and `cancelled` are swept with no window at all: for those
     * the listener has already released the storage, so anything this sweep
     * still finds is a run whose phase moved without a model event, and it has
     * been sitting there since whenever that happened.
     *
     * Failures are reported and swallowed. This is cleanup of somebody else's
     * abandoned run; it must never be the reason an operator cannot start
     * theirs.
     *
     * @param  int  $limit  runs to reconcile per call, so one sweep cannot turn
     *                      a request into an unbounded amount of file deletion
     * @return int runs reconciled
     */
    public function releaseAbandonedStorage(int $limit = 25): int
    {
        try {
            /**
             * Derived from the two published lists rather than naming `failed`:
             * a terminal phase added to CLOSED_PHASES and not to
             * STORAGE_RELEASE_PHASES is one nobody has decided about yet, and
             * holding its files for the retention window is the safe way to be
             * undecided.
             */
            $deferred = array_values(array_diff(self::CLOSED_PHASES, self::STORAGE_RELEASE_PHASES));
            $cutoff = now()->subHours($this->failedRunRetentionHours());

            $runs = CsvImportRun::query()
                ->where(function ($query) use ($deferred, $cutoff): void {
                    $query
                        ->whereIn('phase', self::STORAGE_RELEASE_PHASES)
                        ->orWhere(function ($aged) use ($deferred, $cutoff): void {
                            $aged
                                ->whereIn('phase', $deferred)
                                ->whereRaw('coalesce(finished_at, updated_at) < ?', [$cutoff]);
                        });
                })
                ->where(function ($query): void {
                    $query
                        ->whereHas('files', fn ($files) => $files->whereNotNull('assembled_path'))
                        ->orWhereHas('files.chunks');
                })
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($runs as $run) {
                $this->releaseStorage($run);
            }

            return $runs->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Delete the assembled CSVs and forget where they were.
     *
     * `assembled_sha256` is deliberately KEPT. It is the evidence of what was
     * actually imported and costs nothing to retain, while `assembled_path` is
     * nulled because a path pointing at a deleted file is worse than no path.
     *
     * The column is nulled first and the bytes go on commit, in that order and
     * never the other way round: a rolled-back transaction that had already
     * unlinked would restore an `assembled_path` naming a file that is gone.
     * See releaseStorage().
     *
     * @return int files removed
     */
    public function purgeAssembledFiles(CsvImportRun $run): int
    {
        $disk = $this->disk();
        $paths = [];

        foreach ($run->files()->whereNotNull('assembled_path')->get() as $file) {
            $paths[] = $file->assembled_path;

            $file->forceFill(['assembled_path' => null])->save();
        }

        if ($paths !== []) {
            DB::afterCommit(function () use ($disk, $paths): void {
                $disk->delete($paths);
            });
        }

        return count($paths);
    }

    /**
     * Take the slot back from an upload nobody is driving.
     *
     * Recorded as a cancellation with a reason that says what happened, so an
     * operator who returns to a dead browser tab and finds their run gone can
     * read why rather than guess.
     *
     * CALLED FROM INSIDE createRun()'s TRANSACTION, which is why every unlink
     * in releaseStorage() is deferred to `DB::afterCommit`. Two concurrent
     * POST /imports both take `lockForUpdate` on the same rows, so a deadlock
     * here is plausible rather than exotic; unlinking inline meant the loser's
     * rollback restored the stale run's chunk rows with their bytes already
     * gone, leaving a run that could not be assembled, could not be repaired by
     * re-uploading, and could only be cancelled.
     *
     * Finalised through the shared path like every other ending, so this second
     * route to `cancelled` writes the same summary audit row rather than
     * discarding somebody's upload silently. The actor is whoever opened the
     * new run, which is accurate — their request is what took the slot — and
     * the reason on the row says it was automatic rather than a decision. The
     * audit row is written inside the same transaction, so it rolls back with
     * the reclaim if the open fails, exactly as it should.
     */
    private function reclaimStaleUpload(CsvImportRun $run): void
    {
        $minutes = $this->staleUploadAfterMinutes();

        $this->releaseStorage($run);

        $this->finaliseRun(
            $run,
            'cancelled',
            "Abandoned mid-upload: no chunk arrived for over {$minutes} minutes, so the run was reclaimed automatically to let a new import start.",
        );
    }

    /**
     * The file of a run for a given kind, or a 404.
     */
    public function fileFor(CsvImportRun $run, string $kind): CsvImportFile
    {
        $this->assertKind($kind);

        $file = $run->files()->where('kind', $kind)->first();

        if ($file === null) {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$run->id} has no {$kind} file.",
            ], 404));
        }

        // The run is already in hand from route binding. Handing it to the
        // relation saves a query on every chunk of every upload — hundreds per
        // run, and the single hottest path this service has.
        $file->setRelation('run', $run);

        return $file;
    }

    /**
     * Store one received chunk, or recognise it as one already held.
     *
     * `$sourcePath` is a local path holding the received bytes — a PHP upload
     * temp file, or a temp file the controller streamed the raw request body
     * into. Either way the bytes are hashed off disk rather than held in
     * memory.
     *
     * @return array{status: 'stored'|'duplicate', chunk: CsvImportFileChunk}
     */
    public function storeChunk(CsvImportFile $file, int $index, string $declaredSha256, string $sourcePath): array
    {
        $this->assertUploading($file->run);

        if ($index < 0 || $index >= $file->total_chunks) {
            throw new HttpResponseException(response()->json([
                'message' => "Chunk index {$index} is outside this file's range (0.."
                    .($file->total_chunks - 1).').',
                'total_chunks' => $file->total_chunks,
            ], 422));
        }

        $declaredSha256 = strtolower($declaredSha256);

        /**
         * The idempotency decision comes before the bytes are hashed, on
         * purpose.
         *
         * A chunk already on file had its bytes verified when it was stored, so
         * re-verifying a resend proves nothing new, and skipping the hash makes
         * the common resume case — a client replaying the parts it is unsure
         * about — cheap rather than a full re-read of the file.
         */
        $existing = $file->chunks()->where('chunk_index', $index)->first();

        if ($existing !== null) {
            return $this->reconcileExistingChunk($existing, $index, $declaredSha256);
        }

        $expectedBytes = $this->expectedChunkBytes($file, $index);

        // php-fpm reuses a worker across requests and PHP caches stat results
        // per path, so the size of a temp file at a recycled path can otherwise
        // be read from the previous request's cache.
        clearstatcache(true, $sourcePath);
        $receivedBytes = (int) filesize($sourcePath);

        if ($receivedBytes !== $expectedBytes) {
            throw new HttpResponseException(response()->json([
                'message' => "Chunk {$index} is {$receivedBytes} bytes; this file's chunk {$index} must be exactly {$expectedBytes} bytes.",
                'expected_size_bytes' => $expectedBytes,
                'received_size_bytes' => $receivedBytes,
                'chunk_size' => $file->chunk_size,
            ], 422));
        }

        /**
         * Verified BEFORE the bytes are stored. A part corrupted in transit is
         * rejected while the client still has it and can resend that one part —
         * rather than surfacing hours later as a whole-file mismatch with no
         * way to tell which of two hundred parts went bad.
         *
         * This is a different failure from the 409 above: that one means the
         * client resumed against a different file, this one means the wire ate
         * a byte. They get different statuses because they need different
         * fixes.
         */
        $actualSha256 = hash_file('sha256', $sourcePath);

        if (! hash_equals($declaredSha256, (string) $actualSha256)) {
            throw new HttpResponseException(response()->json([
                'message' => "Chunk {$index} did not survive the upload: its bytes hash to a different value than the digest sent with them. Send this chunk again.",
                'declared_sha256' => $declaredSha256,
                'received_sha256' => $actualSha256,
            ], 422));
        }

        $path = $this->chunkPath($file, $index);

        try {
            /**
             * Row first, bytes second, both inside the transaction.
             *
             * Writing the bytes first would let a racing PUT carrying DIFFERENT
             * content for the same index overwrite a stored chunk at its
             * deterministic path and only then lose the unique-index race — the
             * loser gets its 409, and the winner's row is left describing bytes
             * that are no longer on disk. Inserting first means the loser never
             * reaches the write.
             */
            $chunk = DB::transaction(function () use ($file, $index, $actualSha256, $expectedBytes, $path, $sourcePath): CsvImportFileChunk {
                $chunk = $file->chunks()->create([
                    'chunk_index' => $index,
                    'size_bytes' => $expectedBytes,
                    'sha256' => $actualSha256,
                    'path' => $path,
                ]);

                $stream = fopen($sourcePath, 'rb');

                if ($stream === false) {
                    throw new RuntimeException("Unable to read the received chunk at {$sourcePath}.");
                }

                try {
                    // Checked, because the `private` disk is `'throw' => false`
                    // and writeStream() returns false rather than raising. An
                    // unchecked false commits a chunk row describing bytes that
                    // were never written, and every resend of it is then
                    // answered as an already-received duplicate — forever.
                    if ($this->disk()->writeStream($path, $stream) === false) {
                        throw new RuntimeException("Unable to store chunk {$index} at {$path}.");
                    }
                } finally {
                    fclose($stream);
                }

                return $chunk;
            });
        } catch (QueryException $e) {
            // Two requests carrying the same index; the database decided which
            // one wins. Handle the loser as the retry it almost always is.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = $file->chunks()->where('chunk_index', $index)->firstOrFail();

            return $this->reconcileExistingChunk($existing, $index, $declaredSha256);
        }

        return ['status' => 'stored', 'chunk' => $chunk];
    }

    /**
     * Concatenate a run's chunks back into the original files and prove the
     * result is byte-for-byte what the client promised.
     *
     * @return array{files: array<string, array<string, mixed>>}
     */
    public function assemble(CsvImportRun $run): array
    {
        if (! in_array($run->phase, ['uploading', 'assembled'], true)) {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$run->id} is in phase '{$run->phase}' and can no longer be assembled.",
                'phase' => $run->phase,
            ], 409));
        }

        $files = $run->files()->orderBy('kind')->get();

        if ($files->count() !== count(self::KINDS)) {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$run->id} is missing one of its files.",
            ], 422));
        }

        // Every file is checked for completeness before ANY file is written, so
        // a run that is short one chunk of its loans file does not leave a
        // half-finished customers file behind to reason about.
        $missing = [];

        foreach ($files as $file) {
            if ($file->assembled_path !== null) {
                continue;
            }

            $indexes = $this->missingChunkIndexes($file);

            if ($indexes !== []) {
                $missing[$file->kind] = $indexes;
            }
        }

        if ($missing !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Some chunks have not been received yet. Send them and call assemble again.',
                'missing_chunks' => array_map(
                    fn (array $indexes): array => array_slice($indexes, 0, self::MISSING_CHUNK_LIST_LIMIT),
                    $missing,
                ),
                // Exact, and never truncated — same discipline as the status
                // endpoint, so a client reading either one is never misled
                // about how much is left to send.
                'missing_chunk_counts' => array_map('count', $missing),
                'missing_chunks_truncated' => array_map(
                    fn (array $indexes): bool => count($indexes) > self::MISSING_CHUNK_LIST_LIMIT,
                    $missing,
                ),
            ], 422));
        }

        /**
         * One assembly per run at a time.
         *
         * Assembly is synchronous and can run for seconds on a 100 MiB pair,
         * against a 90 s proxy budget — so a client that times out and retries,
         * or an operator who double-clicks, really does produce two concurrent
         * calls. Unserialised, they interleave catastrophically: B hashes the
         * file while A is still writing it, sees a mismatch and deletes it,
         * while A commits `assembled_path`, drops every chunk row and deletes
         * every chunk file. The run then claims to be assembled, has no
         * assembled file, and has no chunks left to rebuild it from — an entire
         * membership register lost to a retry.
         */
        $lock = Cache::lock("csv-import:assemble:{$run->id}", 300);

        if (! $lock->get()) {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$run->id} is already being assembled. Wait for that request to finish rather than starting another.",
            ], 409));
        }

        try {
            // Re-read under the lock: the request we queued behind may have
            // assembled these files already.
            foreach ($run->files()->orderBy('kind')->get() as $file) {
                if ($file->assembled_path === null) {
                    $this->assembleFile($file);
                }
            }

            if ($run->phase !== 'assembled') {
                $run->forceFill(['phase' => 'assembled'])->save();
            }
        } finally {
            $lock->release();
        }

        return $this->runPayload($run->fresh()); // @phpstan-ignore-line
    }

    /**
     * The upload-progress view of a run: what each file still needs.
     *
     * `missing_chunks` rather than a received list, because after a dropped
     * connection the missing set is the short one — and it is literally the
     * work queue the client resumes from. A 100 MiB file that is 99% uploaded
     * reports two integers here instead of two hundred.
     *
     * Shared with the status endpoint so both describe a run identically.
     *
     * @return array{run: array<string, mixed>, chunk_size: int, files: array<string, array<string, mixed>>}
     */
    public function runPayload(CsvImportRun $run): array
    {
        $files = $run->files()->orderBy('kind')->get();

        $receivedByFile = $this->receivedChunkIndexes($files);

        $payload = [];

        foreach ($files as $file) {
            $assembled = $file->assembled_path !== null;
            $received = $receivedByFile[$file->id] ?? [];

            // An assembled file has had its chunks deleted; reporting them as
            // missing would send a client that already succeeded back to
            // re-upload the whole thing.
            $missing = $assembled ? [] : $this->missingFrom($file, $received);

            $payload[$file->kind] = [
                'id' => $file->id,
                'kind' => $file->kind,
                'original_filename' => $file->original_filename,
                'size_bytes' => $file->size_bytes,
                'sha256' => $file->sha256,
                'chunk_size' => $file->chunk_size,
                'total_chunks' => $file->total_chunks,
                'assembled' => $assembled,
                'received_chunks' => $assembled ? $file->total_chunks : count($received),
                ...$this->missingChunkPayload($missing),
            ];
        }

        return [
            'run' => [
                'id' => $run->id,
                'branch_id' => $run->branch_id,
                'as_of_date' => $run->as_of_date?->toDateString(),
                'phase' => $run->phase,
                // Derived from CLOSED_PHASES so a client never has to hardcode
                // the phase list — the thing that breaks the moment a phase is
                // added, as `cancelled` just was.
                'is_closed' => in_array($run->phase, self::CLOSED_PHASES, true),
                'initiated_by' => $run->initiated_by,
                'created_at' => $run->created_at?->toIso8601String(),
            ],
            'chunk_size' => $files->max('chunk_size') ?? $this->chunkSize(),
            'files' => $payload,
        ];
    }

    /**
     * Delete every chunk of a run, from the disk and from the database.
     *
     * Assembly cleans up after itself; nothing else does. A run abandoned
     * part-way through its upload — cancelled, or simply never finished —
     * otherwise leaves its parts on the private volume indefinitely, and those
     * parts are a cooperative's members in plain text, not merely wasted
     * megabytes.
     *
     * Offered here for whoever owns cancellation and cleanup: this is the only
     * place that knows the path layout, so deleting chunks anywhere else would
     * be a second copy of it waiting to drift.
     *
     * Rows go first and inside a transaction, bytes only once that is durable.
     * An orphaned file is a wasted megabyte; an orphaned ROW is a phantom that
     * a later assemble would try to read and fail on — and one a re-send cannot
     * fix, because storeChunk() answers a matching re-send of a chunk it still
     * has a row for as a duplicate no-op.
     *
     * `DB::afterCommit` rather than a plain call, because this is reachable
     * from inside a caller's transaction — createRun() reaches it through
     * reclaimStaleUpload(). Registered after the inner transaction above rather
     * than inside it, so with an outer transaction open it attaches to the
     * OUTER one and only fires when that commits. See releaseStorage().
     *
     * @return int chunks removed
     */
    public function discardChunks(CsvImportRun $run): int
    {
        $disk = $this->disk();
        $removed = 0;

        foreach ($run->files()->get() as $file) {
            $paths = $file->chunks()->pluck('path')->all();

            if ($paths === []) {
                continue;
            }

            DB::transaction(function () use ($file): void {
                $file->chunks()->delete();
            });

            $directory = $this->chunkDirectory($file);

            DB::afterCommit(function () use ($disk, $paths, $directory): void {
                $disk->delete($paths);
                $disk->deleteDirectory($directory);
            });

            $removed += count($paths);
        }

        return $removed;
    }

    /**
     * The indexes of this file's chunks that have not arrived.
     *
     * @return list<int>
     */
    public function missingChunkIndexes(CsvImportFile $file): array
    {
        return $this->missingFrom($file, $file->chunks()->pluck('chunk_index')->all());
    }

    /**
     * The capped-list contract the status endpoint publishes.
     *
     * `missing_chunk_count` is the exact number and is never truncated, so a
     * client is never misled about how much work remains by a list that was
     * shortened for the wire.
     *
     * @param  list<int>  $missing
     * @return array{missing_chunks: list<int>, missing_chunk_count: int, missing_chunks_truncated: bool}
     */
    public function missingChunkPayload(array $missing): array
    {
        return [
            'missing_chunks' => array_slice($missing, 0, self::MISSING_CHUNK_LIST_LIMIT),
            'missing_chunk_count' => count($missing),
            'missing_chunks_truncated' => count($missing) > self::MISSING_CHUNK_LIST_LIMIT,
        ];
    }

    /**
     * @param  list<int>  $received
     * @return list<int>
     */
    private function missingFrom(CsvImportFile $file, array $received): array
    {
        return array_values(array_diff(range(0, $file->total_chunks - 1), $received));
    }

    /**
     * Bytes chunk `$index` must contain: a full chunk everywhere but the last,
     * and the remainder at the end.
     */
    public function expectedChunkBytes(CsvImportFile $file, int $index): int
    {
        $lastIndex = $file->total_chunks - 1;

        if ($index < $lastIndex) {
            return $file->chunk_size;
        }

        return $file->size_bytes - ($lastIndex * $file->chunk_size);
    }

    /**
     * @return array{status: 'duplicate', chunk: CsvImportFileChunk}
     */
    private function reconcileExistingChunk(CsvImportFileChunk $existing, int $index, string $declaredSha256): array
    {
        if (hash_equals($existing->sha256, $declaredSha256)) {
            return ['status' => 'duplicate', 'chunk' => $existing];
        }

        /**
         * Same slot, different content. The client is resuming against a
         * different local file than the one this run was opened for — an edited
         * export, or the wrong file picked in the dialog. Overwriting would
         * assemble a splice of two exports that still parses as valid CSV and
         * imports as real people.
         */
        throw new HttpResponseException(response()->json([
            'message' => "Chunk {$index} was already received with a different digest. This run was started for a different file — start a new run rather than resuming this one.",
            'stored_sha256' => $existing->sha256,
            'received_sha256' => $declaredSha256,
        ], 409));
    }

    /**
     * ASSEMBLY HAPPENS IN THE WEB REQUEST, AND MUST STAY THERE.
     *
     * php-fpm runs as www-data; the scheduler and queue workers run as root.
     * The `private` disk sets no `visibility`, so Flysystem creates DIRECTORIES
     * 0700 — measured, not assumed; the files themselves come out umask-derived
     * 0644, and it is the directory that does the gating. A run's directories
     * do not exist until the first chunk of it is written, so if a scheduled
     * job created them they would be root-owned 0700 and www-data could not
     * even traverse into them, let alone open the file inside. Root can read
     * what www-data wrote, so this looks fine in every test and on every dev
     * machine and fails only in production, silently: the file is there, and
     * the one process that needs it gets a permission error.
     *
     * The mode is worth stating precisely, because "chmod the files" is the
     * obvious wrong fix — the directory is the problem.
     * MovePrivateFilesOffPublicDisk.php:80-90 carries the same warning, learned
     * the same way.
     *
     * The budget is comfortable: the frontend's Next proxy allows 90 s and the
     * largest run this endpoint accepts is well inside a few seconds of
     * sequential copying.
     */
    private function assembleFile(CsvImportFile $file): void
    {
        $disk = $this->disk();
        $target = $this->assembledPath($file);

        /**
         * Built at a unique staging path and only then renamed into place.
         *
         * Verifying a file at its final path means a reader — or a second
         * assemble — can see a half-written file under the name that is
         * supposed to mean "verified", and a mismatch then deletes a path
         * somebody else may have already published. Rename on a local disk is
         * atomic, so the final name never exists in a partial state.
         */
        $staging = $target.'.'.bin2hex(random_bytes(8)).'.partial';

        $temp = tmpfile();

        if ($temp === false) {
            throw new RuntimeException('Unable to open a temporary file for assembly.');
        }

        try {
            /**
             * Ordered by `chunk_index` out of the database, never by whatever
             * order the directory happens to list its entries in. The
             * zero-padded filenames make a lexical `ls` agree with the true
             * order too, but that is a courtesy to whoever is looking at the
             * directory during an incident, not something this code relies on.
             *
             * stream_copy_to_stream copies in fixed-size blocks, so peak memory
             * is a buffer rather than the file — the difference between
             * assembling a 100 MiB export and exhausting memory_limit doing it.
             */
            foreach ($file->chunks()->orderBy('chunk_index')->cursor() as $chunk) {
                $in = $disk->readStream($chunk->path);

                if (! is_resource($in)) {
                    throw new RuntimeException("Chunk {$chunk->chunk_index} of {$file->kind} is recorded but missing from disk at {$chunk->path}.");
                }

                try {
                    stream_copy_to_stream($in, $temp);
                } finally {
                    fclose($in);
                }
            }

            rewind($temp);

            /**
             * The return value is checked because the `private` disk is
             * configured `'throw' => false`: FilesystemAdapter swallows
             * UnableToWriteFile, does not report it, and returns false. An
             * ignored false on ENOSPC or a permissions fault would leave a run
             * claiming an assembled file that was never written.
             */
            if ($disk->writeStream($staging, $temp) === false) {
                throw new RuntimeException("Unable to write the assembled {$file->kind} file to {$staging}.");
            }
        } finally {
            if (is_resource($temp)) {
                fclose($temp);
            }
        }

        // Hashed off the stored file rather than off the temp stream, so what
        // is verified is what actually landed on the volume.
        $assembledSha256 = $this->digestOf($disk, $staging);
        $assembledSize = (int) $disk->size($staging);

        if (! hash_equals($file->sha256, $assembledSha256)) {
            /**
             * The assembled file goes, the chunks stay.
             *
             * A whole-file mismatch after every part verified individually
             * means parts are missing, duplicated or out of order — all of
             * which the client fixes by re-sending specific chunks. Deleting
             * them here would turn a recoverable state into a full re-upload of
             * a coop's entire membership over a mobile connection.
             */
            // Only ever the staging path. Deleting `$target` here is what let a
            // concurrent assemble destroy a file another request had just
            // published.
            $disk->delete($staging);

            throw new HttpResponseException(response()->json([
                'message' => "The assembled {$file->kind} file does not match the digest declared when the run was opened. Its chunks have been kept so they can be re-sent.",
                'kind' => $file->kind,
                'declared_sha256' => $file->sha256,
                'assembled_sha256' => $assembledSha256,
                'declared_size_bytes' => $file->size_bytes,
                'assembled_size_bytes' => $assembledSize,
            ], 422));
        }

        $disk->delete($target);

        if (! $disk->move($staging, $target)) {
            $disk->delete($staging);

            throw new RuntimeException("Unable to publish the assembled {$file->kind} file to {$target}.");
        }

        $chunkPaths = $file->chunks()->pluck('path')->all();

        DB::transaction(function () use ($file, $target, $assembledSha256): void {
            $file->forceFill([
                'assembled_path' => $target,
                'assembled_sha256' => $assembledSha256,
            ])->save();

            $file->chunks()->delete();
        });

        // After the commit: an orphaned chunk file is a wasted megabyte, an
        // orphaned chunk ROW would be a phantom the next assemble tries to read.
        $disk->delete($chunkPaths);
        $disk->deleteDirectory($this->chunkDirectory($file));

        /**
         * A second sweep, for a chunk PUT that landed while this ran.
         *
         * The phase check refuses chunks once the run reaches `assembled`, but
         * that flips after the last file commits, so a request in flight can
         * still insert a row and write a part during assembly. The directory
         * removal above takes its bytes; this takes its row, which would
         * otherwise sit there as a phantom pointing at nothing. Free — it runs
         * once per file at assembly, never on the upload path.
         */
        $file->chunks()->delete();
    }

    private function digestOf(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read back the assembled file at {$path}.");
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * Chunk indexes already held, grouped by file id — one query for the whole
     * run rather than one per file.
     *
     * @param  Collection<int, CsvImportFile>  $files
     * @return array<int, list<int>>
     */
    private function receivedChunkIndexes(Collection $files): array
    {
        if ($files->isEmpty()) {
            return [];
        }

        $rows = CsvImportFileChunk::query()
            ->whereIn('csv_import_file_id', $files->pluck('id')->all())
            ->orderBy('chunk_index')
            ->get(['csv_import_file_id', 'chunk_index']);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->csv_import_file_id][] = (int) $row->chunk_index;
        }

        return $grouped;
    }

    private function assertUploading(CsvImportRun $run): void
    {
        if ($run->phase !== 'uploading') {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$run->id} is in phase '{$run->phase}' and is no longer accepting chunks.",
                'phase' => $run->phase,
            ], 409));
        }
    }

    /**
     * The filename reduced to something safe to store, log and print back.
     *
     * Nothing builds a path from this — chunk and assembled paths are derived
     * from ids — so this is hygiene rather than traversal defence: strip
     * directory separators of either flavour (basename() alone ignores `\\` on
     * Linux) and control characters, which have no place in a value an admin
     * screen renders.
     */
    private function safeFilename(string $filename): string
    {
        $filename = str_replace('\\', '/', $filename);
        $filename = basename($filename);
        $filename = preg_replace('/[\x00-\x1f\x7f]/u', '', $filename) ?? '';

        return mb_substr(trim($filename), 0, 255) ?: 'upload.csv';
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new HttpResponseException(response()->json([
                'message' => "Unknown import file kind '{$kind}'.",
            ], 404));
        }
    }

    /**
     * MySQL errno 1062 — duplicate entry for a key.
     *
     * Deliberately not the SQLSTATE (23000), which covers every integrity
     * constraint including foreign keys. Treating an FK failure as a duplicate
     * chunk would send it to a firstOrFail() that finds nothing and reports a
     * 404, burying the real error.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    private function runDirectory(CsvImportFile $file): string
    {
        return rtrim((string) config('imports.path_prefix'), '/').'/'.$file->csv_import_run_id;
    }

    private function chunkDirectory(CsvImportFile $file): string
    {
        return $this->runDirectory($file).'/chunks/'.$file->kind;
    }

    /**
     * Zero-padded, so a lexical listing of the directory is the concatenation
     * order. Nothing depends on that — assembly orders by `chunk_index` — but a
     * directory whose natural sort silently puts chunk 10 before chunk 2 is a
     * trap laid for whoever next has to look at one by hand.
     */
    private function chunkPath(CsvImportFile $file, int $index): string
    {
        return $this->chunkDirectory($file).'/'
            .str_pad((string) $index, self::CHUNK_INDEX_PAD, '0', STR_PAD_LEFT).'.part';
    }

    private function assembledPath(CsvImportFile $file): string
    {
        return $this->runDirectory($file).'/'.$file->kind.'.csv';
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
