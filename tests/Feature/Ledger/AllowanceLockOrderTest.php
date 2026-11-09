<?php

namespace Tests\Feature\Ledger;

use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Services\Accounting\AutomaticPoster;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A bookkeeper's write on the allowance account (posting a draft, or
 * reversing a journal) and a restructure write-off (J2), truly at once.
 *
 * J2 locks the allowance account row, then every allowance line, then their
 * journals (AutomaticPoster::allowanceBalance()). A bookkeeper write that
 * locked its journal first and then reached for its allowance lines waited on
 * J2 while J2 waited on its journal: a deadlock, and a 500 for whoever InnoDB
 * rolled back. So every writer of allowance lines takes the account row first
 * (JournalPoster::lockTheAllowanceIfTouched()), and the two queue instead.
 *
 * Two processes, not two connections in one: one PHP thread blocks on the
 * first lock it waits for, so it can never be the second half of a deadlock.
 * The bookkeeper's write runs in tests/Fixtures/allowance-writer.php. It
 * signals once it holds its journal and pauses there, and J2 starts in that
 * window, the exact interleaving that deadlocked.
 */
class AllowanceLockOrderTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /** The other process can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    /** How long the bookkeeper's write holds its journal, in microseconds. */
    private const PAUSE = 1_500_000;

    /** @return array<string, array{0: string}> */
    public static function bookkeeperWrites(): array
    {
        return [
            'posting a draft with an allowance line' => ['post'],
            'reversing a journal with an allowance line' => ['reverse'],
        ];
    }

    #[DataProvider('bookkeeperWrites')]
    public function test_a_bookkeeper_write_on_the_allowance_and_a_restructure_write_off_at_once_both_finish(string $action): void
    {
        $this->seedAndLogin();
        $source = $this->createReleasedLoan();
        $new = $this->restructureWritingOff($this->createReleasedLoan(), $source, 300_000);
        $this->seedChartOfAccounts();
        // ₱5,000.00 provided, so ₱3,000.00 is written off against it in either order.
        $this->postSimpleJournal('5140', '1200', 500_000);
        $lines = [
            ['account_id' => $this->account('5140'), 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $this->account('1200'), 'debit' => 0, 'credit' => 100_000],
        ];
        $journal = $action === 'post' ? $this->draftJournal($lines) : $this->postJournal($lines);

        $marker = sys_get_temp_dir().'/allowance-lock-order-'.bin2hex(random_bytes(6));
        $bookkeeper = $this->startTheBookkeeper($action, $journal->id, $marker);

        try {
            $this->waitUntilItHoldsItsJournal($bookkeeper, $marker);

            $writeOff = DB::transaction(fn (): ?AccountingJournal => app(AutomaticPoster::class)
                ->restructureRelease($new, $source, $this->admin->id, 'cash')['write_off']);

            $bookkeeper->wait();
        } finally {
            @unlink($marker);
            $bookkeeper->stop(0);
        }

        $this->assertSame(0, $bookkeeper->getExitCode(), 'the bookkeeper\'s write failed: '.$bookkeeper->getOutput().$bookkeeper->getErrorOutput());
        $this->assertStringStartsWith('ok JE-', $bookkeeper->getOutput());
        $this->assertSame($action === 'post' ? 'posted' : 'reversed', $journal->fresh()->status);

        $this->assertSame(300_000, $this->sumOn($writeOff, '1200', 'debit'));
        $this->assertSame(0, $this->sumOn($writeOff, '5140', 'debit'));
        $this->assertSame(300_000, $this->sumOn($writeOff, '1110', 'credit'));
    }

    private function startTheBookkeeper(string $action, int $journalId, string $marker): Process
    {
        $database = config('database.connections.'.DB::getDefaultConnection());

        $process = new Process(
            [PHP_BINARY, base_path('tests/Fixtures/allowance-writer.php'), $action, (string) $journalId, $marker, (string) self::PAUSE],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => DB::getDefaultConnection(),
                'DB_HOST' => (string) $database['host'],
                'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => (string) $database['database'],
                'DB_USERNAME' => (string) $database['username'],
                'DB_PASSWORD' => (string) $database['password'],
                // Not a test worker: it uses the database it is given.
                'TEST_TOKEN' => false,
                'LARAVEL_PARALLEL_TESTING' => false,
            ],
            null,
            60,
        );
        $process->start();

        return $process;
    }

    private function waitUntilItHoldsItsJournal(Process $bookkeeper, string $marker): void
    {
        $deadline = microtime(true) + 30;

        while (! file_exists($marker)) {
            if (! $bookkeeper->isRunning() || microtime(true) > $deadline) {
                $this->fail('The bookkeeper\'s write never took its journal lock: '.$bookkeeper->getOutput().$bookkeeper->getErrorOutput());
            }

            usleep(20_000);
        }
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

    private function sumOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }
}
