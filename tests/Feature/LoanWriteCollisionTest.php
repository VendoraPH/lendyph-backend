<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Closure;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Voiding a payment, creating a loan, applying an adjustment and extending a
 * loan, when they collide with another write: the order every loan write
 * locks in (the collateral rows the operation touches in one id-ordered
 * statement, then the loan row, then the operation's own row), the state
 * re-read under those locks, and a 409 the client can reload from, never a
 * 500 and never the same change applied twice.
 */
class LoanWriteCollisionTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction, as LoanReleaseConflictTest is:
     * inside one, the write's own transaction is a savepoint that Laravel does
     * not roll back on a deadlock, so a leak could not be told from a
     * rollback.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    private const DATA_TOO_LONG = ['22001', 1406, "Data too long for column 'description' at row 1"];

    private const CONFLICT = 'Another change to this loan was saved at the same time. Reload and try again.';

    private const CREATE_CONFLICT = 'Another loan application was created at the same moment. Submit again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── voiding a payment ────────────────────────────────────────────────

    public function test_a_void_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $repayment = $this->postedRepayment();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `repayments`') && in_array('voided', $bindings, true), self::DEADLOCK);

        $this->voidRequest($repayment)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_void_whose_lock_wait_times_out_answers_409_and_writes_nothing(): void
    {
        $repayment = $this->postedRepayment();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `repayments`') && in_array('voided', $bindings, true), self::LOCK_WAIT_TIMEOUT);

        $this->voidRequest($repayment)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_void_locks_its_collateral_then_the_loan_then_the_payment(): void
    {
        $repayment = $this->postedRepayment(withCollateral: true);

        $reads = $this->lockingReads(fn () => $this->voidRequest($repayment)->assertOk());

        $this->assertStringContainsString('from `collaterals`', $reads[0]['sql']);
        $this->assertStringContainsString('order by `collaterals`.`id` asc', $reads[0]['sql']);

        $this->assertStringContainsString('from `loans`', $reads[1]['sql']);
        $this->assertSame([$repayment->loan_id], $this->lockedIds($reads[1]));

        $this->assertStringContainsString('from `repayments`', $reads[2]['sql']);
        $this->assertSame([$repayment->id], $this->lockedIds($reads[2]));
    }

    public function test_a_second_void_of_the_same_payment_answers_409_and_reverses_nothing_again(): void
    {
        $repayment = $this->postedRepayment();

        // Both requests read the payment as posted before either committed; the
        // first one then voids it.
        $secondRequestsCopy = Repayment::findOrFail($repayment->id);
        app(RepaymentService::class)->voidRepayment(Repayment::findOrFail($repayment->id), 'Keyed twice', $this->admin);
        $afterFirst = $this->state();

        $this->assertConflict(fn () => app(RepaymentService::class)->voidRepayment($secondRequestsCopy, 'Keyed twice', $this->admin));

        $this->assertSame($afterFirst, $this->state());
    }

    public function test_a_void_of_a_payment_voided_after_it_was_read_answers_409(): void
    {
        $repayment = $this->postedRepayment(withCollateral: true);
        $before = $this->state();

        $slippedIn = $this->atTheCollateralLock(function () use ($repayment): void {
            DB::table('repayments')->where('id', $repayment->id)->update(['status' => 'voided']);
        });

        $this->voidRequest($repayment)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($slippedIn(), 'the other void never landed, so this test proved nothing');
        $this->assertSame($before, $this->state());
    }

    public function test_a_void_on_a_loan_restructured_after_it_was_read_answers_409(): void
    {
        $repayment = $this->postedRepayment(withCollateral: true);
        $before = $this->state();

        $slippedIn = $this->atTheCollateralLock(function () use ($repayment): void {
            DB::table('loans')->where('id', $repayment->loan_id)->update(['status' => 'restructured']);
        });

        $this->voidRequest($repayment)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($slippedIn(), 'the restructure never landed, so this test proved nothing');
        $this->assertSame($before, $this->state());
    }

    public function test_a_void_still_reverses_a_payment(): void
    {
        $repayment = $this->postedRepayment(withCollateral: true);
        $loan = Loan::findOrFail($repayment->loan_id);
        $paidBefore = (float) $loan->amortizationSchedules()->sum('principal_paid');

        $this->voidRequest($repayment)->assertOk();

        $this->assertSame('voided', $repayment->fresh()->status);
        $this->assertGreaterThan(0, $paidBefore);
        $this->assertEquals(0, (float) $loan->amortizationSchedules()->sum('principal_paid'));
    }

    public function test_a_void_rethrows_any_other_database_error(): void
    {
        $repayment = $this->postedRepayment();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `repayments`') && in_array('voided', $bindings, true), self::DATA_TOO_LONG);

        $this->voidRequest($repayment)->assertStatus(500);

        $this->assertSame($before, $this->state());
    }

    // ── creating a loan ──────────────────────────────────────────────────

    public function test_a_create_that_deadlocks_linking_a_co_maker_answers_409_and_leaves_no_loan(): void
    {
        $payload = $this->createPayload(withCoMaker: true);
        $before = $this->state();
        $this->failOn(fn (string $sql): bool => str_starts_with($sql, 'insert into `co_maker_loan`'), self::DEADLOCK);

        $this->postJson('/api/loans', $payload)->assertStatus(409)->assertExactJson(['message' => self::CREATE_CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_create_whose_lock_wait_times_out_answers_409_and_leaves_no_loan(): void
    {
        $payload = $this->createPayload(withCoMaker: true);
        $before = $this->state();
        $this->failOn(fn (string $sql): bool => str_starts_with($sql, 'insert into `co_maker_loan`'), self::LOCK_WAIT_TIMEOUT);

        $this->postJson('/api/loans', $payload)->assertStatus(409)->assertExactJson(['message' => self::CREATE_CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_create_that_lost_its_application_number_to_another_create_answers_409(): void
    {
        $payload = $this->createPayload();
        $existing = Loan::query()->orderByDesc('id')->firstOrFail();
        $before = $this->state();

        // Another create takes the next number between this one reading the
        // newest loan and inserting its own.
        $slippedIn = false;
        DB::listen(function (QueryExecuted $query) use (&$slippedIn, $existing): void {
            $sql = strtolower($query->sql);

            if ($slippedIn || ! str_contains($sql, 'from `loans`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $slippedIn = true;
            $row = (array) DB::table('loans')->where('id', $existing->id)->first();
            unset($row['id']);
            $row['application_number'] = 'LA-'.str_pad((string) ((int) substr($existing->application_number, 3) + 1), 6, '0', STR_PAD_LEFT);
            $row['loan_account_number'] = null;
            DB::table('loans')->insert($row);
        });

        $this->postJson('/api/loans', $payload)->assertStatus(409)->assertExactJson(['message' => self::CREATE_CONFLICT]);

        $this->assertTrue($slippedIn, 'the other create never landed, so this test proved nothing');
        $this->assertSame($before, $this->state());
    }

    public function test_a_create_rethrows_a_unique_violation_on_any_other_index(): void
    {
        $payload = $this->createPayload(withCoMaker: true);
        $before = $this->state();

        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (! str_starts_with($query, 'insert into `co_maker_loan`')) {
                return;
            }

            $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1' for key 'co_maker_loan.co_maker_loan_loan_id_co_maker_id_unique'";
            $pdo = new PDOException($message);
            $pdo->errorInfo = ['23000', 1062, "Duplicate entry '1-1' for key 'co_maker_loan.co_maker_loan_loan_id_co_maker_id_unique'"];
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '23000');

            throw (new UniqueConstraintViolationException(DB::connection()->getName(), $query, $bindings, $pdo))
                ->setIndex('co_maker_loan_loan_id_co_maker_id_unique');
        });

        $this->postJson('/api/loans', $payload)->assertStatus(500);

        $this->assertSame($before, $this->state());
    }

    public function test_a_create_holds_the_application_number_lock_in_its_transaction(): void
    {
        $payload = $this->createPayload(withCoMaker: true);

        $reads = $this->lockingReads(fn () => $this->postJson('/api/loans', $payload)->assertCreated());

        $this->assertNotSame([], $reads, 'creating a loan took no lock inside a transaction');
        $this->assertStringContainsString('from `loans`', $reads[0]['sql']);
        $this->assertStringContainsString('order by `id` desc', $reads[0]['sql']);
        $this->assertStringNotContainsString('`collaterals`', implode(' ', array_column($reads, 'sql')), 'a create pledges nothing, so it must lock no collateral');
    }

    public function test_a_create_still_creates_the_loan_with_its_co_maker(): void
    {
        $payload = $this->createPayload(withCoMaker: true);

        $id = $this->postJson('/api/loans', $payload)->assertCreated()->json('data.id');

        $loan = Loan::findOrFail($id);
        $this->assertSame('draft', $loan->status);
        $this->assertCount(1, $loan->coMakers);
    }

    // ── applying an adjustment ───────────────────────────────────────────

    public function test_an_apply_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $adjustment = $this->approvedAdjustment();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_adjustments`') && in_array('applied', $bindings, true), self::DEADLOCK);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_an_apply_whose_lock_wait_times_out_answers_409_and_writes_nothing(): void
    {
        $adjustment = $this->approvedAdjustment();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_adjustments`') && in_array('applied', $bindings, true), self::LOCK_WAIT_TIMEOUT);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_an_apply_locks_the_loan_then_the_adjustment(): void
    {
        $adjustment = $this->approvedAdjustment();

        $reads = $this->lockingReads(fn () => $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertOk());

        $this->assertStringContainsString('from `loans`', $reads[0]['sql']);
        $this->assertSame([$adjustment->loan_id], $this->lockedIds($reads[0]));

        $this->assertStringContainsString('from `loan_adjustments`', $reads[1]['sql']);
        $this->assertSame([$adjustment->id], $this->lockedIds($reads[1]));
    }

    public function test_a_second_apply_of_the_same_adjustment_answers_409_and_applies_nothing_again(): void
    {
        $adjustment = $this->approvedAdjustment();

        $secondRequestsCopy = LoanAdjustment::findOrFail($adjustment->id);
        app(LoanAdjustmentService::class)->applyAdjustment(LoanAdjustment::findOrFail($adjustment->id));
        $afterFirst = $this->state();

        $this->assertConflict(fn () => app(LoanAdjustmentService::class)->applyAdjustment($secondRequestsCopy));

        $this->assertSame($afterFirst, $this->state());
    }

    public function test_an_apply_of_an_adjustment_applied_after_it_was_read_answers_409(): void
    {
        $adjustment = $this->approvedAdjustment();
        $before = $this->state();

        $slippedIn = $this->atTheFirstLock('loans', function () use ($adjustment): void {
            DB::table('loan_adjustments')->where('id', $adjustment->id)->update(['status' => 'applied']);
        });

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($slippedIn(), 'the other apply never landed, so this test proved nothing');
        $this->assertSame($before, $this->state());
    }

    public function test_an_apply_still_applies_the_adjustment(): void
    {
        $adjustment = $this->approvedAdjustment();
        $principalBefore = (float) Loan::findOrFail($adjustment->loan_id)->amortizationSchedules()->sum('principal_due');

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertOk();

        $this->assertSame('applied', $adjustment->fresh()->status);
        $this->assertLessThan($principalBefore, (float) Loan::findOrFail($adjustment->loan_id)->amortizationSchedules()->sum('principal_due'));
    }

    public function test_an_apply_rethrows_any_other_database_error(): void
    {
        $adjustment = $this->approvedAdjustment();
        $before = $this->state();
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `loan_adjustments`') && in_array('applied', $bindings, true), self::DATA_TOO_LONG);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertStatus(500);

        $this->assertSame($before, $this->state());
    }

    // ── extending a loan ─────────────────────────────────────────────────

    public function test_an_extend_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $loan = $this->uponMaturityLoan();
        $before = $this->state();
        $this->failOn(fn (string $sql): bool => str_starts_with($sql, 'insert into `loan_ledger_entries`'), self::DEADLOCK);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_an_extend_locks_the_loan_first(): void
    {
        $loan = $this->uponMaturityLoan();

        $reads = $this->lockingReads(fn () => $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk());

        $this->assertStringContainsString('from `loans`', $reads[0]['sql']);
        $this->assertSame([$loan->id], $this->lockedIds($reads[0]));
    }

    public function test_a_second_extend_from_the_same_reading_answers_409_and_extends_once(): void
    {
        $loan = $this->uponMaturityLoan();

        $secondRequestsCopy = Loan::findOrFail($loan->id);
        app(LoanAdjustmentService::class)->extendLoan(Loan::findOrFail($loan->id), null, $this->admin, 'pay');
        $afterFirst = $this->state();

        $this->assertConflict(fn () => app(LoanAdjustmentService::class)->extendLoan($secondRequestsCopy, null, $this->admin, 'pay'));

        $this->assertSame($afterFirst, $this->state());
        $this->assertSame(1, LoanAdjustment::query()->where('loan_id', $loan->id)->where('adjustment_type', 'extension')->count());
    }

    public function test_an_extend_still_extends_the_loan(): void
    {
        $loan = $this->uponMaturityLoan();
        $maturityBefore = $loan->maturity_date->toDateString();

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $this->assertNotSame($maturityBefore, $loan->fresh()->maturity_date->toDateString());
        $this->assertSame(1, Repayment::query()->where('loan_id', $loan->id)->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** A posted payment that paid the first period of a released loan in full. */
    private function postedRepayment(bool $withCollateral = false): Repayment
    {
        $loan = $this->createReleasedLoan();

        if ($withCollateral) {
            $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 250000]);
            $loan->collaterals()->attach($collateral->id, ['snapshot_value' => 100, 'attached_at' => now()]);
        }

        $first = $loan->amortizationSchedules->first();

        return app(RepaymentService::class)->processRepayment(
            $loan->fresh(),
            (float) $first->total_due,
            now()->toDateString(),
            $this->admin,
        );
    }

    private function voidRequest(Repayment $repayment): TestResponse
    {
        return $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed twice']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(bool $withCoMaker = false): array
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        // A loan for the application number lock to take; the seed has none.
        $this->createReleasedLoan();

        return [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'co_maker_ids' => $withCoMaker ? [Borrower::factory()->create(['branch_id' => $this->branch->id])->id] : [],
        ];
    }

    /** An approved balance adjustment taking ₱1,000 off a released loan's principal. */
    private function approvedAdjustment(): LoanAdjustment
    {
        $loan = $this->createReleasedLoan();
        $service = app(LoanAdjustmentService::class);

        $adjustment = $service->createAdjustment($loan, [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -1000],
        ], $this->admin);

        return $service->approveAdjustment($adjustment, $this->admin, 'ok')->fresh();
    }

    /** A released one-month upon-maturity loan, which can be extended. */
    private function uponMaturityLoan(): Loan
    {
        return $this->createReleasedLoan([
            'product' => [
                'interest_method' => 'upon_maturity',
                'term' => 1,
                'frequency' => 'monthly',
                'interest_rate' => 3.0,
            ],
            'principal_amount' => 60000,
        ]);
    }

    private function assertConflict(Closure $act): void
    {
        try {
            $act();
            $this->fail('The second write was not refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame(['message' => self::CONFLICT], $e->getResponse()->getData(true));
        }
    }

    /**
     * Run `$change` the moment the request takes its collateral row lock.
     * Returns whether that happened.
     *
     * @return Closure(): bool
     */
    private function atTheCollateralLock(Closure $change): Closure
    {
        return $this->atTheFirstLock('collaterals', $change);
    }

    /**
     * Run `$change` right after the request's first locking read of `$table`.
     *
     * @return Closure(): bool
     */
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
     * Refuse the first statement `$matches` accepts with the driver error
     * MySQL raises for `$errorInfo`, built the way PDO builds it.
     *
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

    /**
     * Every locking read issued inside the transaction `$act` opens, in order.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function lockingReads(callable $act): array
    {
        $recording = false;
        $reads = [];

        Event::listen(TransactionBeginning::class, function () use (&$recording): void {
            $recording = true;
        });

        DB::listen(function (QueryExecuted $query) use (&$recording, &$reads): void {
            $sql = strtolower($query->sql);

            if ($recording && str_contains($sql, 'for update')) {
                $reads[] = ['sql' => $sql, 'bindings' => $query->bindings];
            }
        });

        $act();

        return $reads;
    }

    /**
     * The ids a `whereKey()` lock names, whether bound or inlined.
     *
     * @param  array{sql: string, bindings: array<int, mixed>}  $read
     * @return list<int>
     */
    private function lockedIds(array $read): array
    {
        $bindings = $read['bindings'];
        $sql = preg_replace_callback('/\?/', function () use (&$bindings): string {
            return (string) array_shift($bindings);
        }, $read['sql']);

        preg_match('/`id` (?:= (\d+)|in \(([\d, ]+)\))/', $sql, $match);

        return array_map('intval', explode(',', $match[1] !== '' ? $match[1] : $match[2]));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        return [
            'loans' => $rows('loans'),
            'co_makers' => $rows('co_makers'),
            'co_maker_loan' => $rows('co_maker_loan'),
            'schedules' => $rows('amortization_schedules'),
            'repayments' => $rows('repayments'),
            'allocations' => $rows('repayment_allocations'),
            'adjustments' => $rows('loan_adjustments'),
            'ledger' => $rows('loan_ledger_entries'),
            'share_capital' => $rows('share_capital_ledger'),
            'journals' => $rows('accounting_journals'),
            'audit' => AuditLog::count(),
        ];
    }
}
