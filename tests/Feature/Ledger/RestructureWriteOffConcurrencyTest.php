<?php

namespace Tests\Feature\Ledger;

use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\JournalPoster;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The restructure write-off (J2) against an allowance another request moved,
 * on two real connections, as PaymentVoidRaceTest does.
 *
 * The app runs MySQL REPEATABLE READ: a plain SELECT answers from the snapshot
 * the transaction's FIRST plain read took, and locking a row does not move
 * that snapshot (CollateralPledgeGuard explains the trap). A release reads
 * plenty before it reaches J2, so the allowance balance J2 takes from has to
 * be a locking read, which InnoDB answers from the latest committed rows.
 * Read from the snapshot instead, two restructures writing off ₱2,000.00 each
 * against a ₱2,000.00 allowance would both take it.
 *
 * Here the provision committed by the other connection lands after this
 * transaction's first read and before J2, which is exactly the window.
 */
class RestructureWriteOffConcurrencyTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /**
     * Opted out of the per-test transaction: the other connection can only
     * see rows this one has committed, and the refusal below is about there
     * being no transaction at all.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'restructure_write_off_race';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    public function test_the_write_off_takes_an_allowance_provided_after_the_release_first_read(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureWritingOff($this->createReleasedLoan(), $source, 300_000);
        $this->seedChartOfAccounts();
        // ₱2,000.00 provided for before the release starts.
        $this->postSimpleJournal('5140', '1200', 200_000);
        $run = ShareCapitalLedger::factory()->create();

        $writeOff = DB::transaction(function () use ($source, $new, $run): AccountingJournal {
            // The release's own reads come first, and fix this transaction's snapshot.
            $this->assertSame(200_000, $this->allowanceAsThisSnapshotSeesIt());

            // Another request provides ₱1,000.00 more, and commits.
            $this->onTheOtherConnection(fn () => app(AutomaticPoster::class)
                ->allowanceAdjustment($run, 100_000, null, now()->toDateString(), $this->admin->id));

            $this->assertSame(200_000, $this->allowanceAsThisSnapshotSeesIt(), 'the snapshot moved, so this test proved nothing');

            return app(AutomaticPoster::class)->restructureRelease($new, $source, $this->admin->id, 'cash')['write_off'];
        });

        // ₱3,000.00 written off, all of it against the ₱3,000.00 now provided.
        $this->assertSame(300_000, $this->sumOn($writeOff, '1200', 'debit'));
        $this->assertSame(0, $this->sumOn($writeOff, '5140', 'debit'));
        $this->assertSame(300_000, $this->sumOn($writeOff, '1110', 'credit'));
    }

    /**
     * While J2's transaction is open, a bookkeeper's post of an allowance
     * draft on the other connection waits for it (and, with a one-second
     * lock wait here, gives up) instead of slipping in. Once J2 commits, the
     * same post goes through.
     *
     * It waits on the allowance ACCOUNT row, before any journal or line lock:
     * the lock order. Before that order (JournalPoster at d0d7a53) it waited
     * too, so the outcome alone proved nothing: it waited on its journal row,
     * which J2's locking read of the allowance lines' journals had taken.
     * That is a lock taken out of order, the half of the deadlock
     * AllowanceLockOrderTest races. Run against that JournalPoster, this test
     * fails on the query it waited on.
     */
    public function test_a_bookkeeper_post_on_the_allowance_waits_for_the_write_off_and_never_slips_in(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureWritingOff($this->createReleasedLoan(), $source, 300_000);
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('5140', '1200', 300_000);
        // A ₱1,000.00 release of the allowance, drafted earlier.
        $draft = $this->draftJournal([
            ['account_id' => $this->account('1200'), 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $this->account('5140'), 'debit' => 0, 'credit' => 100_000],
        ]);
        $outcome = null;

        $writeOff = DB::transaction(function () use ($source, $new, $draft, &$outcome): AccountingJournal {
            $writeOff = app(AutomaticPoster::class)->restructureRelease($new, $source, $this->admin->id, 'cash')['write_off'];

            $outcome = $this->onTheOtherConnection(function () use ($draft): string {
                try {
                    app(JournalPoster::class)->post(AccountingJournal::query()->findOrFail($draft->id), $this->admin->id);

                    return 'posted';
                } catch (QueryException $e) {
                    if (($e->errorInfo[1] ?? null) === 1205) {
                        $sql = strtolower($e->getSql());

                        return str_starts_with($sql, 'select * from `accounting_accounts`') && str_contains($sql, 'for update')
                            ? 'waited on the allowance account'
                            : 'waited on: '.$sql;
                    }

                    throw $e;
                }
            });

            return $writeOff;
        });

        $this->assertSame('waited on the allowance account', $outcome);
        $this->assertSame(300_000, $this->sumOn($writeOff, '1200', 'debit'));
        $this->assertSame(0, $this->sumOn($writeOff, '5140', 'debit'));
        $this->assertSame('draft', $draft->fresh()->status);

        $this->assertSame('posted', app(JournalPoster::class)->post($draft->fresh(), $this->admin->id)->status);
    }

    /**
     * Without the release's transaction the allowance lock is released at
     * once, and J1 could commit without J2.
     */
    public function test_a_restructure_release_outside_a_transaction_is_refused(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureWritingOff($this->createReleasedLoan(), $source, 300_000);
        $this->seedChartOfAccounts();
        $this->assertSame(0, DB::transactionLevel());

        try {
            app(AutomaticPoster::class)->restructureRelease($new, $source, $this->admin->id, 'cash');
            $this->fail('A restructure release outside a transaction was posted.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('inside the release\'s transaction', $e->getMessage());
        }

        $this->assertSame(0, AccountingJournal::count());
    }

    /** Make `$new` a restructure of `$source` that carries its principal and writes `$writtenOff` off. */
    private function restructureWritingOff(Loan $new, Loan $source, int $writtenOff): Loan
    {
        $new->update([
            'source_loan_id' => $source->id,
            'restructure_carried_principal' => $new->principal_amount,
            'restructure_capitalised_interest' => '0.00',
            'restructure_capitalised_penalty' => '0.00',
            'restructure_written_off_principal' => sprintf('%d.%02d', intdiv($writtenOff, 100), $writtenOff % 100),
        ]);

        return $new->fresh();
    }

    /** The allowance's credit balance by a plain read, as this transaction's snapshot has it. */
    private function allowanceAsThisSnapshotSeesIt(): int
    {
        return (int) DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->where('j.status', 'posted')
            ->where('l.accounting_account_id', $this->account('1200'))
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) AS balance')
            ->value('balance');
    }

    private function sumOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    /** Run `$work` on the other connection, as another request would. */
    private function onTheOtherConnection(callable $work): mixed
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::OTHER_CONNECTION);

        try {
            return $work();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
