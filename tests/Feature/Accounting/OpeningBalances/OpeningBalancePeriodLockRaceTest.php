<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;
use App\Models\AccountingPeriod;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * An opening balance and a year-end close or reopen, on two real connections.
 *
 * A close or a reopen locks its year's months FOR UPDATE first and then
 * inserts journals. The finalise, the save and the CLI used to take their
 * `opening_balance` journal lock (a gap lock while none is posted) before the
 * shared lock on the months after `as_of`, so each held the gap the close's
 * inserts need while it waited for the months the close held: a deadlock. Now
 * each takes the months right after the draft lock (the 3050 lock for the
 * CLI), before any opening-journal lock.
 *
 * Each test lets the opening balance reach its first opening-journal lock and
 * then, on the other connection, locks the later months as a close or reopen
 * does first. That lock must wait: the opening balance already holds the
 * months, so the close queues behind it instead of holding them while it
 * waits. In the old order the close got the months, and the opening balance
 * then timed out waiting for them.
 */
class OpeningBalancePeriodLockRaceTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    /**
     * Opted out of the per-test transaction: the second connection can only
     * see rows the first one has committed.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'opening_balance_race';

    /** The months after AS_OF, which a close or reopen of 2026 locks. */
    private const LATER_MONTHS = ['2026-09', '2026-10', '2026-11', '2026-12'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();

        foreach (['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'] as $month) {
            AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse($month)) + ['status' => 'open']);
        }

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
        // Short, so the old order fails in seconds rather than hanging.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
    }

    protected function tearDown(): void
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    public function test_a_close_reaching_for_the_months_waits_for_a_finalise_that_holds_the_opening_journal_lock(): void
    {
        $version = $this->saveBalancedDraft();

        $outcome = $this->whileItHoldsTheOpeningJournalLock(fn () => $this->finalise($version)->assertOk());

        $this->assertSame('waited', $outcome);
        $this->assertSame('finalised', AccountingOpeningBalance::query()->sole()->status);
        $this->assertSame(1, AccountingJournal::query()->where('source', 'opening_balance')->count());
    }

    public function test_a_close_reaching_for_the_months_waits_for_a_save_that_holds_the_opening_journal_lock(): void
    {
        $outcome = $this->whileItHoldsTheOpeningJournalLock(fn () => $this->save($this->balancedLines())->assertOk());

        $this->assertSame('waited', $outcome);
        $this->assertSame(4, AccountingOpeningBalance::query()->sole()->lines()->count());
    }

    public function test_a_close_reaching_for_the_months_waits_for_the_cli_that_holds_the_opening_journal_lock(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openbal').'.csv';
        file_put_contents($path, "1010,10000.00,\n2300,,1000.00\n");

        try {
            $outcome = $this->whileItHoldsTheOpeningJournalLock(fn () => $this->artisan('accounting:opening-balances', [
                '--file' => $path,
                '--as-of' => self::AS_OF,
                '--force' => true,
            ])->assertSuccessful()->run());
        } finally {
            unlink($path);
        }

        $this->assertSame('waited', $outcome);
        $this->assertSame(1, AccountingJournal::query()->where('source', 'opening_balance')->count());
    }

    /**
     * Runs `$write` and, the moment it has taken its first lock on the
     * `opening_balance` journals, locks the later months on the other
     * connection, as a close or reopen of 2026 does first, keeping that
     * transaction open until `$write` is done. Answers whether that lock
     * waited.
     */
    private function whileItHoldsTheOpeningJournalLock(Closure $write): ?string
    {
        $outcome = null;
        $default = DB::getDefaultConnection();
        $other = DB::connection(self::OTHER_CONNECTION);

        DB::listen(function (QueryExecuted $query) use (&$outcome, $default, $other): void {
            $sql = strtolower($query->sql);

            if ($outcome !== null
                || $query->connectionName !== $default
                || DB::connection($default)->transactionLevel() === 0
                || ! str_contains($sql, 'from `accounting_journals`')
                || ! (str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode'))
                || ! in_array('opening_balance', $query->bindings, true)) {
                return;
            }

            $other->beginTransaction();

            try {
                AccountingPeriod::on(self::OTHER_CONNECTION)
                    ->whereIn('code', self::LATER_MONTHS)
                    ->orderBy('code')
                    ->lockForUpdate()
                    ->get();
                $outcome = 'locked';
            } catch (PDOException $e) {
                $outcome = str_contains($e->getMessage(), 'Lock wait timeout') ? 'waited' : 'failed: '.$e->getMessage();
            }
        });

        try {
            $write();
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
        }

        $this->assertNotNull($outcome, 'the race never ran while the opening balance held its lock, so this test proved nothing');

        return $outcome;
    }
}
