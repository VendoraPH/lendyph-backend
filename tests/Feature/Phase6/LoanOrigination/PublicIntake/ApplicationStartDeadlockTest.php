<?php

namespace Tests\Feature\Phase6\LoanOrigination\PublicIntake;

use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use App\Services\LoanOrigination\Intake\ApplicantIntake;
use Closure;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Two first-time applicants starting at the same moment (finish run round 6,
 * the Low from #390's review). Each start reads the open draft for its
 * mobile under a lock before storing one; with no row to lock, both take a
 * gap lock on the same index range and then insert into it, which MySQL
 * answers with a deadlock, rolling one start back. That start is retried
 * once on what is committed by then; a second deadlock answers 409 in words,
 * never a 500, and nothing is stored twice.
 *
 * The deadlock is simulated on the application's insert. The file opts out
 * of the per-test transaction: inside one, the start's transaction would be a
 * savepoint, and the retry belongs to the outermost transaction.
 */
class ApplicationStartDeadlockTest extends TestCase
{
    use PublicApplyHelpers;

    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    protected function setUp(): void
    {
        parent::setUp();

        // The daily code caps count from Manila midnight.
        $this->travelTo(now('Asia/Manila')->setTime(12, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_start_that_deadlocks_once_is_retried_and_stores_one_application(): void
    {
        $link = $this->enableIntake();
        $code = $this->requestCode($link)->assertOk()->json('data.test_mode.code');
        $fired = $this->failOnInsertInto('origination_applications', self::DEADLOCK, times: 1);

        $this->verifyCode($link, (string) $code)->assertOk()->assertJsonStructure(['data' => ['session_token']]);

        $this->assertSame(1, $fired());
        $this->assertSame(1, $this->applicationsOn($link));
        $this->assertSame(1, (int) $link->fresh()->started, 'the start is counted once');
    }

    public function test_a_start_that_deadlocks_twice_answers_409_in_words_and_stores_nothing(): void
    {
        $link = $this->enableIntake();
        $code = $this->requestCode($link)->assertOk()->json('data.test_mode.code');
        $fired = $this->failOnInsertInto('origination_applications', self::DEADLOCK, times: PHP_INT_MAX);

        $this->verifyCode($link, (string) $code)->assertStatus(409)->assertExactJson(['message' => ApplicantIntake::STARTED_AT_ONCE]);

        $this->assertSame(2, $fired(), 'tried once more, then answered');
        $this->assertSame(0, $this->applicationsOn($link));
        $this->assertSame(0, (int) $link->fresh()->started);
    }

    public function test_a_lock_wait_timeout_answers_409_at_once(): void
    {
        $link = $this->enableIntake();
        $code = $this->requestCode($link)->assertOk()->json('data.test_mode.code');
        $fired = $this->failOnInsertInto('origination_applications', self::LOCK_WAIT_TIMEOUT, times: PHP_INT_MAX);

        $this->verifyCode($link, (string) $code)->assertStatus(409)->assertExactJson(['message' => ApplicantIntake::STARTED_AT_ONCE]);

        $this->assertSame(1, $fired(), 'the wait was already spent, so no retry');
        $this->assertSame(0, $this->applicationsOn($link));
    }

    private function applicationsOn(OriginationLink $link): int
    {
        return OriginationApplication::query()->where('link_id', $link->id)->count();
    }

    /**
     * Throws the given database error on the next `$times` inserts into
     * `$table`, as MySQL would after rolling the transaction back.
     *
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     * @return Closure(): int how many times it fired
     */
    private function failOnInsertInto(string $table, array $errorInfo, int $times): Closure
    {
        $fired = 0;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $table, $errorInfo, $times): void {
            if ($fired >= $times || ! str_starts_with($query, "insert into `{$table}`")) {
                return;
            }

            $fired++;

            [$sqlState, $driverCode, $driverMessage] = $errorInfo;
            $pdo = new PDOException("SQLSTATE[{$sqlState}]: General error: {$driverCode} {$driverMessage}");
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlState);

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });

        return function () use (&$fired): int {
            return $fired;
        };
    }
}
