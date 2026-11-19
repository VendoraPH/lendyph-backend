<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\LoanReconstructionInput;
use App\Services\CsvImport\LoanScheduleReconstructor;
use App\Services\CsvImport\ReconstructedSchedule;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    /**
     * A run with its members imported and `$loans` loan rows still to do.
     */
    private function runAtTheLoansPhase(int $loans, ?CsvImportProcessor $processor = null): CsvImportRun
    {
        $product = LoanProduct::factory()->create(['name' => 'Salary Loan', 'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 1000000]);
        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);

        $customers = [];
        $loanRows = [];

        for ($i = 1; $i <= $loans; $i++) {
            $customers[] = $this->customerRow(sprintf('A-%03d', $i));
            $loanRows[] = $this->loanRow(sprintf('A-%03d', $i), sprintf('L-%03d', $i));
        }

        $this->makeFile($run, 'customers', $customers);
        $this->makeFile($run, 'loans', $loanRows);

        $processor ??= new CsvImportProcessor;

        for ($tick = 0; $tick < 50 && $run->phase !== 'importing_loans'; $tick++) {
            $processor->advance($run);
            $run->refresh();
        }

        $this->assertSame('importing_loans', $run->phase);
        $this->assertSame(0, Loan::whereNotNull('external_loan_no')->count());

        return $run;
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
        return mb_substr('imports:process:'.DB::connection()->getDatabaseName(), 0, 64);
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
        $this->assertLessThanOrEqual(10, $imported, "One tick imported {$imported} slow loans on a 1-second budget: the loans window is too large to stop near the budget.");
        $this->assertLessThan(4.0, $elapsed, sprintf('A 1-second tick ran for %.1f s.', $elapsed));
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
