<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;
use App\Models\Loan;
use App\Services\Accounting\AutomaticPoster;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * An opening balance with a 1200 line and a restructure write-off (J2), at
 * once. J2 locks the allowance account row first and then inserts its
 * journal; a finalise that took the 3050 row and the `source` gap before
 * reaching for the allowance waited on J2 while J2's insert waited on that
 * gap. So the finalise takes the allowance row first, as every writer of
 * allowance lines does, and the two queue.
 *
 * Two processes, as in AllowanceLockOrderTest: one PHP thread can never be
 * both halves of a deadlock. The finalise runs in Fixtures/opening-balance-
 * finaliser.php, pauses holding its locks, and J2 starts in that window.
 */
class OpeningBalanceLockOrderTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    /** The other process can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    /** How long the finalise holds its locks, in microseconds. */
    private const PAUSE = 1_500_000;

    public function test_a_finalise_with_an_allowance_line_and_a_restructure_write_off_at_once_both_finish(): void
    {
        $this->seedAndLogin();
        $source = $this->createReleasedLoan();
        $new = $this->restructureWritingOff($this->createReleasedLoan(), $source, 300_000);
        $this->seedChartOfAccounts();
        // ₱5,000.00 provided, so ₱3,000.00 is written off against it in either order.
        $this->postSimpleJournal('5140', '1200', 500_000);

        $version = (int) $this->save([
            ['account_id' => $this->account('1110'), 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => $this->account('1200'), 'debit' => 0, 'credit' => 100_000],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 900_000],
        ])->assertOk()->json('data.edit_version');
        $draft = AccountingOpeningBalance::query()->sole();

        $marker = sys_get_temp_dir().'/opening-balance-lock-order-'.bin2hex(random_bytes(6));
        $finaliser = $this->startTheFinaliser($draft->id, $version, $marker);

        try {
            $this->waitUntilItHoldsItsLocks($finaliser, $marker);
            $writeOffBegan = microtime(true);

            $writeOff = DB::transaction(fn (): ?AccountingJournal => app(AutomaticPoster::class)
                ->restructureRelease($new, $source, $this->admin->id, 'cash')['write_off']);

            $finaliser->wait();
        } finally {
            @unlink($marker);
            $finaliser->stop(0);
        }

        $this->assertSame(0, $finaliser->getExitCode(), 'the finalise failed: '.$finaliser->getOutput().$finaliser->getErrorOutput());
        $this->assertMatchesRegularExpression('/^ok JE-\d+ at (\d+\.\d+)$/', $finaliser->getOutput());
        $finished = (float) explode(' at ', $finaliser->getOutput())[1];
        $this->assertGreaterThan($writeOffBegan, $finished, 'the finalise finished before J2 began, so they never overlapped');

        $this->assertSame('finalised', $draft->fresh()->status);
        $this->assertSame(1, AccountingJournal::query()->where('source', 'opening_balance')->count());
        $this->assertSame(300_000, (int) $writeOff->lines()->where('accounting_account_id', $this->account('1200'))->sum('debit'));
    }

    private function startTheFinaliser(int $draftId, int $version, string $marker): Process
    {
        $database = config('database.connections.'.DB::getDefaultConnection());

        $process = new Process(
            [PHP_BINARY, __DIR__.'/Fixtures/opening-balance-finaliser.php', (string) $draftId, (string) $version, (string) $this->admin->id, $marker, (string) self::PAUSE],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => DB::getDefaultConnection(),
                'DB_HOST' => (string) $database['host'],
                'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => (string) $database['database'],
                'DB_USERNAME' => (string) $database['username'],
                'DB_PASSWORD' => (string) $database['password'],
                'TEST_TOKEN' => false,
                'LARAVEL_PARALLEL_TESTING' => false,
            ],
            null,
            60,
        );
        $process->start();

        return $process;
    }

    private function waitUntilItHoldsItsLocks(Process $finaliser, string $marker): void
    {
        $deadline = microtime(true) + 30;

        while (! file_exists($marker)) {
            if (! $finaliser->isRunning() || microtime(true) > $deadline) {
                $this->fail('The finalise never reached its locking check: '.$finaliser->getOutput().$finaliser->getErrorOutput());
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
}
