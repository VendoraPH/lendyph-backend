<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingJournal;
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
