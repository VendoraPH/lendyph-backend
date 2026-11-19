<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\ImportTick;
use App\Services\CsvImport\LoanReconstructionInput;
use App\Services\CsvImport\LoanScheduleReconstructor;
use App\Services\CsvImport\ReconstructedSchedule;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * Data Import "stuck at step 6" (ClickUp 14zfbg0dpj2): a run in
 * `importing_loans` whose status stops moving for more than the import
 * screen's 180-second stale warning.
 *
 * The screen measures staleness from the run row, which moves once per
 * committed window. So the run reads as stuck whenever a window takes long, or
 * a tick dies and nothing takes over for minutes.
 */
class ImportLoansStallTest extends TestCase
{
    use BuildsCsvImports;

    /** The import screen's stale warning (frontend STALE_AFTER_SECONDS). */
    private const STALE_AFTER_SECONDS = 180;

    private const LOCK_HOLDER = 'import_lock_holder';

    protected function tearDown(): void
    {
        DB::purge(self::LOCK_HOLDER);

        parent::tearDown();
    }

    private ?LoanProduct $product = null;

    /**
     * A run with its members imported and `$loans` loan rows still to do.
     *
     * @param  string  $prefix  Keeps a second run's members and loans apart from the first's.
     * @param  (callable(int): array<string, string>)|null  $loanOverrides  Per-row loan cells.
     */
    private function runAtTheLoansPhase(int $loans, ?CsvImportProcessor $processor = null, string $prefix = 'A', ?callable $loanOverrides = null): CsvImportRun
    {
        $this->product ??= LoanProduct::factory()->create(['name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 1000000]);
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $this->product->id]]);

        $customers = [];
        $loanRows = [];

        for ($i = 1; $i <= $loans; $i++) {
            $account = sprintf('%s-%03d', $prefix, $i);
            $customers[] = $this->customerRow($account);
            $loanRows[] = $this->loanRow($account, sprintf('L%s-%03d', $prefix, $i), $loanOverrides === null ? [] : $loanOverrides($i));
        }

        $this->makeFile($run, 'customers', $customers);
        $this->makeFile($run, 'loans', $loanRows);

        $processor ??= new CsvImportProcessor;

        for ($tick = 0; $tick < 50 && $run->phase !== 'importing_loans'; $tick++) {
            $processor->advance($run);
            $run->refresh();
        }

        $this->assertSame('importing_loans', $run->phase);
        $this->assertSame(0, $this->loansOf($run)->count());

        return $run;
    }

    /**
     * The loans a run wrote, in the order of the rows that wrote them.
     *
     * @return Collection<int, Loan>
     */
    private function loansOf(CsvImportRun $run): Collection
    {
        $loanIds = CsvImportRow::where('csv_import_file_id', $run->loansFile->id)
            ->whereNotNull('loan_id')
            ->orderBy('id')
            ->pluck('loan_id');

        $loans = Loan::whereIn('id', $loanIds)->get()->keyBy('id');

        return $loanIds->map(fn (int $id): Loan => $loans[$id])->values();
    }

    private function importEvent(): ScheduledEvent
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'imports:process'));
    }

    /**
     * The name the runner holds its database lock under. Per database, because
     * every deployment on the shared VPS talks to one MySQL server, and MySQL
     * lock names are server-wide.
     */
    private function importLockName(): string
    {
        return 'imports:process:'.sha1((string) DB::connection()->getDatabaseName());
    }

    /**
     * A second session, standing in for another `imports:process` process.
     */
    private function otherProcess(): Connection
    {
        config(['database.connections.'.self::LOCK_HOLDER => config('database.connections.'.config('database.default'))]);

        return DB::connection(self::LOCK_HOLDER);
    }

    /**
     * The budget is only checked between windows, so a tick overruns it by up
     * to one whole loans window, and the screen sees nothing until that window
     * commits. A loans window must stay small enough that slow rows never hold
     * a tick far past its budget.
     */
    public function test_a_tick_of_slow_loan_rows_stops_within_one_small_window_of_its_budget(): void
    {
        $this->seedForImport();

        // 200 ms a loan, many times a real row's cost, so the window size is
        // what decides how far past the budget the tick runs.
        $slow = new class extends LoanScheduleReconstructor
        {
            public function reconstruct(LoanReconstructionInput $input): ReconstructedSchedule
            {
                usleep(200_000);

                return parent::reconstruct($input);
            }
        };

        $processor = new CsvImportProcessor(schedules: $slow);
        $this->app->instance(CsvImportProcessor::class, $processor);

        $run = $this->runAtTheLoansPhase(30, $processor);

        $startedAt = microtime(true);
        Artisan::call('imports:process', ['--max-seconds' => 1, '--no-prune' => true]);
        $elapsed = microtime(true) - $startedAt;

        $imported = Loan::whereNotNull('external_loan_no')->count();

        $this->assertGreaterThan(0, $imported, 'The tick made no progress at all.');
        $this->assertSame(CsvImportProcessor::LOANS_CHUNK_SIZE, $imported, "One tick imported {$imported} slow loans on a 1-second budget: it should stop after one loans window.");
        // One window is about 2 s here; the room is for slow CI runners.
        $this->assertLessThan(6.0, $elapsed, sprintf('A 1-second tick ran for %.1f s.', $elapsed));
        $this->assertSame('importing_loans', $run->fresh()->phase);
    }

    /**
     * A tick that dies mid-window (killed by a deploy, the OOM killer, a box
     * restart) never releases the scheduler's overlap lock. While that lock
     * stands no tick runs, so the run cannot move, and the screen tells staff to
     * contact the admin. The lock must lapse before the stale warning would.
     */
    public function test_a_dead_ticks_schedule_lock_lapses_before_the_stale_warning(): void
    {
        $this->seedForImport();

        $event = $this->importEvent();

        // What a dead tick leaves behind: the mutex it took, never removed.
        $this->assertTrue($event->mutex->create($event));
        $this->assertTrue($event->shouldSkipDueToOverlapping(), 'A live tick must still block the next one.');

        // The next tick that can start is at a minute boundary, so the lock has
        // to be gone a full minute before the warning.
        $this->travel(self::STALE_AFTER_SECONDS - 60)->seconds();

        $this->assertFalse(
            $event->shouldSkipDueToOverlapping(),
            'The dead tick\'s lock still blocks imports:process after '.(self::STALE_AFTER_SECONDS - 60).' s, '
            .'so the run cannot move before the import screen shows its stale warning.',
        );
    }

    /**
     * What keeps a short schedule lock safe: two importers must never work at
     * once, even when a live tick outlasts the schedule lock. That exclusion is
     * a MySQL named lock, which the server frees the moment its process dies, so
     * a dead tick blocks nobody.
     */
    public function test_a_tick_stands_down_while_another_process_imports_and_resumes_once_that_one_dies(): void
    {
        $this->seedForImport();

        $run = $this->runAtTheLoansPhase(3);

        $other = $this->otherProcess();
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS got', [$this->importLockName()])->got);

        $exit = Artisan::call('imports:process', ['--no-prune' => true]);

        $this->assertSame(0, $exit, 'Standing down is a normal outcome, not a failure.');
        $this->assertSame(0, Loan::whereNotNull('external_loan_no')->count(), 'A second importer worked the run while another held the lock.');
        $this->assertSame(0, (int) CsvImportRow::where('csv_import_file_id', $run->loansFile->id)->sum('attempts'), 'The standing-down tick claimed attempts on rows it never worked.');
        $this->assertSame('importing_loans', $run->fresh()->phase);

        // The other process dies. Nothing releases its lock but the server.
        DB::disconnect(self::LOCK_HOLDER);
        $this->waitForLockToFree();

        Artisan::call('imports:process', ['--no-prune' => true]);

        $this->assertSame(3, Loan::whereNotNull('external_loan_no')->count());
        $this->assertSame('completed', $run->fresh()->phase);
    }

    /**
     * A tick that dies leaves no log line; the first moment it is knowable is
     * the next tick picking the window up again. That has to reach the log, or
     * an admin looking for why a run sat still finds nothing.
     */
    public function test_resuming_a_window_an_interrupted_tick_left_is_logged(): void
    {
        $this->seedForImport();

        $run = $this->runAtTheLoansPhase(2);

        // Where a tick killed mid-window leaves its rows: attempts claimed,
        // nothing committed.
        DB::table('csv_import_rows')->where('csv_import_file_id', $run->loansFile->id)->update(['attempts' => 1]);

        $entries = [];
        Event::listen(function (MessageLogged $message) use (&$entries): void {
            $entries[] = [(string) $message->level, (string) $message->message, $message->context];
        });

        Artisan::call('imports:process', ['--no-prune' => true]);

        $resumed = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry[0] === 'warning' && str_contains($entry[1], 'interrupted'),
        ));

        $this->assertCount(1, $resumed, 'Nothing in the log says a tick died. Logged: '.json_encode(array_column($entries, 1)));
        $this->assertSame($run->id, $resumed[0][2]['csv_import_run_id'] ?? null);
        $this->assertSame(2, Loan::whereNotNull('external_loan_no')->count());
    }

    /**
     * A dropped connection that Laravel reconnects is a new MySQL session, and
     * MySQL has freed the lock. The tick must notice before its next window and
     * stop, or it imports alongside whichever tick took the lock.
     */
    public function test_a_tick_that_loses_its_lock_mid_run_stops_before_the_next_window(): void
    {
        $this->seedForImport();

        $other = $this->otherProcess();
        $lock = $this->importLockName();

        // After its first window, this tick's session loses the lock (as on a
        // reconnect) and another process takes it.
        $processor = new class($other, $lock) extends CsvImportProcessor
        {
            public int $windows = 0;

            public function __construct(private Connection $other, private string $lock)
            {
                parent::__construct();
            }

            public function advance(CsvImportRun $run): ImportTick
            {
                $tick = parent::advance($run);

                if (++$this->windows === 1) {
                    DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lock]);
                    $this->other->selectOne('SELECT GET_LOCK(?, 0) AS got', [$this->lock]);
                }

                return $tick;
            }
        };

        $run = $this->runAtTheLoansPhase(25);
        $this->app->instance(CsvImportProcessor::class, $processor);

        $entries = [];
        Event::listen(function (MessageLogged $message) use (&$entries): void {
            $entries[] = [(string) $message->level, (string) $message->message];
        });

        $exit = Artisan::call('imports:process', ['--no-prune' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $processor->windows, 'The tick carried on working after it lost its lock.');
        $this->assertSame(CsvImportProcessor::LOANS_CHUNK_SIZE, $this->loansOf($run)->count());
        $this->assertContains(['warning', 'imports:process lost its lock mid-tick and stopped; the next tick carries on'], $entries);

        // The other process's lock is untouched by this tick's release.
        $this->assertSame((int) $other->selectOne('SELECT CONNECTION_ID() AS id')->id, (int) DB::selectOne('SELECT IS_USED_LOCK(?) AS holder', [$lock])->holder);
    }

    /**
     * The lock is released when a tick ends, normally or by an error outside a
     * run. Checked from another session: the holder can always re-take its own
     * lock, so that would prove nothing.
     */
    public function test_the_lock_is_free_for_other_processes_after_a_tick_ends_normally_or_by_an_error(): void
    {
        $this->seedForImport();

        $other = $this->otherProcess();
        $lock = $this->importLockName();

        $this->runAtTheLoansPhase(2);
        Artisan::call('imports:process', ['--no-prune' => true]);

        $this->assertSame(1, (int) $other->selectOne('SELECT IS_FREE_LOCK(?) AS free', [$lock])->free, 'A normal tick kept its lock.');

        // Resolving the storage sweep sits outside every guard in the command,
        // so a failure there ends the tick with an error.
        $this->app->bind('App\\Services\\CsvImport\\CsvImportUploadService', fn () => throw new RuntimeException('the sweep could not be built'));

        try {
            Artisan::call('imports:process');
            $this->fail('The tick was expected to end with the sweep\'s error.');
        } catch (RuntimeException $e) {
            $this->assertSame('the sweep could not be built', $e->getMessage(), 'Releasing the lock must not hide the error that ended the tick.');
        }

        $this->assertSame(1, (int) $other->selectOne('SELECT IS_FREE_LOCK(?) AS free', [$lock])->free, 'A tick that ended with an error kept its lock.');
    }

    /**
     * Loans windows change only the batching. Numbers rise with the file's row
     * order across windows, and every loan and schedule is written exactly as
     * the same file written in one window (writeChunk(), the transactional unit
     * the windows call).
     */
    public function test_ten_row_windows_write_the_same_loans_and_numbers_as_one_window(): void
    {
        $this->seedForImport();

        // Three sizes of loan, so a row written with another row's figures shows.
        $figures = function (int $i): array {
            $k = ($i % 3) + 1;

            return [
                'loan_amount' => number_format(60000 * $k, 2),
                'loan_balance' => number_format(40000 * $k, 2),
                'interest_amount' => number_format(10800 * $k, 2),
                'interest_balance' => number_format(7200 * $k, 2),
                'processing_fee' => number_format(1200 * $k, 2),
            ];
        };

        $windowed = $this->runAtTheLoansPhase(25, null, 'A', $figures);
        $whole = $this->runAtTheLoansPhase(25, null, 'B', $figures);

        // In windows: the scheduled path.
        Artisan::call('imports:process', ['--run' => $windowed->id, '--no-prune' => true]);
        $this->assertSame('completed', $windowed->fresh()->phase);

        // In one window: every row through the same unit of work at once.
        $rows = CsvImportRow::where('csv_import_file_id', $whole->loansFile->id)->orderBy('id')->get()->all();
        DB::transaction(fn () => (new CsvImportProcessor)->writeChunk($whole->fresh(), $rows, CsvImportSchema::LOANS));

        $a = $this->loansOf($windowed);
        $b = $this->loansOf($whole);

        $this->assertCount(25, $a);
        $this->assertCount(25, $b);

        // LA- numbers rise with the row id, with no gaps, across all three windows.
        $numbers = $a->map(fn (Loan $loan): int => (int) substr((string) $loan->application_number, 3))->all();
        $this->assertSame(range($numbers[0], $numbers[0] + 24), $numbers);

        $money = ['principal_amount', 'interest_rate', 'interest_method', 'term', 'term_unit', 'frequency', 'deductions', 'total_deductions', 'net_proceeds', 'status', 'start_date', 'maturity_date', 'released_at', 'penalty_rate', 'grace_period_days', 'imported_arrears_baseline', 'schedule_rounding'];

        foreach ($a as $index => $loan) {
            $other = $b[$index];

            $this->assertSame(
                collect($loan->getAttributes())->only($money)->all(),
                collect($other->getAttributes())->only($money)->all(),
                "Loan on row {$index} differs between windowed and one-window writes.",
            );

            $this->assertSame($this->schedule($loan), $this->schedule($other), "The schedule on row {$index} differs.");
        }
    }

    /**
     * A loan's schedule rows without their ids and timestamps.
     *
     * @return list<array<string, mixed>>
     */
    private function schedule(Loan $loan): array
    {
        return DB::table('amortization_schedules')
            ->where('loan_id', $loan->id)
            ->orderBy('period_number')
            ->get()
            ->map(fn (object $row): array => collect((array) $row)->except(['id', 'loan_id', 'created_at', 'updated_at'])->all())
            ->all();
    }

    private function waitForLockToFree(): void
    {
        for ($i = 0; $i < 40; $i++) {
            if ((int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS free', [$this->importLockName()])->free === 1) {
                return;
            }

            usleep(50_000);
        }

        $this->fail('MySQL did not free the dead session\'s lock within 2 s.');
    }
}
