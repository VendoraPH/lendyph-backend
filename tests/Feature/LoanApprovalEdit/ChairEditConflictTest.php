<?php

namespace Tests\Feature\LoanApprovalEdit;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanApprovalEditService;
use App\Services\LoanService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Chair's edit at their approval step under a concurrency failure: a
 * deadlock or a lock wait timeout answers 409 in words, and nothing of the
 * edit is left behind.
 */
class ChairEditConflictTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction. Inside one, the edit's own
     * transaction is a savepoint, and Laravel doesn't roll a savepoint back on
     * a deadlock (MySQL has already undone the whole transaction by then), so a
     * leak and a rollback would look the same (as in CollateralWriteConflictTest).
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_an_edit_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $this->assertConflictWritesNothing(self::DEADLOCK);
    }

    public function test_an_edit_that_times_out_on_a_lock_answers_409_and_writes_nothing(): void
    {
        $this->assertConflictWritesNothing(self::LOCK_WAIT_TIMEOUT);
    }

    /**
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function assertConflictWritesNothing(array $errorInfo): void
    {
        [$loan, $chair] = $this->loanAtChairStep();
        $version = $this->actingAs($chair)->getJson("/api/loans/{$loan->id}/approval-edit")->assertOk()->json('data.version');
        $before = (int) AuditLog::query()->max('id');
        $this->failOnAuditRow(LoanApprovalEditService::AUDIT_ACTION, $errorInfo);

        $this->actingAs($chair)
            ->patchJson("/api/loans/{$loan->id}/approval-edit", ['principal_amount' => 80000, 'term' => 12, 'version' => $version])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Another change to this loan was saved at the same time. Reload and try again.']);

        $fresh = $loan->fresh();
        $this->assertSame('60000.00', $fresh->getRawOriginal('principal_amount'));
        $this->assertSame(6, (int) $fresh->term);
        $this->assertSame(0, AuditLog::query()->where('id', '>', $before)->count());
    }

    /**
     * A loan waiting at the default chain's "BOD Chairwoman" step, and a Chair.
     *
     * @return array{0: Loan, 1: User}
     */
    private function loanAtChairStep(): array
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'min_interest_rate' => 1.5, 'term' => 6, 'min_term' => 3, 'max_term' => 12]);
        $manager = User::factory()->inBranches($this->branch)->create();
        $manager->assignRole('manager');
        $chair = User::factory()->inBranches($this->branch)->create();
        $chair->assignRole('bod1');

        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => '2026-12-01',
        ], $this->admin);
        app(LoanService::class)->submitForReview($loan, $this->admin);

        $step = LoanApprovalStep::query()->where('loan_id', $loan->id)->where('status', LoanApprovalStep::STATUS_PENDING)->firstOrFail();
        $this->actingAs($manager)->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")->assertOk();

        return [$loan->fresh(), $chair];
    }

    /**
     * Refuse the INSERT of the first audit row carrying `$action` with the
     * driver error MySQL raises for `$errorInfo`, built the way PDO builds it.
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
}
