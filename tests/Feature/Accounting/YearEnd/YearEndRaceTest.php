<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingJournal;
use App\Models\AccountingYearClose;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\YearEndCloser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A posting, and a second close, racing a year-end close, on two real
 * connections.
 *
 * The close locks every period row of the year FOR UPDATE as its first
 * statement. PeriodGuard reads the covering period under a shared lock, so a
 * posting into the year started while the close is open waits on it (here
 * its lock wait times out after one second), and once the close commits the
 * month is closed and the posting is refused. A second close waits on the
 * same rows and answers 409.
 */
class YearEndRaceTest extends TestCase
{
    use PostsJournals, SetupLendyPH, YearEndFixtures;

    /**
     * Opted out of the per-test transaction: the second connection can only
     * see rows the first one has committed.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'year_end_race';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYearEnd();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    public function test_a_posting_racing_the_close_waits_for_it_and_is_then_refused(): void
    {
        $this->postTheYear();

        $outcome = $this->whileTheCloseIsOpen(fn (): string => $this->postOnTheOtherConnection('2025-06-15'));

        $this->assertSame('waited', $outcome, 'the posting did not wait for the close');
        $this->assertSame('refused', $this->postOnTheOtherConnection('2025-06-15'));
        $this->assertSame(0, AccountingJournal::query()->where('date', '2025-06-15')->count());
    }

    public function test_a_second_close_racing_the_first_answers_409(): void
    {
        $this->postTheYear();

        $outcome = $this->whileTheCloseIsOpen(function (): string {
            return $this->onTheOtherConnection(function (): string {
                try {
                    app(YearEndCloser::class)->close(2025, $this->admin);

                    return 'closed';
                } catch (HttpResponseException $e) {
                    return (string) $e->getResponse()->getStatusCode();
                }
            });
        });

        $this->assertSame('409', $outcome);
        $this->assertSame(3, AccountingJournal::query()->where('source', 'closing')->count());
    }

    /**
     * A reopen of 2025 and a close of 2026, which locks 2025's row through
     * its `closed_year` entry. The reopen used to lock its row by id first, so
     * the close could take the `closed_year` entry and wait for the row while
     * the reopen, clearing `closed_year`, waited for that entry: a deadlock.
     * Now the reopen locks through `closed_year` first, the close waits on the
     * entry holding nothing, and the reopen finishes.
     */
    public function test_a_close_of_the_next_year_reaching_for_the_row_a_reopen_holds_queues_behind_it(): void
    {
        $this->postTheYear();
        $close = $this->closed();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 3');

        $outcome = null;
        $default = DB::getDefaultConnection();
        $other = DB::connection(self::OTHER_CONNECTION);

        DB::listen(function (QueryExecuted $query) use (&$outcome, $default, $other): void {
            $sql = strtolower($query->sql);

            if ($outcome !== null
                || $query->connectionName !== $default
                || DB::connection($default)->transactionLevel() === 0
                || ! str_contains($sql, 'from `accounting_year_closes`')
                || ! str_contains($sql, 'for update')) {
                return;
            }

            // What a close of 2026 does with 2025's row (previousYearMustBeClosedFirst()).
            $other->beginTransaction();

            try {
                AccountingYearClose::on(self::OTHER_CONNECTION)->where('closed_year', 2025)->lockForUpdate()->first();
                $outcome = 'locked';
            } catch (PDOException $e) {
                $outcome = str_contains($e->getMessage(), 'Lock wait timeout') ? 'waited' : 'failed: '.$e->getMessage();
            }
        });

        try {
            $response = $this->reopenYear($close['id']);
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }

            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame('waited', $outcome);
        $response->assertOk();
        $this->assertSame('reopened', AccountingYearClose::query()->findOrFail($close['id'])->status);
    }

    /**
     * Closes 2025 through the API, running `$race` on the other connection
     * the moment the close has inserted its row (its period locks are held
     * by then).
     *
     * @param  callable(): string  $race
     */
    private function whileTheCloseIsOpen(callable $race): ?string
    {
        $outcome = null;
        $default = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $query) use (&$outcome, $default, $race): void {
            if ($outcome !== null
                || $query->connectionName !== $default
                || DB::connection($default)->transactionLevel() === 0
                || ! str_starts_with(strtolower($query->sql), 'insert into `accounting_year_closes`')) {
                return;
            }

            $outcome = $race();
        });

        $this->closeYear()->assertCreated();
        $this->assertNotNull($outcome, 'the race never ran while the close was open, so this test proved nothing');

        return $outcome;
    }

    private function postOnTheOtherConnection(string $date): string
    {
        return $this->onTheOtherConnection(function () use ($date): string {
            try {
                app(JournalPoster::class)->postImmediately([
                    'date' => $date,
                    'source' => 'manual',
                    'description' => 'Racing entry',
                    'branch_id' => $this->branch->id,
                ], [
                    ['account_id' => $this->account('1010'), 'debit' => 10000, 'credit' => 0],
                    ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 10000],
                ], $this->admin->id);

                return 'posted';
            } catch (ValidationException) {
                return 'refused';
            } catch (PDOException $e) {
                // A QueryException, or the DeadlockException a nested
                // transaction turns a lock wait timeout into.
                return str_contains($e->getMessage(), 'Lock wait timeout') ? 'waited' : 'failed: '.$e->getMessage();
            }
        });
    }

    /**
     * @param  callable(): string  $callback
     */
    private function onTheOtherConnection(callable $callback): string
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::OTHER_CONNECTION);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
