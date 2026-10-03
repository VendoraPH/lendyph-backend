<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A deadlock or lock wait timeout inside a collateral write is a 409 the client
 * can reload from, never a 500, and the write it interrupted leaves nothing
 * behind.
 *
 * The failure is raised on the LAST statement each write would issue, after
 * every other row it changes has been written, so "nothing behind" is proved by
 * the rollback rather than by the request stopping early.
 */
class CollateralWriteConflictTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction. Inside one, the request's own
     * transaction is a savepoint, and Laravel deliberately does not roll a
     * savepoint back on a deadlock (MySQL has already undone the whole
     * transaction by then), so the rows written before the failure would still
     * be visible and the test could not tell a rollback from a leak.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    private const CONFLICT = 'Another change to this collateral was saved at the same time. Reload and try again.';

    private Borrower $borrower;

    private Collateral $collateral;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->collateral = Collateral::factory()->create(['borrower_id' => $this->borrower->id, 'amount' => 250000]);
        $this->loan = Loan::factory()->create([
            'borrower_id' => $this->borrower->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'purpose' => 'Original purpose',
        ]);
    }

    public function test_an_attach_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $before = $this->state();
        $this->failOnAuditRow('collateral_attached', self::DEADLOCK);

        $this->postJson("/api/loans/{$this->loan->id}/collaterals", [
            'collateral_id' => $this->collateral->id,
            'snapshot_value' => 250000,
        ])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_an_attach_whose_lock_wait_times_out_answers_409_and_writes_nothing(): void
    {
        $before = $this->state();
        $this->failOnAuditRow('collateral_attached', self::LOCK_WAIT_TIMEOUT);

        $this->postJson("/api/loans/{$this->loan->id}/collaterals", [
            'collateral_id' => $this->collateral->id,
            'snapshot_value' => 250000,
        ])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_detach_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $this->pledge($this->loan, $this->collateral);
        $before = $this->state();
        $this->failOnAuditRow('collateral_detached', self::DEADLOCK);

        $this->deleteJson("/api/loans/{$this->loan->id}/collaterals/{$this->collateral->id}")
            ->assertStatus(409)
            ->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_collateral_edit_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $this->pledge($this->loan, $this->collateral);
        $before = $this->state();
        $this->failOnAuditRow('collateral_value_changed', self::DEADLOCK);

        $this->putJson("/api/collaterals/{$this->collateral->id}", ['amount' => 300000, 'detail_value' => 'TCT-CHANGED'])
            ->assertStatus(409)
            ->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_loan_edit_carrying_collaterals_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $dropped = Collateral::factory()->create(['borrower_id' => $this->borrower->id]);
        $this->pledge($this->loan, $dropped);
        $before = $this->state();
        $this->failOnAuditRow('collateral_attached', self::DEADLOCK);

        $this->putJson("/api/loans/{$this->loan->id}", [
            'purpose' => 'Changed purpose',
            'collaterals' => [['collateral_id' => $this->collateral->id, 'snapshot_value' => 1000]],
        ])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_restructure_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        // Restructure creation writes pledges onto the new loan, and the lock
        // it takes for the application number (the newest loan's row) is one a
        // pledge write on that newest loan holds too.
        $source = $this->createReleasedLoan();
        $this->pledge($source, Collateral::factory()->create(['borrower_id' => $source->borrower_id]));
        $before = $this->state();
        $this->failOnAuditRow('restructure_created', self::DEADLOCK);

        $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            // What createReleasedLoan() leaves owed, so no shortfall.
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
        ])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_any_other_database_error_is_not_turned_into_a_409(): void
    {
        $before = $this->state();
        $this->failOnAuditRow('collateral_attached', ['22001', 1406, "Data too long for column 'description' at row 1"]);

        $this->postJson("/api/loans/{$this->loan->id}/collaterals", [
            'collateral_id' => $this->collateral->id,
            'snapshot_value' => 250000,
        ])->assertStatus(500);

        $this->assertSame($before, $this->state());
    }

    private function pledge(Loan $loan, Collateral $collateral): void
    {
        $loan->collaterals()->attach($collateral->id, ['snapshot_value' => 100, 'attached_at' => now()]);
    }

    /**
     * Refuse the INSERT of the first audit row carrying `$action` with the
     * driver error MySQL raises for `$errorInfo`, built the way PDO builds it:
     * SQLSTATE as the code, the driver code and message in `errorInfo`.
     *
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function failOnAuditRow(string $action, array $errorInfo): void
    {
        $fired = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $action, $errorInfo): void {
            if ($fired || ! str_starts_with($query, 'insert into `audit_logs`') || ! in_array($action, $bindings, true)) {
                return;
            }

            $fired = true;

            [$sqlState, $driverCode, $driverMessage] = $errorInfo;
            $pdo = new PDOException("SQLSTATE[{$sqlState}]: General error: {$driverCode} {$driverMessage}");
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlState);

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'pivot' => DB::table('loan_collaterals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'collaterals' => DB::table('collaterals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'loans' => DB::table('loans')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'audit' => AuditLog::count(),
        ];
    }
}
