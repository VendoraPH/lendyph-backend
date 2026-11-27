<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Releases that collide with another write: the same lock order as every
 * collateral write (the collateral rows in one id-ordered statement, then the
 * loan rows), and a 409 the client can reload from for whatever clash remains,
 * never a 500 and never a loan released twice.
 *
 * The deadlock and lock-wait failures are raised on the release journal's
 * INSERT, after the status, account number, fees, schedule and approval step
 * are written, so "nothing behind" is proved by the rollback.
 */
class LoanReleaseConflictTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /**
     * Opted out of the per-test transaction, as CollateralWriteConflictTest is:
     * inside one, the release's own transaction is a savepoint that Laravel
     * does not roll back on a deadlock, so a leak could not be told from a
     * rollback.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    private const CONFLICT = 'Another change to this loan was saved at the same time. Reload and try again.';

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_a_release_that_deadlocks_answers_409_and_writes_nothing(): void
    {
        $loan = $this->approvedLoan(holding: [$this->collateral()]);
        $before = $this->state();
        $this->failOnReleaseJournal(self::DEADLOCK);

        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertStatus(409)
            ->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_release_whose_lock_wait_times_out_answers_409_and_writes_nothing(): void
    {
        $loan = $this->approvedLoan(holding: [$this->collateral()]);
        $before = $this->state();
        $this->failOnReleaseJournal(self::LOCK_WAIT_TIMEOUT);

        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertStatus(409)
            ->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
    }

    public function test_a_restructure_release_that_deadlocks_answers_409_and_leaves_the_source_open(): void
    {
        $source = $this->createReleasedLoan();
        $source->collaterals()->attach($this->collateral($source->borrower_id)->id, ['snapshot_value' => 100, 'attached_at' => now()]);
        $restructure = $this->approvedRestructureOf($source);
        $before = $this->state();
        $this->failOnReleaseJournal(self::DEADLOCK);

        $this->patchJson("/api/loans/{$restructure->id}/release")
            ->assertStatus(409)
            ->assertExactJson(['message' => self::CONFLICT]);

        $this->assertSame($before, $this->state());
        $this->assertContains($source->fresh()->status, ['released', 'ongoing']);
    }

    public function test_a_second_release_of_the_same_loan_answers_409_and_the_loan_is_released_once(): void
    {
        $loan = $this->approvedLoan();

        // Both requests read the loan as approved before either committed; the
        // first one then releases it.
        $secondRequestsCopy = Loan::findOrFail($loan->id);
        app(LoanService::class)->release(Loan::findOrFail($loan->id), $this->admin);
        $afterFirst = $this->state();

        try {
            app(LoanService::class)->release($secondRequestsCopy, $this->admin);
            $this->fail('A second release of the same loan was not refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame(['message' => self::CONFLICT], $e->getResponse()->getData(true));
        }

        $this->assertSame($afterFirst, $this->state());
        $this->assertSame(1, AccountingJournal::query()->where('source', 'loan_release')->count());
    }

    public function test_a_release_whose_loan_gained_a_collateral_after_its_pledges_were_read_answers_409(): void
    {
        $loan = $this->approvedLoan(holding: [$this->collateral()]);
        $slipsIn = $this->collateral();
        $before = $this->state();

        $slippedIn = false;
        DB::listen(function (QueryExecuted $query) use (&$slippedIn, $loan, $slipsIn): void {
            $sql = strtolower($query->sql);

            if ($slippedIn || ! str_contains($sql, 'from `collaterals`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $slippedIn = true;
            DB::table('loan_collaterals')->insert([
                'loan_id' => $loan->id,
                'collateral_id' => $slipsIn->id,
                'snapshot_value' => 100,
                'attached_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertStatus(409)
            ->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($slippedIn, 'the pledge never slipped in, so this test proved nothing');
        $this->assertSame($before, $this->state());
    }

    public function test_a_release_locks_its_collateral_in_one_id_ordered_statement_then_the_loan(): void
    {
        $high = $this->collateral();
        $low = $this->collateral();
        [$low, $high] = $low->id < $high->id ? [$low, $high] : [$high, $low];
        $loan = $this->approvedLoan(holding: [$high, $low]);

        $reads = $this->lockingReads(fn () => $this->patchJson("/api/loans/{$loan->id}/release")->assertOk());

        $this->assertStringContainsString('from `collaterals`', $reads[0]['sql']);
        $this->assertStringContainsString('order by `id` asc', $reads[0]['sql']);
        $this->assertSame([$low->id, $high->id], $this->lockedIds($reads[0]));

        $this->assertStringContainsString('from `loans`', $reads[1]['sql']);
        $this->assertSame([$loan->id], $this->lockedIds($reads[1]));

        foreach ($reads as $read) {
            $this->assertStringNotContainsString('`loan_collaterals`', $read['sql'], 'release locked the pledges themselves, which gap-locks where the next pledge lands');
        }
    }

    public function test_a_restructure_release_locks_its_collateral_then_the_source_and_the_loan_in_id_order(): void
    {
        $source = $this->createReleasedLoan();
        $collateral = $this->collateral($source->borrower_id);
        $source->collaterals()->attach($collateral->id, ['snapshot_value' => 100, 'attached_at' => now()]);
        $restructure = $this->approvedRestructureOf($source);

        $reads = $this->lockingReads(fn () => $this->patchJson("/api/loans/{$restructure->id}/release")->assertOk());

        $this->assertStringContainsString('from `collaterals`', $reads[0]['sql']);
        $this->assertSame([$collateral->id], $this->lockedIds($reads[0]));

        $this->assertStringContainsString('from `loans`', $reads[1]['sql']);
        $this->assertStringContainsString('order by `id` asc', $reads[1]['sql']);
        $this->assertSame([$source->id, $restructure->id], $this->lockedIds($reads[1]));
    }

    /**
     * An approved loan, optionally holding the given collaterals (pledged while
     * it was a draft, as the form does).
     *
     * @param  list<Collateral>  $holding
     */
    private function approvedLoan(array $holding = []): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => $this->borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        foreach ($holding as $collateral) {
            $loan->collaterals()->attach($collateral->id, ['snapshot_value' => 100, 'attached_at' => now()]);
        }

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    private function approvedRestructureOf(Loan $source): Loan
    {
        $response = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            // What createReleasedLoan() leaves owed, so no shortfall.
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
        ])->assertCreated();

        $restructure = Loan::findOrFail($response->json('data.id'));

        // Restructure approval is dual control, so the sign-off is a second user.
        $approver = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::where('name', 'admin')->first()));
        $this->patchJson("/api/loans/{$restructure->id}/submit")->assertOk();
        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$restructure->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);

        return $restructure->fresh();
    }

    private function collateral(?int $borrowerId = null): Collateral
    {
        return Collateral::factory()->create(['borrower_id' => $borrowerId ?? $this->borrower->id, 'amount' => 250000]);
    }

    /**
     * Refuse the INSERT of the first `loan_release` journal with the driver
     * error MySQL raises for `$errorInfo`.
     *
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function failOnReleaseJournal(array $errorInfo): void
    {
        $fired = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $errorInfo): void {
            if ($fired || ! str_starts_with($query, 'insert into `accounting_journals`') || ! in_array('loan_release', $bindings, true)) {
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
     * The ids a `whereKey()` lock names, whether bound or inlined (an integer
     * id list is inlined, a single id is bound).
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
            'pivot' => $rows('loan_collaterals'),
            'schedules' => $rows('amortization_schedules'),
            'steps' => $rows('loan_approval_steps'),
            'journals' => $rows('accounting_journals'),
            'journal_lines' => $rows('accounting_journal_lines'),
            'audit' => AuditLog::count(),
        ];
    }
}
