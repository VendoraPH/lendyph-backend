<?php

namespace Tests\Feature\CreditScoring\Api;

use App\Models\CreditApplicationProfile;
use App\Models\CreditScore;
use App\Models\Loan;
use App\Services\CreditScoring\PolicyFlags;
use App\Services\CreditScoring\Recalculator;
use App\Services\CreditScoring\Scorer;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A lock wait timeout or a deadlock while saving an assessment answers 409
 * and saves nothing (LoanWriteTransaction::run()).
 *
 * Not wrapped in a test transaction: inside one the save's transaction is a
 * savepoint, and Laravel doesn't roll a savepoint back on a concurrency
 * error, so the rollback could only be shown with the save's transaction
 * outermost, as it is in production.
 */
class AssessmentConflictTest extends TestCase
{
    use CreditApiFixtures;

    protected bool $wrapsEachTestInTransaction = false;

    /**
     * @return array<string, array{int, string}>
     */
    public static function concurrencyErrors(): array
    {
        return [
            'lock wait timeout' => [1205, 'Lock wait timeout exceeded; try restarting transaction'],
            'deadlock' => [1213, 'Deadlock found when trying to get lock; try restarting transaction'],
        ];
    }

    #[DataProvider('concurrencyErrors')]
    public function test_a_concurrency_error_in_the_save_answers_409_saving_nothing_and_freeing_the_lock(int $code, string $text): void
    {
        $this->pinToday();
        $loan = $this->application($this->member(['monthly_income' => 35000]));
        $this->app->instance(Recalculator::class, new class(app(Scorer::class), app(PolicyFlags::class), $code, $text) extends Recalculator
        {
            public function __construct(Scorer $scorer, PolicyFlags $flags, private readonly int $code, private readonly string $text)
            {
                parent::__construct($scorer, $flags);
            }

            protected function assessUnlocked(Loan $loan, string $reason, bool $dryRun, ?Carbon $asOf): array
            {
                $driver = new PDOException("SQLSTATE[HY000]: General error: {$this->code} {$this->text}");
                $driver->errorInfo = ['HY000', $this->code, $this->text];

                throw new QueryException('mysql', 'select * from `loans` where `id` = ? for update', [$loan->id], $driver);
            }
        });

        $this->actingAs($this->branchAnalyst())
            ->putJson("/api/credit-scoring/loans/{$loan->id}/assessment", ['household_expenses' => 1000])
            ->assertConflict();

        $lock = Cache::lock("credit-scoring:member:{$loan->borrower_id}", 60);
        $this->assertSame(0, CreditApplicationProfile::query()->count());
        $this->assertSame(0, CreditScore::query()->count());
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
