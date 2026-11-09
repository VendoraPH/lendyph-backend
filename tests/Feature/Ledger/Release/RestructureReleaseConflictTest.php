<?php

namespace Tests\Feature\Ledger\Release;

use App\Models\Loan;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Restructure releases colliding with other writes, on two real
 * connections: a release waiting on the source loan's row or on the
 * allowance lock answers 409, never 500, and leaves no half-posted journal;
 * and the source is locked before the release first reads it, so a payment
 * that lands before the lock is seen by the split and J2.
 */
class RestructureReleaseConflictTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    /**
     * Opted out of the per-test transaction: the other connection can only
     * see rows this one has committed, and inside one the release's own
     * transaction is a savepoint, so a leak could not be told from a rollback.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'restructure_release_race';

    private const CONFLICT = 'Another change to this loan was saved at the same time. Reload and try again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');

        parent::tearDown();
    }

    public function test_a_release_waiting_on_the_source_loan_answers_409_and_writes_nothing(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $before = $this->releaseState();

        // Another request (a payment, a second restructure release) holds the source.
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('loans')->where('id', $source->id)->lockForUpdate()->first();

        try {
            $this->patchJson("/api/loans/{$new->id}/release")
                ->assertStatus(409)
                ->assertExactJson(['message' => self::CONFLICT]);
        } finally {
            $other->rollBack();
        }

        $this->assertSame($before, $this->releaseState());
        $this->assertContains($source->fresh()->status, ['released', 'ongoing']);
    }

    public function test_a_write_off_waiting_on_the_allowance_lock_answers_409_and_posts_nothing(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $before = $this->releaseState();

        // Another write-off or allowance run holds the allowance account.
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('accounting_accounts')->where('id', $this->account('1200'))->lockForUpdate()->first();

        try {
            $this->patchJson("/api/loans/{$new->id}/release")
                ->assertStatus(409)
                ->assertExactJson(['message' => self::CONFLICT]);
        } finally {
            $other->rollBack();
        }

        $this->assertSame($before, $this->releaseState());
        $this->assertNull($this->journalFor($new, 'loan_release'));
    }

    public function test_a_deadlock_on_the_write_off_leaves_no_half_posted_restructure(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $before = $this->releaseState();
        $fired = false;

        // J1 is written first; the deadlock hits J2's INSERT after it.
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired): void {
            if ($fired || ! str_starts_with($query, 'insert into `accounting_journals`') || ! in_array('credit_loss', $bindings, true)) {
                return;
            }

            $fired = true;
            $errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 '.$errorInfo[2]);
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '40001');

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertStatus(409)
            ->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($fired, 'J2 was never written, so this test proved nothing');
        $this->assertSame($before, $this->releaseState());
        $this->assertNull($this->journalFor($new, 'loan_release'));
    }

    public function test_a_payment_that_lands_before_the_source_lock_is_seen_and_refuses_the_release(): void
    {
        $source = $this->createReleasedLoan();
        // Approved with ₱70,800.00 owed: ₱10,000.00 principal would be written off.
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $paid = null;

        // Right before the release locks the loan rows, inside its transaction,
        // another request pays period 1 (₱10,000.00 principal, ₱1,800.00
        // interest) and commits: ₱59,000.00 is now owed, below the ₱70,800.00
        // approved. A plain read before the lock would fix the snapshot
        // without the payment and release on the approved figures.
        DB::connection()->beforeExecuting(function (string $query) use (&$paid, $source): void {
            $sql = strtolower($query);

            if ($paid !== null
                || DB::connection()->transactionLevel() === 0
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'from `loans`')
                || ! str_contains($sql, 'for update')) {
                return;
            }

            $paid = $this->payOnTheOtherConnection($source->id, 11800);
        });

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => 'The source loan was paid down or adjusted after this restructure was approved. Send it back for approval so the figures can be taken again.']);

        $this->assertNotNull($paid, 'the payment never landed inside the release, so this test proved nothing');
        $this->assertSame('posted', Repayment::findOrFail($paid)->status);
        $this->assertSame('approved', $new->fresh()->status);
        $this->assertContains($source->fresh()->status, ['released', 'ongoing']);
        $this->assertSame([null, null, null, null], $this->storedSplit($new));
        $this->assertNull($this->journalFor($new, 'loan_release'));
        $this->assertNull($this->journalFor($source, 'credit_loss'));
    }

    /** Record a payment on the other connection, as another request would, and commit it. */
    private function payOnTheOtherConnection(int $loanId, float $amount): int
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::OTHER_CONNECTION);

        try {
            return app(RepaymentService::class)
                ->processRepayment(Loan::findOrFail($loanId), $amount, now()->toDateString(), $this->admin)
                ->id;
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
