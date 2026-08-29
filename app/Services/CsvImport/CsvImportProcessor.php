<?php

namespace App\Services\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportFile;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Drives one CSV import run forward by one unit of work at a time.
 *
 * The run is a state machine — staging, then an optional wait for a human to
 * confirm the product mapping, then customers, then loans — and this class owns
 * every transition. The command above it owns only the clock.
 *
 * ## The unit of work is a CHUNK, not a row
 *
 * Every domain write for ~50 staged rows happens in ONE transaction, and this is
 * forced rather than chosen. Borrower and Loan issue their own codes in a
 * `creating` hook that reads `orderByDesc('id')->lockForUpdate()`, and
 * SequenceAllocator takes that same lock on behalf of the whole chunk. A lock
 * only exists for the length of the transaction that took it, so a per-row
 * transaction would release it 50 times and let a teller's create land inside
 * the range the importer is working through — surfacing later as a unique-key
 * violation on a perfectly ordinary row.
 *
 * The `csv_import_rows` result stamps are written in that SAME transaction as
 * the borrowers and loans they describe. That is the property the whole design
 * rests on: a crash can leave the chunk entirely done or entirely undone, and
 * nothing else. Never a borrower with no record of where they came from, and
 * never a row marked `imported` with nothing behind it.
 *
 * ## Resume does not trust the cursor
 *
 * The resume point is re-derived every tick as
 * `MIN(id) WHERE csv_import_file_id = ? AND status = 'valid' AND result IS NULL`.
 * `csv_import_runs.cursor_row_id` is a progress hint for the UI and is never
 * read back as truth: it is written outside the chunk transaction, so a crash
 * can leave it ahead of what actually committed, and trusting it would skip
 * real rows silently. The NULL `result` is the only marker that cannot lie,
 * because it commits with the work.
 *
 * The window is then `id > $cursor AND id <= $cursor + CHUNK_SIZE`, the shape
 * TimezoneShift::apply() uses: it advances by a fixed amount whether or not the
 * rows inside it matched, so the walk terminates by construction rather than by
 * relying on rows falling out of their own WHERE clause.
 *
 * ## This class never CREATES a file
 *
 * The scheduler runs as root and php-fpm as www-data. A file created here would
 * be root-owned 0600 under a directory the web process cannot read, and that
 * only ever shows up in production.
 *
 * It does RESOLVE the `private` disk, through CsvImportStager and
 * CsvImportReader, and resolving a local disk mkdirs its root —
 * FilesystemManager::createLocalDriver() builds the adapter with
 * `$lazyRootCreation = false`. That is a directory, not a file, and it is
 * covered by the same rule for the same reason: see the fuller note on
 * ProcessCsvImports, which owns the uid discipline.
 */
class CsvImportProcessor
{
    /**
     * Rows per transaction. Big enough that the sequence lock is taken once per
     * fifty members rather than once per member; small enough that a rollback
     * costs a second of work and that the lock is not held long enough to make a
     * teller's "Save" spin.
     */
    public const CHUNK_SIZE = 50;

    /**
     * How many times a row may be picked up before it is given up on.
     *
     * This is NOT about rows that throw — those are isolated and stamped
     * `failed` within the same tick. It is about the case nothing else can
     * catch: a row whose processing kills the PROCESS (an OOM, a deploy
     * restart, the scheduler's own timeout). Nothing rolls back a signal, so
     * the attempt is claimed in its own committed statement BEFORE the work
     * starts. Without that, the same window would be re-derived and re-killed
     * every minute forever and the run would never move.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * `csv_import_runs.notes` keys, so the status and mapping endpoints read
     * the same strings this writes.
     */
    public const NOTE_LOAN_PRODUCTS = 'loan_products';

    public const NOTE_FILE_NOTES = 'file_notes';

    public const NOTE_SUMMARY = 'summary';

    /**
     * Phases nothing further will happen to.
     *
     * @var list<string>
     */
    public const TERMINAL_PHASES = ['completed', 'failed', 'cancelled'];

    /**
     * The `audit_logs.action` self::finalise() writes for each terminal phase.
     *
     * One action per outcome rather than one shared `csv_import_finished`,
     * because the question an auditor arrives with is "did this import finish or
     * was it stopped", and answering it by parsing a description string is how
     * the answer eventually becomes wrong. Keyed by phase so the map cannot
     * fall out of step with self::TERMINAL_PHASES without failing loudly.
     *
     * @var array<string, string>
     */
    public const FINAL_ACTIONS = [
        'completed' => 'csv_import_completed',
        'failed' => 'csv_import_failed',
        'cancelled' => 'csv_import_cancelled',
    ];

    /**
     * `csv_import_rows.result_category` for a loan that was imported but breaks
     * one of the bounds configured on the product it was mapped to.
     *
     * Points at ErrorReportBuilder's constant rather than restating the string.
     * That class publishes it as the error report's own name for this category,
     * and the two must not be able to drift.
     */
    public const CATEGORY_OUT_OF_PRODUCT_BOUNDS = ErrorReportBuilder::CATEGORY_OUT_OF_PRODUCT_BOUNDS;

    /**
     * Phases this class will pick up and move along.
     *
     * @var list<string>
     */
    public const WORKABLE_PHASES = ['assembled', 'staging', 'awaiting_mapping', 'importing_customers', 'importing_loans'];

    public function __construct(
        private readonly CsvImportStager $stager = new CsvImportStager,
        private readonly BorrowerMatcher $matcher = new BorrowerMatcher,
        private readonly SequenceAllocator $sequences = new SequenceAllocator,
        private readonly LoanScheduleReconstructor $schedules = new LoanScheduleReconstructor,
    ) {}

    /**
     * Advance one run by one unit of work.
     */
    public function advance(CsvImportRun $run): ImportTick
    {
        return match ($run->phase) {
            'assembled', 'staging' => $this->stagePhase($run),
            'awaiting_mapping' => $this->mappingGate($run),
            'importing_customers' => $this->importCustomers($run),
            'importing_loans' => $this->importLoans($run),
            default => new ImportTick($run->phase, 0, idle: true, note: "Phase [{$run->phase}] is not workable."),
        };
    }

    /**
     * Stage every file of the run that has not been staged yet, one per tick.
     *
     * One file per call rather than all of them, so the command's budget check
     * lands between the two files of a run. A single file is still atomic in the
     * sense that matters — see CsvImportStager::stage() — but it is not
     * interruptible, because a half-parsed file has no meaningful state.
     */
    private function stagePhase(CsvImportRun $run): ImportTick
    {
        // `customers` sorts before `loans`, which is also the order they have to
        // be imported in.
        $pending = $run->files()->orderBy('kind')->get()
            ->first(fn (CsvImportFile $file) => ! $this->stager->isStaged($file));

        if ($pending === null) {
            return $this->finishStaging($run);
        }

        $this->setPhase($run, 'staging');

        Log::info('csv-import: staging a file', [
            'csv_import_run_id' => $run->id,
            'csv_import_file_id' => $pending->id,
            'kind' => $pending->kind,
        ]);

        $result = $this->stager->stage($pending);

        $fileNotes = (array) (($run->notes ?? [])[self::NOTE_FILE_NOTES] ?? []);
        $fileNotes[$pending->kind] = $result->notes;

        $additions = [self::NOTE_FILE_NOTES => $fileNotes];

        if ($result->loanProducts !== []) {
            $additions[self::NOTE_LOAN_PRODUCTS] = $result->loanProducts;
        }

        $this->mergeNotes($run, $additions);

        return new ImportTick('staging', $result->staged, note: "Staged {$result->staged} {$pending->kind} row(s).");
    }

    /**
     * Everything is staged: either stop for the mapping or start importing.
     */
    private function finishStaging(CsvImportRun $run): ImportTick
    {
        $unmapped = $this->unmappedProducts($run);

        if ($unmapped !== []) {
            $this->setPhase($run, 'awaiting_mapping');

            Log::info('csv-import: waiting on a product mapping', [
                'csv_import_run_id' => $run->id,
                'unmapped_loan_products' => count($unmapped),
            ]);

            return new ImportTick('awaiting_mapping', 0, idle: true, note: 'Waiting for an admin to map '.count($unmapped).' loan product(s).');
        }

        return $this->beginCustomers($run);
    }

    /**
     * A run parked on the mapping screen moves on the moment the mapping COVERS
     * every product string staging found.
     *
     * This is the whole of the phase machine for that transition: the endpoint
     * that stores the mapping deliberately writes `product_mapping` and stops,
     * because `phase` is owned here. So a mapping that arrives while nobody is
     * looking still gets picked up on the next tick, and a mapping saved by a
     * request that then failed cannot leave the run parked forever.
     *
     * Coverage rather than mere presence, because a partial mapping would
     * otherwise start the import and hand the admin a few hundred failed loans
     * to fix by re-running — when what they actually needed was to finish
     * filling in the form they were already on.
     */
    private function mappingGate(CsvImportRun $run): ImportTick
    {
        // A run cannot be judged mapped before its files have been staged —
        // nothing knows what the products ARE yet. Landing here early (an
        // out-of-order phase write, a file that arrived late) must route back to
        // staging, or the gate would read an empty product list as "nothing to
        // map" and burn through the whole loans file failing every row.
        if ($run->files()->get()->contains(fn (CsvImportFile $file) => ! $this->stager->isStaged($file))) {
            return $this->stagePhase($run);
        }

        $unmapped = $this->unmappedProducts($run);

        if ($unmapped !== []) {
            return new ImportTick(
                'awaiting_mapping',
                0,
                idle: true,
                note: 'Waiting for an admin to map '.count($unmapped).' loan product(s).',
            );
        }

        return $this->beginCustomers($run);
    }

    /**
     * Staged product strings the confirmed mapping does not answer for.
     *
     * Compared with array_key_exists on the string EXACTLY as staged — the value
     * the mapping endpoint persists, byte for byte, blank cells included as a
     * `""` key. Re-trimming or re-folding it here would miss every key and fail
     * a whole cohort of loans as unmapped.
     *
     * This is a hash lookup and nothing about it may become order-sensitive:
     * `product_mapping` is a MySQL JSON column, so its keys come back sorted by
     * length then lexicographically rather than in the order the admin
     * confirmed them.
     *
     * Note that passing this check is NOT a guarantee every loan row will
     * resolve. The staged list is capped (CsvImportStager::MAX_DISTINCT_PRODUCTS)
     * and a mapping can point at a product that has since been deleted, so
     * importLoanRow() still fails rows individually — see the category it uses.
     *
     * @return list<string>
     */
    private function unmappedProducts(CsvImportRun $run): array
    {
        $staged = (array) (($run->notes ?? [])[self::NOTE_LOAN_PRODUCTS] ?? []);
        $mapping = (array) ($run->product_mapping ?? []);

        return array_values(array_filter(
            array_map(static fn ($product): string => (string) $product, $staged),
            static fn (string $product): bool => ! array_key_exists($product, $mapping),
        ));
    }

    private function beginCustomers(CsvImportRun $run): ImportTick
    {
        $this->setPhase($run, 'importing_customers', ['started_at' => $run->started_at ?? now()]);

        Log::info('csv-import: importing customers', [
            'csv_import_run_id' => $run->id,
            'branch_id' => $run->branch_id,
            'as_of_date' => $run->as_of_date?->toDateString(),
        ]);

        return new ImportTick('importing_customers', 0, note: 'Customer import started.');
    }

    private function importCustomers(CsvImportRun $run): ImportTick
    {
        $file = $run->customersFile;

        if ($file === null) {
            Log::info('csv-import: no customers file on this run, going straight to loans', [
                'csv_import_run_id' => $run->id,
            ]);

            return $this->finishCustomers($run);
        }

        $this->markInvalidRowsSkipped($file);

        $rows = $this->nextWindow($file);

        if ($rows === []) {
            return $this->finishCustomers($run);
        }

        $processed = $this->processWindow($run, $rows, CsvImportSchema::CUSTOMERS);
        $this->rememberCursor($run, $rows);

        Log::info('csv-import: customers chunk done', [
            'csv_import_run_id' => $run->id,
            'csv_import_file_id' => $file->id,
            'rows' => $processed,
            'from_row_id' => $rows[0]->id,
            'to_row_id' => $rows[array_key_last($rows)]->id,
            'remaining' => $this->pendingCount($file),
        ]);

        return new ImportTick('importing_customers', $processed);
    }

    private function finishCustomers(CsvImportRun $run): ImportTick
    {
        $loansFile = $run->loansFile;

        if ($loansFile === null) {
            return $this->completeRun($run);
        }

        if (! $this->stager->isStaged($loansFile)) {
            // Defensive: the loans file arrived after staging finished. Go back
            // rather than import from rows that do not exist yet.
            $this->setPhase($run, 'staging');

            return new ImportTick('staging', 0, note: 'The loans file still needs staging.');
        }

        $this->setPhase($run, 'importing_loans', ['cursor_row_id' => null]);

        Log::info('csv-import: customers complete, importing loans', [
            'csv_import_run_id' => $run->id,
            'csv_import_file_id' => $loansFile->id,
        ]);

        return new ImportTick('importing_loans', 0, note: 'Loan import started.');
    }

    private function importLoans(CsvImportRun $run): ImportTick
    {
        $this->assertCustomersComplete($run);

        $file = $run->loansFile;

        if ($file === null) {
            return $this->completeRun($run);
        }

        $this->markInvalidRowsSkipped($file);

        $rows = $this->nextWindow($file);

        if ($rows === []) {
            return $this->completeRun($run);
        }

        $processed = $this->processWindow($run, $rows, CsvImportSchema::LOANS);
        $this->rememberCursor($run, $rows);

        Log::info('csv-import: loans chunk done', [
            'csv_import_run_id' => $run->id,
            'csv_import_file_id' => $file->id,
            'rows' => $processed,
            'from_row_id' => $rows[0]->id,
            'to_row_id' => $rows[array_key_last($rows)]->id,
            'remaining' => $this->pendingCount($file),
        ]);

        return new ImportTick('importing_loans', $processed);
    }

    /**
     * The loans phase may not begin until the customers phase has finished.
     *
     * A loan row finds its member through `borrowers.external_account_no`, which
     * only exists once the customers file has been imported. Starting early does
     * not fail loudly — it fails as a pile of `borrower_not_found` rows for
     * members who are three chunks further down the customers file and would
     * have been created a minute later. The admin is then looking at hundreds of
     * failures caused entirely by ordering.
     *
     * Asserted here rather than only at the transition, because the phase column
     * can be set by anything with database access — a resumed run, a support
     * fix, a colleague's endpoint.
     *
     * @throws LogicException
     */
    public function assertCustomersComplete(CsvImportRun $run): void
    {
        $file = $run->customersFile;

        if ($file === null) {
            return;
        }

        $pending = $this->pendingCount($file);

        if ($pending > 0) {
            throw new LogicException(
                "Run #{$run->id} cannot import loans yet: {$pending} customer row(s) are still unprocessed. "
                .'Loan rows join to members through `borrowers.external_account_no`, so every loan belonging to a '
                .'member further down the customers file would fail to find them.'
            );
        }
    }

    /**
     * Rows this file still has to decide — the completion predicate, and the
     * same one nextWindow() derives its resume point from.
     */
    public function pendingCount(CsvImportFile $file): int
    {
        return CsvImportRow::query()
            ->where('csv_import_file_id', $file->id)
            ->where('status', 'valid')
            ->whereNull('result')
            ->count();
    }

    /**
     * Rows that failed validation at staging carry a result too.
     *
     * They are excluded from the work predicate by `status = 'valid'`, so this
     * changes nothing about what gets imported. It exists so that "rows with no
     * result" and "rows still to do" are the same set — otherwise every progress
     * figure in the UI, and CsvImportRow::scopePending(), counts the invalid
     * rows as outstanding work forever and the run never reads as finished.
     *
     * One indexed statement, idempotent, and after the first pass it matches
     * nothing.
     */
    private function markInvalidRowsSkipped(CsvImportFile $file): void
    {
        DB::table('csv_import_rows')
            ->where('csv_import_file_id', $file->id)
            ->where('status', 'invalid')
            ->whereNull('result')
            ->update([
                'result' => 'skipped',
                'result_category' => 'invalid_row',
                'result_message' => 'This row did not pass validation at staging and was not imported. See its per-field errors.',
                'updated_at' => now(),
            ]);
    }

    /**
     * The next chunk, re-derived from the data rather than from the cursor.
     *
     * @return list<CsvImportRow>
     */
    private function nextWindow(CsvImportFile $file): array
    {
        $min = DB::table('csv_import_rows')
            ->where('csv_import_file_id', $file->id)
            ->where('status', 'valid')
            ->whereNull('result')
            ->min('id');

        if ($min === null) {
            return [];
        }

        $cursor = (int) $min - 1;

        return CsvImportRow::query()
            ->where('csv_import_file_id', $file->id)
            ->where('status', 'valid')
            ->whereNull('result')
            ->where('id', '>', $cursor)
            ->where('id', '<=', $cursor + self::CHUNK_SIZE)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Process one window: claim the attempts, then write the chunk, falling back
     * to row-by-row isolation if anything throws.
     *
     * @param  list<CsvImportRow>  $rows
     */
    private function processWindow(CsvImportRun $run, array $rows, string $shape): int
    {
        $ids = array_map(static fn (CsvImportRow $row): int => $row->id, $rows);

        // Claimed in its own statement, committed before any work starts. A
        // transaction cannot protect against the process being killed, so the
        // counter that guards against that must not be inside one.
        DB::table('csv_import_rows')->whereIn('id', $ids)->update([
            'attempts' => DB::raw('attempts + 1'),
            'updated_at' => now(),
        ]);

        $workable = [];
        $retrying = false;

        foreach ($rows as $row) {
            $attempts = $row->attempts + 1;

            if ($attempts > self::MAX_ATTEMPTS) {
                $this->stamp($row, RowOutcome::failed(
                    'abandoned',
                    "This row was attempted {$attempts} times and did not complete. It was given up on so the rest "
                    .'of the import could continue; nothing was written for it.',
                ));

                Log::error('csv-import: abandoning a row after repeated attempts', [
                    'csv_import_run_id' => $run->id,
                    'csv_import_row_id' => $row->id,
                    'line_number' => $row->line_number,
                    'attempts' => $attempts,
                ]);

                continue;
            }

            // A row already carrying an attempt means a previous tick picked
            // this window up and never finished it. Going straight to row-by-row
            // stops one poisonous row taking its forty-nine neighbours down with
            // it on every subsequent tick until they all hit the cap.
            if ($row->attempts >= 1) {
                $retrying = true;
            }

            $workable[] = $row;
        }

        if ($workable === []) {
            return count($rows);
        }

        if (! $retrying) {
            try {
                DB::transaction(fn () => $this->writeChunk($run, $workable, $shape));

                return count($rows);
            } catch (Throwable $e) {
                // ImportErrorDigest, never $e->getMessage() — a QueryException's
                // message is the failing SQL with the bindings substituted, so
                // interpolating it here writes up to fifty members' full records
                // into a log file that never rotates. See that class.
                $context = ImportErrorDigest::context($e);

                Log::warning('csv-import: chunk rolled back, isolating its rows', [
                    'csv_import_run_id' => $run->id,
                    'shape' => $shape,
                    'rows' => count($workable),
                    'from_row_id' => $workable[0]->id,
                ] + $context);

                ImportErrorDigest::recordDiagnostics($e, [
                    'csv_import_run_id' => $run->id,
                    'shape' => $shape,
                    'from_row_id' => $workable[0]->id,
                ]);
            }
        }

        foreach ($workable as $row) {
            try {
                DB::transaction(fn () => $this->writeChunk($run, [$row], $shape));
            } catch (Throwable $e) {
                $context = ImportErrorDigest::context($e);

                Log::warning('csv-import: row failed and was isolated', [
                    'csv_import_run_id' => $run->id,
                    'csv_import_row_id' => $row->id,
                    'line_number' => $row->line_number,
                ] + $context);

                ImportErrorDigest::recordDiagnostics($e, [
                    'csv_import_run_id' => $run->id,
                    'csv_import_row_id' => $row->id,
                    'line_number' => $row->line_number,
                ]);

                // Stamped in its OWN transaction, after the failed one rolled
                // back. Writing it inside would have rolled back with the work
                // and left the row looking untouched, which is how a bad row
                // gets picked up forever.
                //
                // The stamp is fixed prose keyed to the line number, NOT the
                // exception message: this column is rendered by the admin error
                // screen and streamed by `errors.csv`, so a driver message
                // landing in it hands the member's own record back to the
                // browser. The line number is what makes it actionable; the
                // driver's numeric code is the only variable part.
                $this->stamp($row, RowOutcome::failed(
                    'exception',
                    ImportErrorDigest::forRow($e, (int) $row->line_number),
                ));
            }
        }

        return count($rows);
    }

    /**
     * The transactional unit of work: every domain write for this chunk, and the
     * result stamp for every row in it, in the caller's transaction.
     *
     * Public because it IS the boundary — a test has to be able to throw inside
     * it and prove the rollback took the borrowers AND the stamps with it.
     *
     * @param  list<CsvImportRow>  $rows
     *
     * @throws LogicException when called outside a transaction
     */
    public function writeChunk(CsvImportRun $run, array $rows, string $shape): void
    {
        // Takes the same row lock Borrower::booted() and Loan::booted() take,
        // and holds it for the whole chunk because the caller's transaction is
        // still open. The codes it returns are a prediction, not an assignment
        // — the hooks issue the real ones and arrive at the same values.
        $shape === CsvImportSchema::CUSTOMERS
            ? $this->sequences->allocateBorrowerCodes(count($rows))
            : $this->sequences->allocateApplicationNumbers(count($rows));

        // Drops the Auditable trait's per-model row WITHOUT dropping the model
        // event, so Borrower::booted()'s `created` hook still creates the
        // member's ShareCapitalPledge carrying the CSV's Pledge Amt.
        // `withoutEvents()`/`saveQuietly()` would have taken the pledge with it,
        // silently, and `pledges:backfill` hardcodes amount = 0 so it could
        // never be recovered. One summary audit row is written per run instead.
        //
        // THE SUPPRESSION IS PROCESS-WIDE, NOT MODEL-WIDE. It is a single static
        // flag on AuditLogService (deliberately — a static declared on the trait
        // would be a separate variable per model and could never cover Borrower
        // and Loan at once), so for the duration of this callback NOTHING
        // auditable writes an audit row, whether this method knows about it or
        // not. Today that is Borrower, Loan and the share capital pledge.
        //
        // The moment somebody adds co-makers, collateral, a document record or
        // any other Auditable model to the import, those silently lose their
        // audit rows too. There is no error and no warning; the only symptom is
        // an absence in `audit_logs` that nobody notices until an auditor asks.
        // If you add a write here, decide explicitly whether it belongs in the
        // run's summary row (self::finalise()) and put it there.
        AuditLogService::withoutModelAuditing(function () use ($run, $rows, $shape): void {
            foreach ($rows as $row) {
                $outcome = $shape === CsvImportSchema::CUSTOMERS
                    ? $this->importCustomerRow($run, $row)
                    : $this->importLoanRow($run, $row);

                $this->stamp($row, $outcome);
            }
        });
    }

    private function importCustomerRow(CsvImportRun $run, CsvImportRow $row): RowOutcome
    {
        $normalized = NormalizedRow::fromPayload((array) $row->normalized);

        $accountNo = (string) $normalized->value('account_no');
        $firstName = (string) $normalized->value('first_name');
        $lastName = (string) $normalized->value('last_name');
        $birthdate = $normalized->value('birthdate');

        $match = $this->matcher->match($accountNo, $firstName, $lastName, is_string($birthdate) ? $birthdate : null);

        if ($match->outcome === BorrowerMatch::ALREADY_IMPORTED) {
            return RowOutcome::alreadyImported($match->borrower?->id, (string) $match->reason);
        }

        if ($match->outcome === BorrowerMatch::BACKFILLED) {
            return RowOutcome::matchedExisting($match->borrower?->id, (string) $match->reason);
        }

        if ($match->needsReview()) {
            return RowOutcome::skipped($match->outcome, (string) $match->reason, $match->borrower?->id);
        }

        $street = $normalized->value('street_address');

        $borrower = Borrower::create([
            'external_account_no' => $accountNo,
            'first_name' => $firstName,
            'middle_name' => $normalized->value('middle_name'),
            'last_name' => $lastName,
            'suffix' => $normalized->value('suffix'),
            'birthdate' => $birthdate,
            'gender' => $normalized->value('gender'),
            'civil_status' => $normalized->value('civil_status'),
            'contact_number' => $normalized->value('contact_number'),
            'email' => $normalized->value('email'),
            /*
             * BOTH address columns, to the same value, and this is not
             * belt-and-braces.
             *
             * `borrowers.address` is the legacy column, and it is the one the
             * admin UI actually reads and writes — it is labelled "Street
             * Address" on the member form, and `street_address` is blank on all
             * 44 live members. Writing only `street_address` would make every
             * imported member's address invisible in the app, and the first
             * admin to open and save that member would write `address: null`
             * over the empty box while the real value sat unseen in the other
             * column. Writing only `address` would leave the newer column that
             * the API resources expose empty. So both, always, identically.
             */
            'address' => $street,
            'street_address' => $street,
            'barangay' => $normalized->value('barangay'),
            'city' => $normalized->value('city'),
            'province' => $normalized->value('province'),
            'employer_or_business' => $normalized->value('employer_or_business'),
            'monthly_income' => $this->pesos($this->money($normalized, 'monthly_income')),
            /*
             * Read by Borrower::booted()'s `created` hook to size the member's
             * ShareCapitalPledge, so this value has to be on the model BEFORE
             * the insert — it cannot be set afterwards. The column is NOT NULL
             * with a 0 default, so a blank cell becomes an explicit '0.00'.
             */
            'pledge_amount' => $this->pesos($this->money($normalized, 'pledge_amount')) ?? '0.00',
            'spouse_first_name' => $normalized->value('spouse_first_name'),
            'spouse_middle_name' => $normalized->value('spouse_middle_name'),
            'spouse_last_name' => $normalized->value('spouse_last_name'),
            'spouse_contact_number' => $normalized->value('spouse_contact_number'),
            'spouse_occupation' => $normalized->value('spouse_occupation'),
            'branch_id' => $run->branch_id,
            /*
             * `active`, never `pending`.
             *
             * `pending` means an unapproved registration, and Borrower::members()
             * excludes it from every read path that means "member" — the members
             * list, share capital, the pledge report. An imported member is not
             * an applicant; they are already on the coop's books. Importing them
             * as `pending` would migrate a cooperative's entire membership into
             * a state where none of it is visible.
             */
            'status' => 'active',
            /*
             * ...and WHO admitted them, and WHEN. See
             * CsvImportRun::admissionStamp(), which owns both values.
             *
             * `status = 'active'` on its own says a member was admitted while
             * `approved_at`/`approved_by` say nobody admitted them on no date,
             * which is not a gap in the record so much as a contradiction in it.
             * It is also not a stable gap: `registrations:backfill-approvals`
             * fills `whereNull('approved_at')` with `created_at` and a null
             * approver, so leaving these blank would let a later housekeeping
             * command invent an admission dated to the night of the upload.
             * Stamping here is what makes that command skip them.
             */
        ] + $run->admissionStamp());

        return RowOutcome::imported(
            borrowerId: $borrower->id,
            message: $normalized->warnings === [] ? null : $this->joinNotes($normalized->warningsToArray()),
            category: $normalized->warnings === [] ? null : 'imported_with_warnings',
        );
    }

    private function importLoanRow(CsvImportRun $run, CsvImportRow $row): RowOutcome
    {
        $normalized = NormalizedRow::fromPayload((array) $row->normalized);

        $loanNo = (string) $normalized->value('loan_no');
        $accountNo = (string) $normalized->value('account_no');

        // The unique index on `loans.external_loan_no` is the real backstop —
        // this lookup is what turns a re-run into a reported no-op instead of a
        // driver error.
        $existing = Loan::query()->where('external_loan_no', $loanNo)->first();

        if ($existing !== null) {
            return RowOutcome::alreadyImported(
                $existing->borrower_id,
                "Loan {$loanNo} is already on file as {$existing->application_number}, so the row was skipped.",
                loanId: $existing->id,
            );
        }

        $borrower = Borrower::query()->where('external_account_no', $accountNo)->first();

        if ($borrower === null) {
            return RowOutcome::failed(
                'borrower_not_found',
                "No member carries account number \"{$accountNo}\". The loan cannot be filed against anybody, so it "
                .'was not imported. Check that this account number appears in the customers file.',
            );
        }

        /*
         * Looked up on the staged string EXACTLY as it was staged — the same
         * value the mapping endpoint persisted as a key, blank cells included as
         * `""`. No re-trimming and no case folding: a re-normalised variant
         * would miss the key and fail every loan of that product as unmapped.
         *
         * A hash lookup, and it must stay one. `product_mapping` is a MySQL JSON
         * column and its key ORDER is rewritten on read; only the keys
         * themselves survive.
         */
        $productName = (string) ($normalized->value('loan_product') ?? '');
        $productId = ($run->product_mapping ?? [])[$productName] ?? null;
        $product = $productId === null ? null : LoanProduct::find($productId);

        if ($product === null) {
            /*
             * The category carries the product name, and that is deliberate.
             * The error report groups by `result_category`, and a single
             * `unmapped_product` bucket would render "312 rows failed" — true,
             * unactionable, and identical whether one product is missing or
             * nine. Naming it makes the line read "312 rows — Loan Product
             * 'Regular' could not be resolved", which is a thing an admin can
             * go and fix. The prefix stays stable so every unmapped-product
             * failure is still selectable with one LIKE.
             */
            return RowOutcome::failed(
                mb_substr('unmapped_product:'.($productName === '' ? '(blank)' : $productName), 0, 255),
                $productName === ''
                    ? 'This row has no Loan Product, so there is no product to resolve it against and the loan\'s '
                        .'penalty rate and grace period could not be established.'
                    : "The loan product \"{$productName}\" could not be resolved to a product in this system, so the "
                        ."loan's penalty rate and grace period could not be established. Check the product mapping "
                        .'for this import — the product it points at may have been removed.',
                borrowerId: $borrower->id,
            );
        }

        $schedule = $this->schedules->reconstruct(LoanReconstructionInput::fromNormalizedRow($normalized));

        if (! $schedule->isValid()) {
            return RowOutcome::failed(
                'schedule_not_reconstructable',
                $this->joinNotes($schedule->errors === [] ? [] : array_map(
                    static fn (RowNote $note): array => $note->toArray(),
                    $schedule->errors,
                )) ?: 'The payment schedule for this loan could not be rebuilt from the figures given.',
                borrowerId: $borrower->id,
            );
        }

        $principal = (int) $this->money($normalized, 'loan_amount');
        $deductions = $this->deductionsFor($normalized);
        $totalDeductions = array_sum(array_map(static fn (array $item): int => $item['centavos'], $deductions));

        if ($totalDeductions > $principal) {
            return RowOutcome::failed(
                'deductions_exceed_principal',
                'The fees on this loan add up to more than the principal, which would make its net proceeds '
                .'negative. Check the Processing Fee, Service Fee and Other Fee Amount cells.',
                borrowerId: $borrower->id,
            );
        }

        $loan = Loan::create([
            /*
             * `loan_account_number` is left NULL on purpose and must stay that
             * way. LoanService::release() issues the next number with
             * `(int) substr(MAX(loan_account_number), 3) + 1`, which assumes the
             * `LN-000123` shape; an external number like `2023-0041` parked
             * there yields 3, and the next release collides with a number that
             * already exists. Release would be permanently broken on that
             * deployment. release() skips NULLs, so the native sequence carries
             * on from the highest LN actually issued.
             */
            'external_loan_no' => $loanNo,
            /*
             * Where the coop's bookkeeping stops and ours starts. An imported
             * loan lands part-way through its life with due dates already
             * months old, so it is instantly overdue by every measure here —
             * but the penalties for those periods are already inside the
             * balances the coop handed over. Without this the night after an
             * import charges real members months of penalties twice.
             */
            'imported_arrears_baseline' => $run->as_of_date,
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $borrower->branch_id ?? $run->branch_id,
            'interest_rate' => $normalized->value('interest_rate'),
            'interest_method' => $normalized->value('interest_type'),
            // A PERIOD COUNT in units of `frequency`, derived from the dates by
            // the reconstructor. NOT the CSV's "Term in Months", which would
            // corrupt every loan that is not monthly.
            'term' => $schedule->term,
            'frequency' => $normalized->value('payment_frequency'),
            'principal_amount' => $this->pesos($principal),
            'purpose' => $normalized->value('purpose'),
            'start_date' => $normalized->value('date_released'),
            'maturity_date' => $normalized->value('maturity_date'),
            // ALWAYS an explicit array. A null here reads as "deductions unknown"
            // to every consumer, and the disclosure statement then has nothing to
            // itemise; an empty array says plainly that nothing was withheld.
            'deductions' => array_map(
                static fn (array $item): array => [
                    'name' => $item['name'],
                    'amount' => $item['amount'],
                    'type' => 'fixed',
                    'original_value' => $item['amount'],
                ],
                $deductions,
            ),
            'total_deductions' => $this->pesos($totalDeductions),
            'net_proceeds' => $this->pesos($principal - $totalDeductions),
            'penalty_rate' => $product->penalty_rate,
            'grace_period_days' => $product->grace_period_days,
            /*
             * `ongoing`, not `released`.
             *
             * RepaymentService flips released -> ongoing on the first payment,
             * so a live loan carrying part-paid periods is `ongoing` by
             * definition; an imported loan with any payment history that read
             * `released` would be a state the app never produces. Both are in
             * EVER_RELEASED_STATUSES and COLLECTIBLE_STATUSES, so reports agree
             * either way — but the loans screen's Current tab points at
             * `ongoing`, and that is where an operator expects to find a
             * migrated book.
             */
            'status' => 'ongoing',
            // The money genuinely did go out the door, on this date. Left null,
            // every disbursement and releases report would omit the coop's
            // entire existing portfolio. `released_by` stays null because no
            // user of this system released it.
            'released_at' => Carbon::parse((string) $normalized->value('date_released'))->startOfDay(),
            'created_by' => $run->initiated_by,
        ]);

        $now = now();

        DB::table('amortization_schedules')->insert(array_map(
            static fn (array $period): array => $period + [
                'loan_id' => $loan->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $schedule->toScheduleRows(),
        ));

        $warnings = array_merge($normalized->warningsToArray(), array_map(
            static fn (RowNote $note): array => $note->toArray(),
            $schedule->warnings,
        ));

        /*
         * Which of LoanService::createLoan()'s bounds this loan breaks, if any.
         *
         * The importer deliberately bypasses createLoan() — a migration has to
         * be able to carry a decade of loans that today's product configuration
         * would refuse, and refusing them would strand the members those loans
         * belong to. But bypassing the guard is not the same as pretending it
         * passed, so the breach is RECORDED and the loan is written anyway.
         *
         * DELEGATED TO ProductMappingResolver, AND NOT REIMPLEMENTED HERE. That
         * same function forecasts the damage on the mapping screen BEFORE the
         * run — "288 loans will disagree with their product" — and this stamps
         * the rows it actually turned out to be. Two copies of the arithmetic is
         * precisely how the screen promises 288 and the error report lists 291,
         * with nobody able to say which is lying. They cannot disagree while
         * they are the same function.
         *
         * `$principal` is INTEGER CENTAVOS and the resolver divides by 100;
         * handing it pesos would silently compare a 60,000 peso loan against
         * the bounds as though it were 600.
         *
         * `term_in_months` and not $schedule->term: the bounds on a product are
         * expressed in months and the admin is looking at the months column in
         * their own file. The reconstructed period count is a different number
         * in a different unit and comparing it here would report breaches that
         * are not there for every non-monthly loan.
         */
        $breaches = ProductMappingResolver::boundsBreaches(
            $product,
            $principal,
            $normalized->value('term_in_months'),
            $normalized->value('interest_rate'),
        );

        $messages = $warnings === [] ? [] : [$this->joinNotes($warnings)];

        if ($breaches !== []) {
            $messages[] = "This loan is outside the bounds configured on [{$product->name}] ("
                .implode(', ', $breaches).'). It was imported exactly as the file states it — a migration carries '
                .'the existing book across rather than re-underwriting it — but the same figures typed into the '
                .'new loan form would be refused.';
        }

        return RowOutcome::imported(
            borrowerId: $borrower->id,
            loanId: $loan->id,
            /*
             * A bounds breach OUTRANKS `imported_with_warnings`, and the order
             * is the whole point. Every normalisation warning is already on the
             * row in `normalized.warnings` and survives whatever category it is
             * filed under; a bounds breach has no other trace anywhere. Filing
             * it under the weaker category because the row also happened to
             * warn about a phone number would be the one way to lose it.
             */
            category: $breaches !== []
                ? self::CATEGORY_OUT_OF_PRODUCT_BOUNDS
                : ($warnings === [] ? null : 'imported_with_warnings'),
            message: $messages === [] ? null : implode(' ', $messages),
        );
    }

    /**
     * Fees withheld at release, as integer centavos plus their peso string.
     *
     * They are deductions, not part of the schedule arithmetic: LoanService
     * keeps them in `deductions`/`total_deductions`/`net_proceeds` and never
     * nets them against principal, so neither does this.
     *
     * @return list<array{name: string, amount: string, centavos: int}>
     */
    private function deductionsFor(NormalizedRow $normalized): array
    {
        $items = [];

        foreach ([['processing_fee', 'Processing Fee'], ['service_fee', 'Service Fee']] as [$key, $label]) {
            $centavos = $this->money($normalized, $key);

            if ($centavos !== null && $centavos > 0) {
                $items[] = ['name' => $label, 'amount' => (string) $this->pesos($centavos), 'centavos' => $centavos];
            }
        }

        $other = $this->money($normalized, 'other_fee_amount');

        if ($other !== null && $other > 0) {
            $detail = $normalized->value('other_fee_detail');

            $items[] = [
                'name' => is_string($detail) && $detail !== '' ? $detail : 'Other Fee',
                'amount' => (string) $this->pesos($other),
                'centavos' => $other,
            ];
        }

        return $items;
    }

    /**
     * Read one money value back out of the staged payload, holding it to being
     * integer centavos.
     *
     * The assertion is the point. `12500.0` written into a MySQL JSON column
     * comes back as int `12500` while `12500.5` comes back as a float, so a
     * payload that ever holds a float has already made whole pesos and pesos-
     * and-centavos behave differently — and nothing downstream would notice.
     * NormalizedRow::fromPayload() is what guarantees the type; this is the
     * check that the guarantee actually held, at the last point before the
     * value becomes a member's balance.
     */
    private function money(NormalizedRow $normalized, string $key): ?int
    {
        $value = $normalized->value($key);

        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new LogicException(
            "Staged money value [{$key}] came back as ".get_debug_type($value).' rather than integer centavos. '
            .'Money is written to the JSON column as a string precisely so the column cannot retype it.'
        );
    }

    /**
     * Integer centavos to an exact decimal STRING.
     *
     * A string, never a float: handing Eloquent a float to write into a
     * decimal(12,2) reintroduces at the last step exactly the representation
     * error that holding centavos all the way through was meant to avoid.
     */
    private function pesos(?int $centavos): ?string
    {
        if ($centavos === null) {
            return null;
        }

        $sign = $centavos < 0 ? '-' : '';
        $absolute = abs($centavos);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array{field: string, code: string, message: string}>  $notes
     */
    private function joinNotes(array $notes): string
    {
        return mb_substr(implode(' ', array_map(
            static fn (array $note): string => $note['message'],
            $notes,
        )), 0, 2000);
    }

    /**
     * Write one row's outcome.
     *
     * Through the query builder rather than the model, deliberately. An Eloquent
     * save on a model that was already "saved" inside a transaction that then
     * rolled back computes no dirty attributes and writes NOTHING — so the
     * isolation pass would silently fail to stamp exactly the rows it exists to
     * stamp.
     */
    private function stamp(CsvImportRow $row, RowOutcome $outcome): void
    {
        DB::table('csv_import_rows')->where('id', $row->id)->update([
            'result' => $outcome->result,
            'result_category' => $outcome->category,
            'result_message' => $outcome->message === null ? null : mb_substr($outcome->message, 0, 2000),
            'borrower_id' => $outcome->borrowerId,
            'loan_id' => $outcome->loanId,
            'updated_at' => now(),
        ]);
    }

    /**
     * A progress hint for the UI, and nothing more — see the class docblock.
     *
     * @param  list<CsvImportRow>  $rows
     */
    private function rememberCursor(CsvImportRun $run, array $rows): void
    {
        $run->forceFill(['cursor_row_id' => $rows[array_key_last($rows)]->id])->save();
    }

    private function completeRun(CsvImportRun $run): ImportTick
    {
        $this->finalise($run, 'completed');

        $run->refresh();

        Log::info('csv-import: run completed', [
            'csv_import_run_id' => $run->id,
            'summary' => ($run->notes ?? [])[self::NOTE_SUMMARY] ?? null,
        ]);

        return new ImportTick('completed', 0, idle: true, note: 'Run completed.');
    }

    /**
     * End a run: one summary audit row, then the terminal phase, together.
     *
     * ## Every way a run can end comes through here
     *
     * This used to live inside completeRun(), which meant the summary row was
     * written on exactly ONE of the four ways a run can reach a terminal phase.
     * The other three wrote nothing at all:
     *
     *  - anything throwing out of advance(), which the command catches and
     *    writes off as `failed`;
     *  - the 14-day abandoned sweep;
     *  - an operator cancelling after staging.
     *
     * None of those is exotic, and all three are reachable with members already
     * created: a run can import two thousand borrowers, go idle in
     * `importing_loans`, be swept a fortnight later, and leave `audit_logs`
     * completely empty. Two thousand member records created, the run written
     * off, and no trace anywhere that it happened.
     *
     * That is the whole point of the row. The per-model audit rows were
     * suppressed in writeChunk(), so this is the ONLY entry in `audit_logs`
     * saying a cooperative's membership and loan book appeared in one night. It
     * carries the same summarise() counts whatever the outcome, because how much
     * was written before a run died is exactly what an auditor asks about — a
     * failed run is not an empty one.
     *
     * ## Why it is public
     *
     * The cancel path lives on CsvImportUploadService, which is a different
     * class on a different branch. It calls this rather than growing its own
     * copy: one shape, one owner, and no way for the two to drift into
     * disagreeing about what a run's summary means.
     *
     * ## Locking
     *
     * The audit row and the phase are written in one transaction taken under a
     * row lock, and the phase is RE-READ under that lock rather than trusted
     * from a model loaded a minute ago. Two overlapping schedulers, or a cancel
     * racing a completion, therefore produce one summary row and not two — the
     * loser sees a terminal phase and returns.
     *
     * @param  string  $phase  One of self::TERMINAL_PHASES.
     * @param  string|null  $failureReason  Persisted to `failure_reason`. MUST
     *                                      already be safe to show a browser —
     *                                      see ImportErrorDigest.
     * @param  int|null  $userId  Defaults to the run's initiator. The scheduler
     *                            has no `auth()` user, so the accountable human
     *                            has to come off the run, where it was captured
     *                            when they asked for the work. An HTTP caller
     *                            (cancel) should pass the actor instead.
     * @param  string|null  $ipAddress  Same, for `initiated_ip`.
     * @return CsvImportRun|null The finalised run, or null if it was already
     *                           terminal (or gone) and this call did nothing.
     *
     * @throws LogicException when $phase is not terminal
     */
    public function finalise(
        CsvImportRun $run,
        string $phase,
        ?string $failureReason = null,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): ?CsvImportRun {
        if (! in_array($phase, self::TERMINAL_PHASES, true)) {
            throw new LogicException(
                "finalise() ends a run, so [{$phase}] must be one of ["
                .implode(', ', self::TERMINAL_PHASES).']. Use setPhase() to move a run along instead.'
            );
        }

        return DB::transaction(function () use ($run, $phase, $failureReason, $userId, $ipAddress): ?CsvImportRun {
            $locked = CsvImportRun::query()->whereKey($run->id)->lockForUpdate()->first();

            if ($locked === null || in_array($locked->phase, self::TERMINAL_PHASES, true)) {
                return null;
            }

            $summary = $this->summarise($locked);
            $newValues = $failureReason === null ? $summary : $summary + ['failure_reason' => $failureReason];

            AuditLogService::log(
                action: self::FINAL_ACTIONS[$phase],
                auditable: $locked,
                newValues: $newValues,
                description: $this->finalDescription($locked, $phase, $summary),
                userId: $userId ?? $locked->initiated_by,
                ipAddress: $ipAddress ?? $locked->initiated_ip,
            );

            $attributes = [
                'phase' => $phase,
                'finished_at' => now(),
                // The counts go on the run as well as in the audit row: the
                // status screen is where somebody asks "how far did it get"
                // about a run that did not finish.
                'notes' => array_merge((array) $locked->notes, [self::NOTE_SUMMARY => $summary]),
            ];

            if ($failureReason !== null) {
                $attributes['failure_reason'] = mb_substr($failureReason, 0, 2000);
            }

            $locked->forceFill($attributes)->save();

            return $locked;
        });
    }

    /**
     * The audit row's human sentence.
     *
     * The counts are stated for every outcome, not just the happy one: "failed"
     * on its own invites the reading that nothing was written, and on this
     * importer that is usually false.
     *
     * @param  array<string, mixed>  $summary
     */
    private function finalDescription(CsvImportRun $run, string $phase, array $summary): string
    {
        $written = "{$summary['borrowers_imported']} member(s) and {$summary['loans_imported']} loan(s)";

        return match ($phase) {
            'completed' => "CSV migration run #{$run->id} completed: {$written} imported.",
            'failed' => "CSV migration run #{$run->id} failed: {$written} had already been imported when it stopped.",
            'cancelled' => "CSV migration run #{$run->id} cancelled: {$written} had already been imported when it was cancelled.",
        };
    }

    /**
     * Per-file, per-outcome counts for the summary audit row.
     *
     * @return array<string, mixed>
     */
    private function summarise(CsvImportRun $run): array
    {
        $counts = [];

        foreach ($run->files as $file) {
            $counts[$file->kind] = DB::table('csv_import_rows')
                ->where('csv_import_file_id', $file->id)
                ->selectRaw('coalesce(result, \'pending\') as result, count(*) as total')
                ->groupBy('result')
                ->pluck('total', 'result')
                ->map(static fn ($total): int => (int) $total)
                ->all();
        }

        return [
            'run_id' => $run->id,
            'branch_id' => $run->branch_id,
            'as_of_date' => $run->as_of_date?->toDateString(),
            'borrowers_imported' => (int) ($counts['customers']['imported'] ?? 0),
            'borrowers_matched' => (int) ($counts['customers']['matched_existing'] ?? 0)
                + (int) ($counts['customers']['already_imported'] ?? 0),
            'loans_imported' => (int) ($counts['loans']['imported'] ?? 0),
            'by_file' => $counts,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function setPhase(CsvImportRun $run, string $phase, array $extra = []): void
    {
        if ($run->phase === $phase && $extra === []) {
            return;
        }

        $run->forceFill(['phase' => $phase] + $extra)->save();
    }

    /**
     * Set some keys of `csv_import_runs.notes` and leave the rest alone.
     *
     * The two files stage on different ticks and each contributes, so a
     * wholesale overwrite would drop whichever went first. Top-level keys are
     * REPLACED rather than deep-merged: the caller has already composed the
     * value it wants, and merging a list would append duplicates on any second
     * pass.
     *
     * @param  array<string, mixed>  $additions
     */
    private function mergeNotes(CsvImportRun $run, array $additions): void
    {
        $run->forceFill([
            'notes' => array_replace((array) ($run->notes ?? []), $additions),
        ])->save();
    }
}
