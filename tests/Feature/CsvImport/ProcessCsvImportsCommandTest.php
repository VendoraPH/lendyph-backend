<?php

namespace Tests\Feature\CsvImport;

use App\Console\Commands\ProcessCsvImports;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\LoanProduct;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvImportStager;
use ArrayObject;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * The runner itself: the budget, the audit row, the log, and the two things it
 * must never do — write a file, or hold the overlap lock.
 */
class ProcessCsvImportsCommandTest extends TestCase
{
    use BuildsCsvImports;

    /**
     * Capture what actually reaches the log, rather than merely that something
     * did.
     *
     * Read off the `MessageLogged` event rather than a mocked facade, because
     * the requirement is about the log being the only surviving channel: the
     * scheduler sends stdout to /dev/null and discards the exit code, so a test
     * that asserted on console output would be checking the one thing nobody in
     * production can see.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function captureLog(callable $callback): array
    {
        $entries = [];

        Event::listen(function (MessageLogged $message) use (&$entries): void {
            $entries[] = [
                'level' => (string) $message->level,
                'message' => (string) $message->message,
                'context' => $message->context,
            ];
        });

        $callback();

        return $entries;
    }

    /**
     * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $entries
     */
    private function assertLogged(array $entries, string $needle, ?string $level = null): void
    {
        foreach ($entries as $entry) {
            if (str_contains($entry['message'], $needle) && ($level === null || $entry['level'] === $level)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("Nothing was logged matching \"{$needle}\". The scheduler discards console output and the exit "
            .'code, so anything not in the log is invisible in production. Logged: '
            .json_encode(array_column($entries, 'message')));
    }

    /**
     * Start, progress, every caught row failure, and completion — all through
     * Log, because none of them reaches anybody any other way.
     */
    public function test_every_meaningful_event_reaches_the_log(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001'),
            $this->customerRow('A-002'),
        ]);

        (new CsvImportStager)->stage($file);

        // A row that will throw inside the chunk, so the failure path is
        // exercised in the same tick as the success path.
        $poison = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->skip(1)->first();
        $payload = json_decode((string) $poison->getRawOriginal('normalized'), true);
        $income = array_search('monthly_income', CsvImportSchema::keys(CsvImportSchema::CUSTOMERS), true);
        // An int where a string belongs: exactly what a payload written as
        // floats would have read back as.
        $payload['values'][$income] = 18500;
        DB::table('csv_import_rows')->where('id', $poison->id)->update(['normalized' => json_encode($payload)]);

        $entries = $this->captureLog(fn () => Artisan::call('imports:process'));

        $this->assertLogged($entries, 'imports:process starting', 'info');
        $this->assertLogged($entries, 'csv-import: importing customers', 'info');
        $this->assertLogged($entries, 'csv-import: customers chunk done', 'info');
        $this->assertLogged($entries, 'csv-import: chunk rolled back', 'warning');
        $this->assertLogged($entries, 'csv-import: row failed and was isolated', 'warning');
        $this->assertLogged($entries, 'csv-import: run completed', 'info');
        $this->assertLogged($entries, 'imports:process finished', 'info');
    }

    /**
     * A budget exit is a normal outcome and must report success: returning
     * promptly is precisely what stops a long import holding the
     * `withoutOverlapping` lock and blocking every tick behind it.
     *
     * The budget is honoured at CHUNK boundaries, not row boundaries, and that
     * is not a rounding error — a chunk is one transaction and cannot be cut in
     * half without giving up the property the whole design exists for. So the
     * file below is deliberately larger than one chunk: with a row budget of 5
     * the runner still finishes the chunk it started, commits it, and stops.
     */
    public function test_a_budget_exit_returns_success_with_the_work_committed_so_far(): void
    {
        $this->seedForImport();

        $total = CsvImportProcessor::CHUNK_SIZE + 10;
        $run = $this->makeRun();
        $rows = [];

        for ($i = 1; $i <= $total; $i++) {
            $rows[] = $this->customerRow(sprintf('A-%03d', $i));
        }

        $file = $this->makeFile($run, 'customers', $rows);
        (new CsvImportStager)->stage($file);
        $run->forceFill(['phase' => 'importing_customers'])->save();

        $exit = Artisan::call('imports:process', ['--max-rows' => 5]);

        $this->assertSame(0, $exit, 'A budget exit is not a failure.');

        $run->refresh();
        $this->assertSame('importing_customers', $run->phase);

        // Committed, not merely attempted, and the cursor moved.
        $imported = Borrower::whereNotNull('external_account_no')->count();
        $this->assertSame(CsvImportProcessor::CHUNK_SIZE, $imported);
        $this->assertLessThan($total, $imported);
        $this->assertNotNull($run->cursor_row_id);

        // And the next tick picks up exactly where the rows say it should,
        // without re-importing anything.
        Artisan::call('imports:process');

        $this->assertSame($total, Borrower::whereNotNull('external_account_no')->count());
        $this->assertSame('completed', $run->fresh()->phase);
    }

    /**
     * Exactly one audit row for the whole run, naming the admin who asked for
     * it, from a process that has no `auth()` user and no `request()`.
     *
     * The per-model rows were suppressed, so this is the ONLY trace in
     * `audit_logs` that a cooperative's membership and loan book appeared in one
     * night — which makes it the row an auditor will ask about.
     */
    public function test_it_writes_exactly_one_audit_row_per_run_naming_the_initiator(): void
    {
        $this->seedForImport();

        $product = LoanProduct::factory()->create(['name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 1000000]);
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001'), $this->customerRow('A-002')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1'), $this->loanRow('A-002', 'L-2')]);

        $this->assertNull(auth()->id(), 'The runner must be tested with nobody authenticated — that is its real condition.');

        Artisan::call('imports:process');
        // Run again: a completed run must not write a second summary.
        Artisan::call('imports:process');

        $logs = AuditLog::where('action', 'csv_import_completed')->get();

        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('203.0.113.9', $log->ip_address);
        $this->assertSame(CsvImportRun::class, $log->auditable_type);
        $this->assertSame($run->id, $log->auditable_id);
        $this->assertSame(2, $log->new_values['borrowers_imported']);
        $this->assertSame(2, $log->new_values['loans_imported']);

        // And nothing per-row.
        $this->assertSame(0, AuditLog::where('auditable_type', Borrower::class)->count());
    }

    /**
     * The runner must never CREATE a file, and the verb is the whole assertion.
     *
     * The scheduler runs as root and php-fpm as www-data, so a file root creates
     * is root-owned and the web process can never read it back — a failure that
     * only appears in production. Unlinking is a different act entirely: it
     * creates nothing and needs only write permission on the parent directory,
     * which root has. So this asserts that nothing NEW appeared, not that the
     * listing is unchanged — the orphaned-storage sweep legitimately removes
     * files, and an equality assertion here would fail the moment that sweep
     * becomes reachable.
     */
    public function test_the_runner_never_creates_a_file(): void
    {
        $this->seedForImport();

        $product = LoanProduct::factory()->create(['name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 1000000]);
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1')]);

        $before = Storage::disk('private')->allFiles();

        Artisan::call('imports:process');

        $this->assertSame('completed', $run->fresh()->phase, 'The run has to have actually done its work for this to mean anything.');

        $created = array_values(array_diff(Storage::disk('private')->allFiles(), $before));

        $this->assertSame([], $created, 'The runner created a file. Running as root, that file is unreadable by php-fpm forever.');
    }

    /**
     * The orphaned-storage sweep, which closes the one gap the upload service
     * cannot close on its own.
     *
     * Its own listener never fires for this command — `Model::query()->update()`
     * is a mass update and Eloquent dispatches no model events for it — so the
     * runs written off above reach `failed` with nothing cleaning up after them.
     * The upload service reconciles on the next import; this command reconciles
     * when there is no next import, which is exactly the abandoned case.
     */
    public function test_it_asks_the_upload_service_to_release_orphaned_storage(): void
    {
        $this->seedForImport();

        $this->makeRun(['phase' => 'completed']);

        // An ArrayObject rather than an int: a promoted `&$calls` parameter is
        // assigned by value, so the fake would tick a copy and this would fail
        // for a reason that has nothing to do with the command.
        $calls = new ArrayObject(['count' => 0]);

        $this->app->bind(ProcessCsvImportsCommandTest::uploadService(), fn () => new class($calls)
        {
            public function __construct(private ArrayObject $calls) {}

            public function releaseAbandonedStorage(int $limit = 25): int
            {
                $this->calls['count']++;

                return 0;
            }
        });

        $this->assertSame(0, Artisan::call('imports:process'));
        $this->assertSame(1, $calls['count'], 'The command finished a tick without asking for orphaned storage to be released.');
    }

    /**
     * Somebody else's abandoned upload failing to clean up must never fail this
     * tick or block the next import. The import work is the point of the
     * command; housekeeping is not allowed to take it down with it.
     */
    public function test_a_failing_storage_sweep_does_not_fail_the_tick(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);

        $this->app->bind(ProcessCsvImportsCommandTest::uploadService(), fn () => new class
        {
            public function releaseAbandonedStorage(int $limit = 25): int
            {
                throw new RuntimeException('the private disk went away');
            }
        });

        $entries = $this->captureLog(fn () => $this->assertSame(0, Artisan::call('imports:process')));

        // The import still happened, and the failure was reported where somebody
        // can actually read it.
        $this->assertSame('completed', $run->fresh()->phase);
        $this->assertSame(1, Borrower::where('external_account_no', 'A-001')->count());
        $this->assertLogged($entries, 'orphaned-storage sweep failed', 'error');
    }

    /**
     * The command's own name for the upload service, read off the command rather
     * than duplicated here — a copy would keep passing after a rename while the
     * sweep silently stopped being called.
     */
    public static function uploadService(): string
    {
        return (new ReflectionClass(ProcessCsvImports::class))->getConstant('UPLOAD_SERVICE');
    }

    /**
     * A run nobody has touched in a fortnight is written off rather than left in
     * the UI's in-progress list forever.
     *
     * Written off, NOT deleted: deleting cascades to the files and staged rows
     * while this command must not touch the filesystem, which would leave the
     * uploaded CSVs behind as orphaned member PII with nothing pointing at them.
     */
    public function test_it_writes_off_runs_abandoned_for_a_fortnight(): void
    {
        $this->seedForImport();

        // The commonest abandonment: an upload started and walked away from.
        $staleUpload = $this->makeRun(['phase' => 'uploading']);

        // And one genuinely parked on the mapping screen: staged, with a product
        // string nobody ever mapped.
        $staleMapping = $this->makeRun([
            'phase' => 'awaiting_mapping',
            'notes' => [CsvImportProcessor::NOTE_LOAN_PRODUCTS => ['Salary Loan']],
        ]);

        $fresh = $this->makeRun(['phase' => 'uploading']);
        $done = $this->makeRun(['phase' => 'completed']);

        DB::table('csv_import_runs')
            ->whereIn('id', [$staleUpload->id, $staleMapping->id, $done->id])
            ->update(['updated_at' => now()->subDays(20)]);

        Artisan::call('imports:process');

        $this->assertSame('failed', $staleUpload->fresh()->phase);
        $this->assertStringContainsString('Abandoned', (string) $staleUpload->fresh()->failure_reason);
        $this->assertStringContainsString('uploading', (string) $staleUpload->fresh()->failure_reason);

        $this->assertSame('failed', $staleMapping->fresh()->phase);
        $this->assertStringContainsString('awaiting_mapping', (string) $staleMapping->fresh()->failure_reason);

        // Still waiting, not abandoned.
        $this->assertSame('uploading', $fresh->fresh()->phase);
        // Terminal phases are left alone — a completed run is not abandoned.
        $this->assertSame('completed', $done->fresh()->phase);
    }

    /**
     * A run that cannot be advanced is written off with the reason on it, rather
     * than left in a phase the next tick picks up and throws on again every
     * minute forever. The command still succeeds, and the other runs still run.
     */
    public function test_a_run_that_cannot_advance_is_written_off_and_does_not_stop_the_others(): void
    {
        $this->seedForImport();

        $broken = $this->makeRun();
        $this->makeFile($broken, 'customers', [$this->customerRow('A-001')]);
        Storage::disk('private')->delete("csv-imports/{$broken->id}/customers.csv");

        $healthy = $this->makeRun();
        $this->makeFile($healthy, 'customers', [$this->customerRow('B-001')]);

        $exit = Artisan::call('imports:process');

        $this->assertSame(0, $exit);
        $this->assertSame('failed', $broken->fresh()->phase);
        $this->assertNotNull($broken->fresh()->failure_reason);
        $this->assertSame('completed', $healthy->fresh()->phase);
        $this->assertSame(1, Borrower::where('external_account_no', 'B-001')->count());
    }

    /**
     * The schedule entry itself. Registered every minute and non-overlapping —
     * without the second, a tick killed mid-chunk would be joined by the next
     * minute's tick working the same rows.
     */
    public function test_it_is_scheduled_every_minute_without_overlapping(): void
    {
        $this->seedForImport();

        $events = collect(app(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'imports:process'));

        $this->assertCount(1, $events, 'imports:process is not on the schedule, so nothing would ever run it.');

        $event = $events->first();

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'Two importers on the same rows is exactly what this prevents.');
        $this->assertSame(10, $event->expiresAt);
    }

    /**
     * The gate the whole two-phase design exists for, driven through the command
     * rather than the service: customers first, loans second, never together.
     */
    public function test_it_imports_customers_before_loans_end_to_end(): void
    {
        $this->seedForImport();

        $product = LoanProduct::factory()->create(['name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 1000000]);
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $customers = $this->makeFile($run, 'customers', [$this->customerRow('A-001'), $this->customerRow('A-002')]);
        $loans = $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-1'), $this->loanRow('A-002', 'L-2')]);

        // One row at a time, so the phase can be observed between them.
        $seen = [];

        for ($tick = 0; $tick < 20 && $run->fresh()->phase !== 'completed'; $tick++) {
            Artisan::call('imports:process', ['--max-rows' => 1]);
            $seen[] = $run->fresh()->phase;
        }

        $this->assertSame('completed', $run->fresh()->phase);

        // Loans never started while a customer row was outstanding.
        $firstLoanTick = array_search('importing_loans', $seen, true);
        $lastCustomerTick = array_keys($seen, 'importing_customers', true);

        $this->assertNotFalse($firstLoanTick);
        $this->assertLessThan($firstLoanTick, max($lastCustomerTick));

        $processor = new CsvImportProcessor;
        $this->assertSame(0, $processor->pendingCount($customers->fresh()));
        $this->assertSame(0, $processor->pendingCount($loans->fresh()));
    }
}
