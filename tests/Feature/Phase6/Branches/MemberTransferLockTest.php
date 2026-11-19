<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE4: a loan application opened at the same moment as a member
 * transfer can't slip past the transfer's open-records check, and one opened
 * while a transfer is being written lands in the member's new branch.
 *
 * The transfer reads the member under a row lock and reads their records
 * with locking reads, so it waits for a create already writing a loan for
 * the member and then sees it. Without the lock, the transfer's plain read
 * doesn't see the uncommitted loan, and the member moves away from a loan
 * application left in the old branch.
 *
 * In the other order, the loan create (POST /loans) takes the member's branch
 * lock (MemberLock), which the transfer holds, so it waits for the transfer
 * and then copies the new branch. Without that lock it copied the old branch, and the
 * loan stayed behind in a branch the member had left.
 *
 * Two processes, not two connections in one (see AllowanceLockOrderTest):
 * the other side runs in tests/Fixtures/member-loan-opener.php or
 * member-transferrer.php, signals once its row is written, and holds its
 * transaction open while this process runs its half.
 */
class MemberTransferLockTest extends TestCase
{
    use SetupLendyPH;

    /** The other process can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    /** How long the loan create holds its transaction, in microseconds. */
    private const PAUSE = 1_500_000;

    public function test_a_loan_opened_at_the_same_moment_refuses_the_transfer(): void
    {
        $this->seedAndLogin();
        $second = Branch::factory()->create(['name' => 'Second Branch', 'code' => 'SEC']);
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);

        $marker = sys_get_temp_dir().'/member-transfer-lock-'.bin2hex(random_bytes(6));
        $opener = $this->startTheFixture('member-loan-opener.php', [(string) $member->id, (string) $product->id, (string) $this->admin->id, $marker, (string) self::PAUSE]);

        try {
            $this->waitForTheMarker($opener, $marker);

            $response = $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $second->id, 'reason' => 'Moved for work.']);
            $transferEnded = microtime(true);

            $opener->wait();
        } finally {
            @unlink($marker);
            $opener->stop(0);
        }

        $this->assertSame(0, $opener->getExitCode(), 'the loan create failed: '.$opener->getOutput().$opener->getErrorOutput());
        $this->assertMatchesRegularExpression('/^ok \d+ at (\d+\.\d+)$/', $opener->getOutput());
        // The transfer must have waited for the create to commit.
        $committed = (float) explode(' at ', $opener->getOutput())[1];
        $this->assertGreaterThan($committed, $transferEnded, 'the transfer finished before the loan create committed, so it never waited');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => "This member can't be transferred while they have 1 loan application not yet released. Loans, savings, time deposits, portal requests and GCash Cash Ins stay in the branch where they were opened, so close or settle them first."]);

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
        $this->assertSame($this->branch->id, (int) Loan::query()->where('borrower_id', $member->id)->value('branch_id'));
    }

    public function test_a_loan_opened_while_a_transfer_is_written_lands_in_the_new_branch(): void
    {
        $this->seedAndLogin();
        $second = Branch::factory()->create(['name' => 'Second Branch', 'code' => 'SEC']);
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);

        // The lock both processes take lives in the database cache, as deployed.
        config(['cache.default' => 'database']);

        $marker = sys_get_temp_dir().'/member-transfer-lock-'.bin2hex(random_bytes(6));
        $transfer = $this->startTheFixture('member-transferrer.php', [(string) $member->id, (string) $second->id, $marker, (string) self::PAUSE]);

        try {
            $this->waitForTheMarker($transfer, $marker);

            $response = $this->postJson('/api/loans', [
                'borrower_id' => $member->id,
                'loan_product_id' => $product->id,
                'principal_amount' => 10000,
                'start_date' => now()->toDateString(),
            ]);
            $createEnded = microtime(true);

            $transfer->wait();
        } finally {
            @unlink($marker);
            $transfer->stop(0);
        }

        $this->assertSame(0, $transfer->getExitCode(), 'the transfer failed: '.$transfer->getOutput().$transfer->getErrorOutput());
        $this->assertMatchesRegularExpression('/^ok at (\d+\.\d+)$/', $transfer->getOutput());
        $committed = (float) explode(' at ', $transfer->getOutput())[1];
        $this->assertGreaterThan($committed, $createEnded, 'the loan create finished before the transfer committed, so they never overlapped');

        $response->assertCreated();
        $this->assertSame($second->id, (int) $member->fresh()->branch_id);
        $this->assertSame($second->id, (int) Loan::query()->findOrFail($response->json('data.id'))->branch_id, 'the loan was written in the branch the member left');
    }

    /** @param  list<string>  $arguments */
    private function startTheFixture(string $fixture, array $arguments): Process
    {
        $database = config('database.connections.'.DB::getDefaultConnection());

        $process = new Process(
            [PHP_BINARY, base_path("tests/Fixtures/{$fixture}"), ...$arguments],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => DB::getDefaultConnection(),
                'DB_HOST' => (string) $database['host'],
                'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => (string) $database['database'],
                'DB_USERNAME' => (string) $database['username'],
                'DB_PASSWORD' => (string) $database['password'],
                'CACHE_STORE' => 'database',
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

    private function waitForTheMarker(Process $other, string $marker): void
    {
        $deadline = microtime(true) + 30;

        while (! file_exists($marker)) {
            if (! $other->isRunning() || microtime(true) > $deadline) {
                $this->fail('The other process never wrote its row: '.$other->getOutput().$other->getErrorOutput());
            }

            usleep(20_000);
        }
    }
}
