<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Services\GeneratedNumberWrite;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Closure;
use Exception;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Two saves that generate the next number at the same moment. The loser's
 * whole write is rolled back and tried again on what is committed by then, up
 * to GeneratedNumberWrite::MAX_TRIES times. Still colliding, it answers 409,
 * never 500, and nothing is ever created twice.
 */
class GeneratedNumberCollisionTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction, as LoanWriteCollisionTest is:
     * inside one the write's transaction would be a savepoint, and the helper
     * leaves the retry to the outermost transaction.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    /**
     * What each kind of save inserts and where its number lives.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function saves(): array
    {
        return [
            'member' => ['member', 'borrowers', 'borrower_code', 'Another member was created at the same moment. Submit again.'],
            'co-maker' => ['coMaker', 'co_makers', 'co_maker_code', 'Another co-maker was created at the same moment. Submit again.'],
            'share capital entry' => ['ledgerEntry', 'share_capital_ledger', 'reference', 'Another share capital entry was saved at the same moment. Submit again.'],
            'adjustment' => ['adjustment', 'loan_adjustments', 'adjustment_number', 'Another adjustment was requested at the same moment. Submit again.'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_indexes_it_retries_on_exist(): void
    {
        $indexes = [
            'borrowers' => GeneratedNumberWrite::BORROWER_CODE_INDEX,
            'co_makers' => GeneratedNumberWrite::CO_MAKER_CODE_INDEX,
            'loan_adjustments' => GeneratedNumberWrite::ADJUSTMENT_NUMBER_INDEX,
            'share_capital_ledger' => GeneratedNumberWrite::SHARE_CAPITAL_REFERENCE_INDEX,
        ];

        foreach ($indexes as $table => $index) {
            $this->assertContains($index, array_column(Schema::getIndexes($table), 'name'), $table);
        }
    }

    #[DataProvider('saves')]
    public function test_a_real_collision_is_retried_and_saves_exactly_one_row(string $save, string $table, string $column, string $message): void
    {
        $send = $this->{$save}();
        $before = $this->rows($table);

        $slips = $this->slipTheNextNumberIn($table, $column, times: 1);

        $response = $send();
        $response->assertCreated();

        $this->assertSame(1, $slips());
        $this->assertSame($before + 1, $this->rows($table));
    }

    #[DataProvider('saves')]
    public function test_a_save_that_keeps_colliding_answers_409_and_leaves_nothing(string $save, string $table, string $column, string $message): void
    {
        $send = $this->{$save}();
        $before = $this->state();

        $slips = $this->slipTheNextNumberIn($table, $column, times: PHP_INT_MAX);

        $send()->assertStatus(409)->assertExactJson(['message' => $message]);

        $this->assertSame(GeneratedNumberWrite::MAX_TRIES, $slips());
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('saves')]
    public function test_a_deadlock_is_retried_and_saves_exactly_one_row(string $save, string $table, string $column, string $message): void
    {
        $send = $this->{$save}();
        $before = $this->rows($table);
        $fired = $this->failOnInsertInto($table, self::DEADLOCK, times: 2);

        $send()->assertCreated();

        $this->assertSame(2, $fired());
        $this->assertSame($before + 1, $this->rows($table));
    }

    public function test_a_lock_wait_timeout_answers_409_at_once_after_the_wait_it_already_cost(): void
    {
        $send = $this->member();
        $before = $this->state();
        $fired = $this->failOnInsertInto('borrowers', self::LOCK_WAIT_TIMEOUT, times: PHP_INT_MAX);

        $send()->assertStatus(409);

        $this->assertSame(1, $fired());
        $this->assertSame($before, $this->state());
    }

    public function test_a_duplicate_on_any_other_index_is_not_retried(): void
    {
        $send = $this->coMaker();
        $before = $this->state();
        $fired = 0;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired): void {
            if (str_starts_with($query, 'insert into `co_makers`')) {
                $fired++;

                throw $this->duplicateKey($query, $bindings, 'co_makers_member_borrower_id_unique');
            }
        });

        $send()->assertStatus(500);

        $this->assertSame(1, $fired);
        $this->assertSame($before, $this->state());
    }

    public function test_a_retried_member_keeps_one_pledge_and_one_created_audit_row(): void
    {
        $send = $this->member();
        $this->slipTheNextNumberIn('borrowers', 'borrower_code', times: 1);
        $pledges = ShareCapitalPledge::count();
        $audit = AuditLog::where('action', 'created')->where('auditable_type', (new Borrower)->getMorphClass())->count();

        $response = $send()->assertCreated();

        $member = Borrower::findOrFail($response->json('data.id'));
        $this->assertSame($pledges + 1, ShareCapitalPledge::count());
        $this->assertSame(1, ShareCapitalPledge::where('borrower_id', $member->id)->count());
        $this->assertSame($audit + 1, AuditLog::where('action', 'created')->where('auditable_type', $member->getMorphClass())->count());
    }

    public function test_a_retried_member_is_refused_when_the_same_member_landed_meanwhile(): void
    {
        $send = $this->member();
        $this->failOnInsertInto('borrowers', self::DEADLOCK, times: 1);
        $twin = $this->landOnFirstRollback(fn (): Borrower => Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Collision',
            'middle_name' => null,
            'last_name' => 'Probe',
            'birthdate' => '1990-01-15',
        ]));

        $before = Borrower::count();

        $send()->assertStatus(422)->assertJsonValidationErrors('first_name');

        $this->assertNotNull($twin());
        $this->assertSame($before + 1, Borrower::count());
    }

    public function test_create_anyway_still_creates_after_a_retry(): void
    {
        $send = $this->member(['force' => true]);
        Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Collision',
            'middle_name' => null,
            'last_name' => 'Probe',
            'birthdate' => '1990-01-15',
        ]);
        $this->slipTheNextNumberIn('borrowers', 'borrower_code', times: 1);

        $send()->assertCreated();
    }

    public function test_a_bulk_share_capital_save_that_collides_answers_409_at_once_and_posts_nothing(): void
    {
        $pledges = [$this->memberPledge(), $this->memberPledge()];
        $before = $this->state();
        $slips = $this->slipTheNextNumberIn('share_capital_ledger', 'reference', times: PHP_INT_MAX);

        $this->postJson('/api/pledges/bulk-entries', ['entries' => array_map(fn (ShareCapitalPledge $pledge): array => [
            'pledge_id' => $pledge->id,
            'amount' => 100,
            'type' => 'credit',
            'date' => now()->toDateString(),
        ], $pledges)])->assertStatus(409)->assertExactJson(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT]);

        $this->assertSame(1, $slips(), 'a bulk save must not be tried again: an overlapping one would post twice');
        $this->assertSame($before, $this->state());
    }

    public function test_an_auto_credit_run_that_collides_answers_409_at_once_and_posts_nothing(): void
    {
        $pledge = $this->memberPledge();
        $pledge->update(['amount' => 250, 'auto_credit' => true]);
        $before = $this->state();
        $slips = $this->slipTheNextNumberIn('share_capital_ledger', 'reference', times: PHP_INT_MAX);

        $this->postJson('/api/auto-credit/process')->assertStatus(409)->assertExactJson(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT]);

        $this->assertSame(1, $slips(), 'an auto-credit run must not be tried again: an overlapping one would credit each pledge twice');
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::table('auto_credit_runs')->count());
    }

    public function test_an_auto_credit_run_that_deadlocks_answers_409_at_once_and_posts_nothing(): void
    {
        $pledge = $this->memberPledge();
        $pledge->update(['amount' => 250, 'auto_credit' => true]);
        $before = $this->state();
        $fired = $this->failOnInsertInto('share_capital_ledger', self::DEADLOCK, times: PHP_INT_MAX);

        $this->postJson('/api/auto-credit/process')->assertStatus(409)->assertExactJson(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT]);

        $this->assertSame(1, $fired());
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::table('auto_credit_runs')->count());
    }

    public function test_a_co_maker_number_collision_while_creating_a_loan_answers_409_and_leaves_no_loan(): void
    {
        $loan = $this->draftLoan();
        $coMaker = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = Loan::count();
        $before = $this->state();
        $slips = $this->slipTheNextNumberIn('co_makers', 'co_maker_code', times: PHP_INT_MAX);

        $this->postJson('/api/loans', [
            'borrower_id' => $loan->borrower_id,
            'loan_product_id' => $loan->loan_product_id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'co_maker_ids' => [$coMaker->id],
        ])->assertStatus(409)->assertExactJson(['message' => 'Another loan application was created at the same moment. Submit again.']);

        $this->assertSame(1, $slips());
        $this->assertSame($loans, Loan::count());
        $this->assertSame($before, $this->state());
    }

    public function test_a_co_maker_number_collision_while_editing_a_loan_with_no_collateral_list_answers_409(): void
    {
        $loan = $this->draftLoan();
        $coMaker = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $before = $this->state();
        $slips = $this->slipTheNextNumberIn('co_makers', 'co_maker_code', times: PHP_INT_MAX);

        $this->putJson("/api/loans/{$loan->id}", ['co_maker_ids' => [$coMaker->id], 'principal_amount' => 55000])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Another change to this loan was saved at the same time. Reload and try again.']);

        $this->assertSame(1, $slips());
        $this->assertSame($before, $this->state());
        $this->assertSame(60000.0, (float) $loan->fresh()->principal_amount);
    }

    public function test_a_co_maker_number_collision_while_editing_a_loan_with_a_collateral_list_answers_409(): void
    {
        $loan = $this->draftLoan();
        $coMaker = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $before = $this->state();
        $slips = $this->slipTheNextNumberIn('co_makers', 'co_maker_code', times: PHP_INT_MAX);

        $this->putJson("/api/loans/{$loan->id}", ['co_maker_ids' => [$coMaker->id], 'collaterals' => [], 'principal_amount' => 55000])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Another change to this collateral was saved at the same time. Reload and try again.']);

        $this->assertSame(1, $slips());
        $this->assertSame($before, $this->state());
        $this->assertSame(60000.0, (float) $loan->fresh()->principal_amount);
    }

    public function test_an_anonymous_registration_refused_after_a_retry_gets_the_generic_message(): void
    {
        $send = $this->anonymousRegistration();
        $this->failOnInsertInto('borrowers', self::DEADLOCK, times: 1);
        $twin = $this->landOnFirstRollback(fn (): Borrower => Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Anonymous',
            'middle_name' => null,
            'last_name' => 'Applicant',
            'birthdate' => '1990-01-15',
        ]));
        $before = Borrower::count();

        $response = $send()->assertStatus(422);

        $this->assertSame(['A similar member already exists. Please contact your branch to continue.'], $response->json('errors.first_name'));
        $this->assertStringNotContainsString($twin()->borrower_code, $response->getContent());
        $this->assertSame($before + 1, Borrower::count());
    }

    public function test_an_anonymous_registration_with_a_key_skips_the_recheck_and_reads_the_winner_back(): void
    {
        $key = (string) Str::uuid();
        $send = $this->anonymousRegistration(['registration_uuid' => $key]);
        $this->failOnInsertInto('borrowers', self::DEADLOCK, times: 1);
        $twin = $this->landOnFirstRollback(fn (): Borrower => Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'registration_uuid' => $key,
            'status' => 'pending',
            'first_name' => 'Anonymous',
            'middle_name' => null,
            'last_name' => 'Applicant',
            'birthdate' => '1990-01-15',
        ]));
        $before = Borrower::count();

        $response = $send()->assertCreated();

        $this->assertSame($twin()->id, $response->json('data.id'));
        $this->assertSame($before + 1, Borrower::count());
    }

    public function test_a_co_maker_added_while_awaiting_release_is_retried_with_one_link(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('loans')->where('id', $loan->id)->update(['status' => 'approved', 'released_at' => null]);
        $before = CoMaker::count();
        $slips = $this->slipTheNextNumberIn('co_makers', 'co_maker_code', times: 1);

        $this->postJson("/api/loans/{$loan->id}/co-makers", [
            'first_name' => 'Late',
            'last_name' => 'Cosigner',
            'address' => 'Somewhere',
            'contact_number' => '09170000000',
            'relationship_to_borrower' => 'sibling',
        ])->assertSuccessful();

        $this->assertSame(1, $slips());
        $this->assertSame($before + 1, CoMaker::count());
        $this->assertSame(1, DB::table('co_maker_loan')->where('loan_id', $loan->id)->count());
    }

    public function test_a_build_up_payment_takes_its_reference_from_its_receipt_and_never_a_dated_number(): void
    {
        $loan = $this->buildUpLoan();

        // What the dated numbering would issue next is taken, as another
        // writer's uncommitted-then-committed row would make it.
        $this->ledgerRowWith('SC-'.now()->format('Ymd').'-000001', $loan->borrower_id);

        $response = $this->buildUpPayment($loan)->assertCreated();

        $repayment = Repayment::findOrFail($response->json('data.id'));
        $credit = ShareCapitalLedger::where('repayment_id', $repayment->id)->firstOrFail();

        $this->assertSame("SC-{$repayment->receipt_number}", $credit->reference);
    }

    public function test_voiding_a_build_up_payment_takes_its_own_reference_from_the_receipt(): void
    {
        $loan = $this->buildUpLoan();
        $repayment = Repayment::findOrFail($this->buildUpPayment($loan)->assertCreated()->json('data.id'));

        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed wrongly', $this->admin);

        $reversal = ShareCapitalLedger::where('repayment_id', $repayment->id)->where('debit', '>', 0)->firstOrFail();

        $this->assertSame("SCV-{$repayment->receipt_number}", $reversal->reference);
    }

    public function test_two_build_up_payments_on_one_day_never_share_a_reference(): void
    {
        $first = $this->buildUpLoan();
        $second = $this->buildUpLoan();

        $this->buildUpPayment($first)->assertCreated();
        $this->buildUpPayment($second)->assertCreated();

        $this->assertSame(2, ShareCapitalLedger::query()->distinct()->count('reference'));
    }

    public function test_a_build_up_payment_whose_share_capital_row_collides_answers_409_and_writes_nothing(): void
    {
        $loan = $this->buildUpLoan();
        $repayments = Repayment::count();
        $ledger = ShareCapitalLedger::count();

        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (str_starts_with($query, 'insert into `share_capital_ledger`')) {
                throw $this->duplicateKey($query, $bindings, GeneratedNumberWrite::SHARE_CAPITAL_REFERENCE_INDEX);
            }
        });

        $this->buildUpPayment($loan)->assertStatus(409);

        $this->assertSame($repayments, Repayment::count());
        $this->assertSame($ledger, ShareCapitalLedger::count());
    }

    public function test_an_extension_whose_number_collides_answers_409_and_writes_nothing(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => 60000,
        ]);
        $before = $this->state();

        $slips = $this->slipTheNextNumberIn('loan_adjustments', 'adjustment_number', times: PHP_INT_MAX);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer', 'remarks' => 'Collision probe'])->assertStatus(409);

        $this->assertGreaterThanOrEqual(1, $slips());
        $this->assertSame($before, $this->state());
    }

    /**
     * @return Closure(): TestResponse
     */
    private function member(array $extra = []): Closure
    {
        $payload = [
            'first_name' => 'Collision',
            'last_name' => 'Probe',
            'birthdate' => '1990-01-15',
            'gender' => 'male',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'address' => 'Somewhere',
            'branch_id' => $this->branch->id,
            ...$extra,
        ];

        return fn (): TestResponse => $this->postJson('/api/borrowers', $payload);
    }

    /**
     * @return Closure(): TestResponse
     */
    private function coMaker(): Closure
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return fn (): TestResponse => $this->postJson("/api/borrowers/{$member->id}/co-makers", [
            'first_name' => 'Collision',
            'last_name' => 'Cosigner',
        ]);
    }

    /**
     * @return Closure(): TestResponse
     */
    private function ledgerEntry(): Closure
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return fn (): TestResponse => $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $member->id,
            'date' => now()->toDateString(),
            'description' => 'Collision probe',
            'type' => 'credit',
            'amount' => 100,
        ]);
    }

    /**
     * @return Closure(): TestResponse
     */
    private function adjustment(): Closure
    {
        $loan = $this->createReleasedLoan();

        return fn (): TestResponse => $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'description' => 'Collision probe',
            'new_values' => ['schedule_ids' => [$loan->amortizationSchedules->first()->id]],
            'remarks' => 'Collision probe',
        ]);
    }

    /**
     * The public form's request, from a caller who is not signed in.
     *
     * @param  array<string, mixed>  $extra
     * @return Closure(): TestResponse
     */
    private function anonymousRegistration(array $extra = []): Closure
    {
        $payload = [
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'first_name' => 'Anonymous',
            'last_name' => 'Applicant',
            'birthdate' => '1990-01-15',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'address' => 'Somewhere',
            ...$extra,
        ];

        $this->app['auth']->forgetGuards();

        return fn (): TestResponse => $this->postJson('/api/borrowers', $payload);
    }

    /**
     * Another request commits `$land` right after this one's first attempt
     * rolls back, before it is tried again. Returns what landed, or null.
     *
     * @return Closure(): mixed
     */
    private function landOnFirstRollback(Closure $land): Closure
    {
        $landed = null;

        Event::listen(TransactionRolledBack::class, function () use (&$landed, $land): void {
            $landed ??= $land();
        });

        return function () use (&$landed): mixed {
            return $landed;
        };
    }

    private function draftLoan(): Loan
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => $member->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    private function memberPledge(): ShareCapitalPledge
    {
        return Borrower::factory()->create(['branch_id' => $this->branch->id])->shareCapitalPledge()->firstOrFail();
    }

    private function buildUpLoan(): Loan
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);

        return $loan->fresh('amortizationSchedules', 'borrower', 'loanProduct');
    }

    private function buildUpPayment(Loan $loan): TestResponse
    {
        return $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $loan->amortizationSchedules->first()->total_due + 750,
            'method' => 'cash',
        ]);
    }

    private function ledgerRowWith(string $reference, int $borrowerId): void
    {
        DB::table('share_capital_ledger')->insert([
            'borrower_id' => $borrowerId,
            'date' => now()->toDateString(),
            'description' => 'Another writer',
            'reference' => $reference,
            'debit' => 0,
            'credit' => 1,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Another save takes the number this one is about to use, for real: on the
     * first `$times` inserts into `$table`, a copy of the insert's own row
     * lands first with that number, and the unique index does the rest. Inside
     * the attempt's transaction, so it goes when the attempt rolls back and the
     * next attempt reads the same number again. Returns how many landed.
     *
     * @return Closure(): int
     */
    private function slipTheNextNumberIn(string $table, string $column, int $times): Closure
    {
        $landed = 0;
        $slipping = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$landed, &$slipping, $table, $column, $times): void {
            if ($slipping || $landed >= $times || ! str_starts_with($query, "insert into `{$table}`")) {
                return;
            }

            preg_match('/\((.+?)\) values/', $query, $match);
            $columns = array_map(fn (string $name): string => trim($name, ' `'), explode(',', $match[1]));

            if (! in_array($column, $columns, true)) {
                return;
            }

            $row = array_combine($columns, $bindings);

            // The twin's other unique columns must not be what collides.
            foreach (['registration_uuid', 'external_account_no', 'member_borrower_id'] as $other) {
                if (array_key_exists($other, $row)) {
                    $row[$other] = null;
                }
            }

            $slipping = true;
            $landed++;
            DB::table($table)->insert($row);
            $slipping = false;
        });

        return function () use (&$landed): int {
            return $landed;
        };
    }

    /**
     * Refuse the first `$times` inserts into `$table` with the driver error
     * MySQL raises for `$errorInfo`. Returns how many were refused.
     *
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     * @return Closure(): int
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

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function duplicateKey(string $query, array $bindings, string $index): UniqueConstraintViolationException
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key '{$index}'";
        $pdo = new PDOException($message);
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key '{$index}'"];
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '23000');

        return (new UniqueConstraintViolationException(DB::connection()->getName(), $query, $bindings, $pdo))->setIndex($index);
    }

    private function rows(string $table): int
    {
        return DB::table($table)->count();
    }

    /**
     * @return array<string, int>
     */
    private function state(): array
    {
        return [
            'borrowers' => Borrower::count(),
            'pledges' => ShareCapitalPledge::count(),
            'co_makers' => CoMaker::count(),
            'ledger' => ShareCapitalLedger::count(),
            'adjustments' => LoanAdjustment::count(),
            'repayments' => Repayment::count(),
            'loan_ledger' => DB::table('loan_ledger_entries')->count(),
            'schedules' => DB::table('amortization_schedules')->count(),
            'journals' => DB::table('accounting_journals')->count(),
            'audit' => AuditLog::count(),
        ];
    }
}
