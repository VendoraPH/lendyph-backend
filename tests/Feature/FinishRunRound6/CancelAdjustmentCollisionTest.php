<?php

namespace Tests\Feature\FinishRunRound6;

use App\Models\LoanAdjustment;
use App\Services\LoanAdjustmentService;
use Closure;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Cancelling an approved adjustment takes the locks an approve, reject and
 * apply take, in the same order (the loan row, then the adjustment's), and
 * re-reads the status under them: one applied or cancelled since it was read
 * answers 409, as does a deadlock or a lock wait timeout, and nothing is
 * written.
 */
class CancelAdjustmentCollisionTest extends TestCase
{
    use SetupLendyPH;

    /** As LoanWriteCollisionTest: inside a wrapping transaction a rollback can't be told from a leak. */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    private const CONFLICT = 'Another change to this loan was saved at the same time. Reload and try again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function approvedAdjustment(): LoanAdjustment
    {
        $pending = app(LoanAdjustmentService::class)->createAdjustment($this->createReleasedLoan(), [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -1000],
        ], $this->admin);

        return app(LoanAdjustmentService::class)->approveAdjustment($pending, $this->admin, 'ok')->fresh();
    }

    private function cancel(LoanAdjustment $adjustment)
    {
        return $this->patchJson("/api/loan-adjustments/{$adjustment->id}/cancel", ['reason' => 'Requested by mistake.']);
    }

    public function test_a_cancel_of_an_adjustment_applied_after_it_was_read_answers_409(): void
    {
        $adjustment = $this->approvedAdjustment();
        $fired = $this->atTheFirstLock('loans', function () use ($adjustment): void {
            DB::table('loan_adjustments')->where('id', $adjustment->id)->update(['status' => 'applied']);
        });

        $this->cancel($adjustment)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        // The slipped-in write ran on the request's own connection, so the
        // 409's rollback takes it too: the row is as it was, never cancelled.
        $this->assertTrue($fired(), 'the other apply never landed, so this test proved nothing');
        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertNull($adjustment->fresh()->cancelled_at);
    }

    public function test_a_cancel_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $adjustment = $this->approvedAdjustment();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_adjustments`') && in_array('cancelled', $bindings, true), self::DEADLOCK);

        $this->cancel($adjustment)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertNull($adjustment->fresh()->cancellation_reason);
    }

    public function test_a_cancel_whose_lock_wait_times_out_answers_409_and_writes_nothing(): void
    {
        $adjustment = $this->approvedAdjustment();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_adjustments`') && in_array('cancelled', $bindings, true), self::LOCK_WAIT_TIMEOUT);

        $this->cancel($adjustment)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame('approved', $adjustment->fresh()->status);
    }

    public function test_a_cancel_locks_the_loan_then_the_adjustment(): void
    {
        $adjustment = $this->approvedAdjustment();
        $recording = false;
        $reads = [];
        Event::listen(TransactionBeginning::class, function () use (&$recording): void {
            $recording = true;
        });
        DB::listen(function (QueryExecuted $query) use (&$recording, &$reads): void {
            if ($recording && str_contains(strtolower($query->sql), 'for update')) {
                $reads[] = strtolower($query->sql);
            }
        });

        $this->cancel($adjustment)->assertOk();

        $this->assertStringContainsString('from `loans`', $reads[0]);
        $this->assertStringContainsString('from `loan_adjustments`', $reads[1]);
    }

    public function test_an_apply_racing_a_cancel_answers_409(): void
    {
        $adjustment = $this->approvedAdjustment();
        $fired = $this->atTheFirstLock('loans', function () use ($adjustment): void {
            DB::table('loan_adjustments')->where('id', $adjustment->id)->update(['status' => 'cancelled']);
        });

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($fired(), 'the cancel never landed, so this test proved nothing');
        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertNull($adjustment->fresh()->applied_at);
    }

    /** @return Closure(): bool */
    private function atTheFirstLock(string $table, Closure $change): Closure
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $table, $change): void {
            $sql = strtolower($query->sql);

            if ($fired || ! str_contains($sql, "from `{$table}`") || ! str_contains($sql, 'for update')) {
                return;
            }

            $fired = true;
            $change();
        });

        return function () use (&$fired): bool {
            return $fired;
        };
    }

    /**
     * @param  Closure(string, array<int, mixed>): bool  $matches
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function failOn(Closure $matches, array $errorInfo): void
    {
        $fired = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $matches, $errorInfo): void {
            if ($fired || ! $matches($query, $bindings)) {
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
