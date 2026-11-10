<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Two withdrawals of ₱800.00 from ₱1,000.00, truly at once (design §8): the
 * second waits on the savings account row lock the first holds, then reads
 * the committed balance and is refused (422). The account never goes below
 * the available balance, and exactly one withdrawal posts.
 *
 * Two processes, as AllowanceRunLockOrderTest: the other side runs in
 * Fixtures/withdrawal-race.php, signals once it holds the account row and
 * pauses there, and this side sends its withdrawal in that window.
 */
class WithdrawalRaceTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    /** The other process can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    /** How long the other process holds the account row, in microseconds. */
    private const PAUSE = 1_500_000;

    public function test_two_racing_withdrawals_cannot_overdraw(): void
    {
        $this->setUpSavings(pinToday: false);
        $account = $this->account(['opened_on' => now()->toDateString(), 'last_member_activity_on' => now()->toDateString()]);
        $this->fund($account, 100_000, now()->toDateString());

        $marker = sys_get_temp_dir().'/savings-withdrawal-race-'.bin2hex(random_bytes(6));
        $other = $this->startTheOtherSide([$marker, (string) self::PAUSE, (string) $account->id, (string) $this->admin->id, '800.00']);

        try {
            $this->waitUntilItHoldsTheAccount($other, $marker);
            $began = microtime(true);

            $response = $this->money("/api/savings/accounts/{$account->id}/withdrawals", [
                'amount' => '800.00', 'date' => now()->toDateString(), 'payment_method' => 'cash',
            ]);

            $other->wait();
        } finally {
            @unlink($marker);
            $other->stop(0);
        }

        $this->assertSame(0, $other->getExitCode(), 'the other side failed: '.$other->getOutput().$other->getErrorOutput());
        $finished = (float) explode(' at ', $other->getOutput())[1];
        $this->assertGreaterThan($began, $finished, 'the other side finished before this one began, so they never overlapped');

        $response->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(1, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->assertSame('200.00', (string) SavingsAccount::query()->findOrFail($account->id)->ledger_balance);
    }

    /** @param  list<string>  $arguments */
    private function startTheOtherSide(array $arguments): Process
    {
        $database = config('database.connections.'.DB::getDefaultConnection());

        $process = new Process(
            [PHP_BINARY, base_path('tests/Feature/Savings/Accounts/Fixtures/withdrawal-race.php'), ...$arguments],
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

    private function waitUntilItHoldsTheAccount(Process $other, string $marker): void
    {
        $deadline = microtime(true) + 30;

        while (! file_exists($marker)) {
            if (! $other->isRunning() || microtime(true) > $deadline) {
                $this->fail('The other side never took the account lock: '.$other->getOutput().$other->getErrorOutput());
            }

            usleep(20_000);
        }
    }
}
