<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use Closure;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Approving or rejecting a public registration while another write collides
 * with it (finish run round 4). Both lock the registration's row first and
 * read it again under that lock; an approval then takes the lock a staff
 * create's duplicate check takes and checks the email under it. A collision
 * answers 409 in words with nothing written: never two outcomes, never a 500.
 *
 * The colliding write lands on a second connection and commits right before
 * the review's first lock or write on the registration's row, as another
 * reviewer's decision, an edit or a staff create would. So this file opts out
 * of the per-test transaction: the other connection can only see committed
 * rows, and the review's own transaction must be a real one for a deadlock to
 * roll it back.
 */
class RegistrationReviewLockTest extends TestCase
{
    use SetupLendyPH;

    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'registration_review_lock';

    private const DECIDED = 'This registration was approved or rejected at the same moment. Reload to see it.';

    private const EMAIL_TAKEN = 'Another member was saved with this email at the same moment. Reload and try again.';

    private const CONFLICT = 'Another change to this registration was saved at the same moment. Reload and try again.';

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decisions(): array
    {
        return ['approved' => ['active'], 'rejected' => ['rejected']];
    }

    #[DataProvider('decisions')]
    public function test_a_registration_decided_while_this_approval_waited_answers_409_and_keeps_that_decision(string $status): void
    {
        $registration = $this->registration();
        $reviewer = User::factory()->create();
        $decision = $status === 'active'
            ? ['status' => 'active', 'approved_by' => $reviewer->id, 'approved_at' => '2026-11-25 09:00:00']
            : ['status' => 'rejected', 'rejected_by' => $reviewer->id, 'rejected_at' => '2026-11-25 09:00:00', 'rejection_reason' => 'The ID does not match.'];
        $landed = $this->atTheRegistrationLock($registration, fn () => $this->other()->table('borrowers')->where('id', $registration->id)->update($decision));
        $audit = (int) AuditLog::query()->max('id');

        $this->approve($registration)->assertStatus(409)->assertExactJson(['message' => self::DECIDED]);

        $this->assertTrue($landed(), 'the other decision landed at the lock');
        $this->assertEquals($decision, (array) DB::table('borrowers')->where('id', $registration->id)->first(array_keys($decision)), 'the other decision stands');
        $this->assertSame(0, AuditLog::query()->where('id', '>', $audit)->count(), 'the refused approval wrote no audit row');
    }

    public function test_an_approval_after_a_staff_create_took_the_same_email_answers_409_and_waits(): void
    {
        $registration = $this->registration(['email' => 'maria.santos@example.com']);
        $landed = $this->atTheRegistrationLock($registration, fn () => $this->other()->table('borrowers')->insert([
            'borrower_code' => 'BRW-900001',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'Maria.Santos@Example.com',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'membership_date' => '2026-11-25',
            'created_at' => '2026-11-25 09:00:00',
            'updated_at' => '2026-11-25 09:00:00',
        ]));

        $this->approve($registration)->assertStatus(409)->assertExactJson(['message' => self::EMAIL_TAKEN]);

        $this->assertTrue($landed(), 'the staff create landed at the lock');
        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertSame(1, Borrower::query()->members()->where('email', 'maria.santos@example.com')->count(), 'one member holds the email');

        // Reloaded and tried again, the approval reads the usual refusal, which
        // names the member to a reviewer who can see them.
        $this->approve($registration)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This email is already on file for BRW-900001 (Maria Santos). Change or clear it in Edit Details before approving.');
    }

    public function test_a_registration_moved_to_another_branch_while_this_approval_waited_answers_409(): void
    {
        $registration = $this->registration();
        $elsewhere = Branch::factory()->create(['name' => 'Town Branch', 'code' => 'TWN']);
        $landed = $this->atTheRegistrationLock($registration, fn () => $this->other()->table('borrowers')->where('id', $registration->id)->update(['branch_id' => $elsewhere->id]));

        $this->approve($registration)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($landed(), 'the edit landed at the lock');
        $fresh = $registration->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($elsewhere->id, (int) $fresh->branch_id);
        $this->assertNull($fresh->approved_by);
    }

    /**
     * @return array<string, array{array{0: string, 1: int, 2: string}}>
     */
    public static function concurrencyErrors(): array
    {
        return ['a deadlock' => [self::DEADLOCK], 'a lock wait timeout' => [self::LOCK_WAIT_TIMEOUT]];
    }

    /**
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    #[DataProvider('concurrencyErrors')]
    public function test_an_approval_the_database_rolls_back_answers_409_and_writes_nothing(array $errorInfo): void
    {
        $registration = $this->registration();
        $audit = (int) AuditLog::query()->max('id');
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `borrowers`') && in_array('active', $bindings, true), $errorInfo);

        $this->approve($registration)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $fresh = $registration->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->approved_by);
        $this->assertSame(0, AuditLog::query()->where('id', '>', $audit)->count());
    }

    public function test_a_rejection_after_an_approval_committed_answers_409_and_the_member_and_pledge_stay(): void
    {
        $registration = $this->registration();
        $reviewer = User::factory()->create();
        $landed = $this->atTheRegistrationLock($registration, fn () => $this->other()->table('borrowers')->where('id', $registration->id)->update([
            'status' => 'active',
            'approved_by' => $reviewer->id,
            'approved_at' => '2026-11-25 09:00:00',
            'membership_date' => '2026-11-25',
            'membership_number' => $registration->borrower_code,
        ]));
        $audit = (int) AuditLog::query()->max('id');

        $this->reject($registration)->assertStatus(409)->assertExactJson(['message' => self::DECIDED]);

        $this->assertTrue($landed(), 'the approval landed first');
        $member = DB::table('borrowers')->where('id', $registration->id)->first();
        $this->assertSame('active', $member->status);
        $this->assertEquals($reviewer->id, $member->approved_by);
        $this->assertNull($member->rejected_by);
        $this->assertNull($member->rejection_reason);
        $this->assertTrue(ShareCapitalPledge::query()->where('borrower_id', $registration->id)->exists(), 'the member keeps their pledge');
        $this->assertSame(0, AuditLog::query()->where('id', '>', $audit)->count(), 'the refused rejection wrote no audit row');
    }

    public function test_a_second_rejection_answers_409_and_the_first_stands(): void
    {
        $registration = $this->registration();
        $reviewer = User::factory()->create();
        $first = ['status' => 'rejected', 'rejected_by' => $reviewer->id, 'rejected_at' => '2026-11-25 09:00:00', 'rejection_reason' => 'The ID does not match.'];
        $landed = $this->atTheRegistrationLock($registration, fn () => $this->other()->table('borrowers')->where('id', $registration->id)->update($first));
        $audit = (int) AuditLog::query()->max('id');

        $this->reject($registration, 'A second reason.')->assertStatus(409)->assertExactJson(['message' => self::DECIDED]);

        $this->assertTrue($landed(), 'the first rejection landed first');
        $this->assertEquals($first, (array) DB::table('borrowers')->where('id', $registration->id)->first(array_keys($first)), 'the first rejection stands');
        $this->assertSame(0, AuditLog::query()->where('id', '>', $audit)->count());
    }

    public function test_a_registration_moved_to_another_branch_while_this_rejection_waited_answers_409(): void
    {
        $registration = $this->registration();
        $elsewhere = Branch::factory()->create(['name' => 'Town Branch', 'code' => 'TWN']);
        $landed = $this->atTheRegistrationLock($registration, fn () => $this->other()->table('borrowers')->where('id', $registration->id)->update(['branch_id' => $elsewhere->id]));

        $this->reject($registration)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue($landed(), 'the edit landed first');
        $fresh = $registration->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->rejected_by);
        $this->assertTrue(ShareCapitalPledge::query()->where('borrower_id', $registration->id)->exists());
    }

    /**
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    #[DataProvider('concurrencyErrors')]
    public function test_a_rejection_the_database_rolls_back_answers_409_and_writes_nothing(array $errorInfo): void
    {
        $registration = $this->registration();
        $audit = (int) AuditLog::query()->max('id');
        $this->failOn(fn (string $sql, array $bindings): bool => str_starts_with($sql, 'update `borrowers`') && in_array('rejected', $bindings, true), $errorInfo);

        $this->reject($registration)->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $fresh = $registration->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->rejected_by);
        $this->assertTrue(ShareCapitalPledge::query()->where('borrower_id', $registration->id)->exists());
        $this->assertSame(0, AuditLog::query()->where('id', '>', $audit)->count());
    }

    public function test_a_review_locks_the_registration_first_and_an_approval_then_takes_the_lock_a_staff_creates_duplicate_check_takes(): void
    {
        $registration = $this->registration();
        $rejected = $this->registration(['email' => 'second.applicant@example.com']);

        $approval = $this->lockingReads(fn () => $this->approve($registration)->assertOk()->assertJsonPath('data.status', 'active'));
        $rejection = $this->lockingReads(fn () => $this->reject($rejected)->assertOk()->assertJsonPath('data.status', 'rejected'));
        $create = $this->lockingReads(fn () => $this->postJson('/api/borrowers', $this->staffCreate())->assertCreated());

        $this->assertGreaterThanOrEqual(2, count($approval), 'the approval takes two locks');
        $this->assertSame([$registration->id], $this->lockedIds($approval[0]), 'the registration\'s own row first');
        $this->assertNotEmpty($create);
        $this->assertSame($create[0]['sql'], $approval[1]['sql'], 'then the very lock a staff create\'s duplicate check takes');

        $this->assertNotEmpty($rejection, 'the rejection takes a lock');
        $this->assertSame([$rejected->id], $this->lockedIds($rejection[0]), 'a rejection takes the registration\'s own row first too');
        $this->assertSame($approval[0]['sql'], $rejection[0]['sql'], 'by the same statement, so the two queue on it');
    }

    private function registration(array $overrides = []): Borrower
    {
        $registration = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
            'email' => 'applicant@example.com',
            ...$overrides,
        ]);

        $registration->documents()->create([
            'type' => 'valid_id',
            'label' => 'umid',
            'file_path' => 'documents/valid_id/test.jpg',
            'original_filename' => 'test.jpg',
        ]);

        return $registration;
    }

    /**
     * @return array<string, mixed>
     */
    private function staffCreate(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'first_name' => 'Teodoro',
            'last_name' => 'Villanueva',
            'birthdate' => '1985-02-03',
            'gender' => 'male',
            'civil_status' => 'single',
            'contact_number' => '09171230000',
            'address' => '45 Narra St',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
            'email' => 'teodoro@example.com',
        ];
    }

    private function approve(Borrower $registration): TestResponse
    {
        return $this->patchJson("/api/borrowers/{$registration->id}/approve-registration");
    }

    private function reject(Borrower $registration, string $reason = 'The applicant asked to withdraw.'): TestResponse
    {
        return $this->patchJson("/api/borrowers/{$registration->id}/reject", ['reason' => $reason]);
    }

    private function other(): Connection
    {
        return DB::connection(self::OTHER_CONNECTION);
    }

    /**
     * Run `$change` on the other connection, where it commits at once, right
     * before the review's first lock or write on the registration's row (its
     * lock, where there is one): another reviewer's decision, an edit or a
     * staff create landing between the review's checks and its write.
     *
     * @return Closure(): bool whether it landed
     */
    private function atTheRegistrationLock(Borrower $registration, Closure $change): Closure
    {
        $landed = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$landed, $registration, $change): void {
            $locksOrWritesTheRow = (str_contains($query, 'from `borrowers`') && str_contains($query, 'for update'))
                || str_starts_with($query, 'update `borrowers`');

            if ($landed
                || ! $locksOrWritesTheRow
                || ! in_array((string) $registration->id, array_map('strval', $bindings), true)) {
                return;
            }

            $landed = true;
            $change();
        });

        return function () use (&$landed): bool {
            return $landed;
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
     * Every locking read on `borrowers` inside the transactions `$act` opens,
     * in order.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function lockingReads(Closure $act): array
    {
        $state = (object) ['open' => true, 'recording' => false, 'reads' => []];

        Event::listen(TransactionBeginning::class, function () use ($state): void {
            $state->recording = $state->open;
        });

        DB::listen(function (QueryExecuted $query) use ($state): void {
            $sql = strtolower($query->sql);

            if ($state->open && $state->recording && str_contains($sql, 'from `borrowers`') && str_contains($sql, 'for update')) {
                $state->reads[] = ['sql' => $sql, 'bindings' => $query->bindings];
            }
        });

        $act();
        $state->open = false;

        return $state->reads;
    }

    /**
     * The ids a `whereKey()` lock names.
     *
     * @param  array{sql: string, bindings: array<int, mixed>}  $read
     * @return list<int>
     */
    private function lockedIds(array $read): array
    {
        $bindings = $read['bindings'];
        $sql = (string) preg_replace_callback('/\?/', function () use (&$bindings): string {
            return (string) array_shift($bindings);
        }, $read['sql']);

        preg_match('/`id` = (\d+)/', $sql, $match);

        return isset($match[1]) ? [(int) $match[1]] : [];
    }
}
