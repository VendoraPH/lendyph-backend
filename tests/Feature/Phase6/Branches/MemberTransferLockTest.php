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
 * transfer can't slip past the transfer's open-records check.
 *
 * The transfer reads the member under a row lock and reads their records
 * with locking reads, so it waits for a create already writing a loan for
 * the member and then sees it. Without the lock, the transfer's plain read
 * doesn't see the uncommitted loan, and the member moves away from a loan
 * application left in the old branch.
 *
 * Two processes, not two connections in one (see AllowanceLockOrderTest):
 * the loan create runs in tests/Fixtures/member-loan-opener.php, signals
 * once its loan row is written, and holds its transaction open while this
 * process runs the transfer.
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
        $opener = $this->startTheLoanOpener($member->id, $product->id, $marker);

        try {
            $this->waitUntilItsLoanIsWritten($opener, $marker);

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

    private function startTheLoanOpener(int $memberId, int $productId, string $marker): Process
    {
        $database = config('database.connections.'.DB::getDefaultConnection());

        $process = new Process(
            [PHP_BINARY, base_path('tests/Fixtures/member-loan-opener.php'), (string) $memberId, (string) $productId, (string) $this->admin->id, $marker, (string) self::PAUSE],
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

    private function waitUntilItsLoanIsWritten(Process $opener, string $marker): void
    {
        $deadline = microtime(true) + 30;

        while (! file_exists($marker)) {
            if (! $opener->isRunning() || microtime(true) > $deadline) {
                $this->fail('The loan create never wrote its loan: '.$opener->getOutput().$opener->getErrorOutput());
            }

            usleep(20_000);
        }
    }
}
