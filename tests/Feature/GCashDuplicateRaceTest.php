<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Services\GCashService;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The GCash duplicate check runs under the reference lock (Lead's call 5), on
 * two real connections: an identical create can't commit between this
 * create's duplicate check and its own insert, so two identical creates are
 * never both saved.
 *
 * Before, the check was a plain read taken before the reference lock: the
 * other create committed after the check had passed, the reference lock then
 * read past it, and both rows were saved.
 */
class GCashDuplicateRaceTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction: the other connection can only
     * see rows this one has committed.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'gcash_duplicate_race';

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    public function test_an_identical_create_cannot_commit_between_the_duplicate_check_and_the_insert(): void
    {
        $prefix = 'GC-'.now()->format('Ymd').'-';
        $last = GCashTransaction::where('reference_no', 'like', $prefix.'%')->max('reference_no');
        $otherReference = $prefix.str_pad((string) ($last === null ? 1 : (int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);

        $checked = false;
        $otherSaved = null;

        DB::listen(function (QueryExecuted $query) use (&$checked, &$otherSaved, $otherReference): void {
            if ($checked || $query->connectionName === self::OTHER_CONNECTION
                || ! str_contains($query->sql, 'gcash_transactions') || ! str_contains($query->sql, 'transaction_date')) {
                return;
            }

            $checked = true;

            // The other create, for the same member, type and amount, commits now.
            $other = DB::connection(self::OTHER_CONNECTION);

            try {
                $other->transaction(fn () => $other->table('gcash_transactions')->insert([
                    'reference_no' => $otherReference,
                    'transaction_date' => now(),
                    'type' => 'cash_in',
                    'amount' => 1000,
                    'charge_amount' => 20,
                    'total_amount' => 1020,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'borrower_id' => $this->member->id,
                    'transactor_user_id' => $this->admin->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $otherSaved = true;
            } catch (QueryException) {
                $otherSaved = false;
            }
        });

        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->member->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ]);

        $this->assertTrue($checked, 'the duplicate check ran');
        $this->assertNotNull($otherSaved);
        $this->assertSame(1, GCashTransaction::query()
            ->where('borrower_id', $this->member->id)
            ->where('type', 'cash_in')
            ->where('amount', 1000)
            ->count(), 'two identical creates were both saved');
    }

    /**
     * Two same-day creates can still deadlock, as before (on a day with no
     * GCash row yet, or on the duplicate check, now a locking read). The one
     * rolled back answers the worded 409 and saves nothing, so nothing is
     * saved twice.
     */
    public function test_a_deadlock_on_the_duplicate_check_answers_the_worded_409_and_saves_nothing(): void
    {
        $fired = 0;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired): void {
            if ($fired > 0 || ! str_contains($query, 'transaction_date') || ! str_contains($query, 'for update')) {
                return;
            }

            $fired++;
            $errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            $pdo = new PDOException("SQLSTATE[{$errorInfo[0]}]: General error: {$errorInfo[1]} {$errorInfo[2]}");
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $errorInfo[0]);

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });
        $before = GCashTransaction::count();

        $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_in', 'amount' => 1000])
            ->assertStatus(409)
            ->assertExactJson(['message' => GCashService::CREATE_CONFLICT]);

        $this->assertSame(1, $fired);
        $this->assertSame($before, GCashTransaction::count());
    }
}
