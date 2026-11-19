<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Models\AmlaOrder;
use App\Models\AmlaOrderHold;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Services\Amla\Screening\OrderCredits;
use App\Services\Amla\Screening\Orders;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The release race (AMLA-B5 re-review): a payout into an account the order
 * already holds commits while the release is under way. The release must
 * still lift the hold that payout linked, read after its locks, never leave a
 * hold on a released order that nothing can lift.
 *
 * Two processes, as AllowanceLockOrderTest does: the release runs in its own
 * (tests/Fixtures/amla-order-releaser.php) and pauses right after its first
 * plain read of the order's links; this test commits the payout's hold in
 * that window, then lets the release go on. The data must be committed for
 * the other process to see it, so this test isn't wrapped in a transaction.
 */
class AmlaOrderReleaseRaceTest extends TestCase
{
    use ScreeningTestHelpers, SetupLendyPH;

    protected bool $wrapsEachTestInTransaction = false;

    public function test_a_payout_committed_mid_release_is_lifted_with_the_rest(): void
    {
        $this->seedAndLogin();
        $member = $this->member();
        $account = SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $member->branch_id, 'ledger_balance' => '80000.00']);
        $asker = $this->sanctionsOfficer();
        $checker = $this->sanctionsOfficer();
        $orders = app(Orders::class);

        $order = $orders->place($asker, [
            'reference' => 'AMLC Freeze Order 77', 'authority' => 'amlc', 'received_on' => now()->toDateString(),
            'borrower_id' => $member->id, 'required_action' => 'Freeze.',
            'holds' => [['account_id' => $account->id, 'amount' => '50000.00']],
        ]);
        $orders->requestRelease($asker, $order->id, ['reason' => 'Lifted.', 'release_reference' => 'R-77']);

        $marker = sys_get_temp_dir().'/amla-release-race-'.bin2hex(random_bytes(6));
        $go = $marker.'-go';
        $releaser = $this->startTheRelease($order->id, $checker->id, $marker, $go);

        try {
            $this->waitFor($releaser, $marker);

            DB::transaction(fn () => app(OrderCredits::class)->holdCredit($member->id, $account->id, 1_000_000, null));
            touch($go);

            $releaser->wait();
        } finally {
            @unlink($marker);
            @unlink($go);
            $releaser->stop(0);
        }

        $this->assertSame(0, $releaser->getExitCode(), 'the release failed: '.$releaser->getOutput().$releaser->getErrorOutput());
        $this->assertSame('released', $order->fresh()->status);

        $links = AmlaOrderHold::query()->where('order_id', $order->id)->pluck('savings_hold_id');
        $this->assertCount(2, $links, 'the payout linked its hold mid-release');
        $this->assertSame(['released'], SavingsHold::query()->whereIn('id', $links)->distinct()->pluck('status')->all(), 'no hold is left on a released order');
        $this->assertSame(0, AmlaOrder::query()->where('status', 'active')->count());
    }

    private function startTheRelease(int $orderId, int $checkerId, string $marker, string $go): Process
    {
        $database = config('database.connections.'.DB::getDefaultConnection());

        $process = new Process(
            [PHP_BINARY, base_path('tests/Fixtures/amla-order-releaser.php'), (string) $orderId, (string) $checkerId, $marker, $go],
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

    private function waitFor(Process $releaser, string $marker): void
    {
        $deadline = microtime(true) + 30;

        while (! file_exists($marker)) {
            if (! $releaser->isRunning() || microtime(true) > $deadline) {
                $this->fail('The release never reached its first read: '.$releaser->getOutput().$releaser->getErrorOutput());
            }

            usleep(20_000);
        }
    }
}
