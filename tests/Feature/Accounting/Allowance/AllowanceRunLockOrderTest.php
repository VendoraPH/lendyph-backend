<?php

namespace Tests\Feature\Accounting\Allowance;

use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Services\Accounting\AutomaticPoster;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * An allowance run and a restructure write-off (J2), truly at once.
 *
 * Both lock the allowance account row before any journal or line lock, and
 * the run never locks a loan row (its figures come from plain reads). So the
 * two queue on the account row and neither deadlocks, in either order. Each
 * reads the other's committed 1200 lines through locking reads: under
 * REPEATABLE READ a plain read would answer from a snapshot taken before the
 * lock, and miss them.
 *
 * Two processes, not two connections in one: one PHP thread blocks on the
 * first lock it waits for, so it can never be the second half of a deadlock.
 * The other side runs in Fixtures/allowance-race.php. It signals once it holds
 * the allowance account row and pauses there, and this side starts in that
 * window.
 */
class AllowanceRunLockOrderTest extends TestCase
{
    use AllowanceTestHelpers, PostsJournals, SetupLendyPH;

    /** The other process can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    /** How long the other process holds the allowance account row, in microseconds. */
    private const PAUSE = 1_500_000;

    private Loan $source;

    private Loan $new;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->grantTheAllowancePermission();

        $this->source = $this->createReleasedLoan();
        $this->new = $this->restructureWritingOff($this->createReleasedLoan(), $this->source, 300_000);
        $this->seedChartOfAccounts();

        // ₱5,000.00 provided in Main. ₱10,000.00 ten days late needs ₱500.00.
        $this->postSimpleJournal('5140', '1200', 500_000);
        $this->period($this->agedLoan(), 10, principal: 10000);
    }

    public function test_a_run_while_a_write_off_holds_the_allowance_waits_and_sees_its_committed_balance(): void
    {
        $marker = $this->markerPath();
        $writeOff = $this->startTheOtherSide(
            ['write-off', $marker, (string) self::PAUSE, (string) $this->new->id, (string) $this->source->id, (string) $this->admin->id],
        );

        try {
            $this->waitUntilItHoldsTheAllowance($writeOff, $marker);
            $runBegan = microtime(true);

            // As a preview read after J2 would propose it: J2 takes ₱3,000.00 of
            // the ₱5,000.00, so ₱1,500.00 of the ₱2,000.00 left is released.
            $response = $this->postJson('/api/accounting/allowance/postings', [
                'as_of' => $this->today(),
                'expected_adjustment' => -150_000,
            ]);

            $writeOff->wait();
        } finally {
            @unlink($marker);
            $writeOff->stop(0);
        }

        $this->assertTheOtherSideFinishedAfter($writeOff, $runBegan);

        $posting = $response->assertCreated()->json('data.postings.0');
        $this->assertSame([50_000, 200_000, -150_000], [$posting['required'], $posting['previous_balance'], $posting['adjustment']]);

        $j2 = AccountingJournal::query()->where('source', 'credit_loss')->where('postable_type', Loan::class)->sole();
        $this->assertSame(300_000, $this->sumOn($j2, '1200', 'debit'));
        $this->assertSame(50_000, $this->allowanceBalance());
    }

    public function test_a_write_off_while_a_run_holds_the_allowance_waits_and_sees_the_run(): void
    {
        $marker = $this->markerPath();
        $run = $this->startTheOtherSide(
            ['allowance', $marker, (string) self::PAUSE, $this->today(), '-450000', (string) $this->admin->id],
        );

        try {
            $this->waitUntilItHoldsTheAllowance($run, $marker);
            $writeOffBegan = microtime(true);

            $j2 = DB::transaction(function (): ?AccountingJournal {
                // The loan rows first, as LoanService::release() takes them. A run
                // that locked a loan row after the allowance would deadlock here.
                Loan::query()->whereKey([$this->source->id, $this->new->id])->orderBy('id')->lockForUpdate()->get();

                return app(AutomaticPoster::class)->restructureRelease($this->new, $this->source, $this->admin->id, 'cash')['write_off'];
            });

            $run->wait();
        } finally {
            @unlink($marker);
            $run->stop(0);
        }

        $this->assertTheOtherSideFinishedAfter($run, $writeOffBegan);

        // The run released ₱4,500.00, leaving ₱500.00: J2 takes that, and the
        // other ₱2,500.00 goes to expense.
        $this->assertSame(50_000, $this->sumOn($j2, '1200', 'debit'));
        $this->assertSame(250_000, $this->sumOn($j2, '5140', 'debit'));
        $this->assertSame(0, $this->allowanceBalance());
    }

    private function markerPath(): string
    {
        return sys_get_temp_dir().'/allowance-run-lock-order-'.bin2hex(random_bytes(6));
    }

    /** @param  list<string>  $arguments */
    private function startTheOtherSide(array $arguments): Process
    {
        $database = config('database.connections.'.DB::getDefaultConnection());

        $process = new Process(
            [PHP_BINARY, base_path('tests/Feature/Accounting/Allowance/Fixtures/allowance-race.php'), ...$arguments],
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

    private function waitUntilItHoldsTheAllowance(Process $other, string $marker): void
    {
        $deadline = microtime(true) + 30;

        while (! file_exists($marker)) {
            if (! $other->isRunning() || microtime(true) > $deadline) {
                $this->fail('The other side never took the allowance lock: '.$other->getOutput().$other->getErrorOutput());
            }

            usleep(20_000);
        }
    }

    private function assertTheOtherSideFinishedAfter(Process $other, float $began): void
    {
        $this->assertSame(0, $other->getExitCode(), 'the other side failed: '.$other->getOutput().$other->getErrorOutput());
        $this->assertMatchesRegularExpression('/^ok \S+ at (\d+\.\d+)$/', $other->getOutput());
        // On a slow machine the pause could end before this side starts, and
        // the two would never overlap: then this proves nothing, so it fails.
        $finished = (float) explode(' at ', $other->getOutput())[1];
        $this->assertGreaterThan($began, $finished, 'the other side finished before this one began, so they never overlapped');
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

    private function sumOn(?AccountingJournal $journal, string $code, string $side): int
    {
        $this->assertNotNull($journal);

        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    private function allowanceBalance(): int
    {
        return (int) DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('l.accounting_account_id', $this->account('1200'))
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) AS balance')
            ->value('balance');
    }
}
