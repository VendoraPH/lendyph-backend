<?php

namespace Tests\Feature\CashReferences;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Services\GeneratedNumberWrite;
use App\Services\ShareCapitalService;
use Closure;
use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Cash reference numbers on two real connections (finish run round 2,
 * decision 5): no two saves ever take the same number.
 *
 * - Each share capital write takes the branch row lock first, then the
 *   closure rows, then its members' rows FOR SHARE (their branch read again),
 *   then its pledges FOR SHARE (their auto-credit switch, finish run round 7),
 *   then (for cash) its series row FOR UPDATE.
 * - A save waits for another one holding its series, and one that waits too
 *   long answers 409 with nothing saved and no number used.
 * - A save that another one beats to the series reads the number that one
 *   committed, and takes the next.
 * - The first number of a series that two saves start at once: the loser's
 *   deadlock is retried (one entry) or answered 409 (a bulk batch).
 */
class CashReferenceRaceTest extends TestCase
{
    use SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'cash_reference_race';

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER_CONNECTION)->rollBack(0);
        DB::purge(self::OTHER_CONNECTION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_each_write_takes_the_branch_row_lock_first_then_the_closure_rows_its_members_its_pledges_and_its_series(): void
    {
        // Auto-credit off for the entries by hand, which a pledge set to
        // auto-credit refuses (finish run round 7); on only for the run.
        $this->member->shareCapitalPledge->update(['auto_credit' => false, 'amount' => 500]);

        $writes = [
            'ledger entry' => [fn (): TestResponse => $this->ledgerEntry()->assertCreated(), true],
            'pledge entry' => [fn (): TestResponse => $this->postJson("/api/pledges/{$this->member->shareCapitalPledge->id}/entries", ['amount' => 250, 'type' => 'credit', 'date' => '2026-11-24'])->assertCreated(), true],
            'bulk entry' => [fn (): TestResponse => $this->postJson('/api/pledges/bulk-entries', ['entries' => [['pledge_id' => $this->member->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24']]])->assertCreated(), true],
            'gcash ledger entry' => [fn (): TestResponse => $this->ledgerEntry(['method' => 'gcash', 'reference_number' => 'GC-1'])->assertCreated(), false],
            'auto-credit run' => [function (): TestResponse {
                $this->member->shareCapitalPledge->update(['auto_credit' => true]);

                return $this->postJson('/api/auto-credit/process', ['cycle_date' => ShareCapitalService::currentCycleDate(), 'method' => 'cash'])->assertCreated();
            }, false],
        ];

        foreach ($writes as $name => [$write, $numbered]) {
            $statements = $this->statementsInsideTheWrite($write);

            $this->assertMatchesRegularExpression('/^select .* from `branches` where `id` in \(.*\) order by `id` asc lock in share mode$/', $statements[0], "{$name}: the branch rows are its first statement");
            $this->assertMatchesRegularExpression('/^select .* from `branch_closures` .* lock in share mode$/', $statements[1], "{$name}: then the closure rows");
            $this->assertMatchesRegularExpression('/^select .* from `borrowers` .* lock in share mode$/', $statements[2], "{$name}: then its members' branches again");

            // Each reads its pledges' auto-credit switch FOR SHARE next (finish run round 7).
            $this->assertMatchesRegularExpression('/^select .* from `share_capital_pledges` .* lock in share mode$/', $statements[3], "{$name}: then its pledges");

            if ($numbered) {
                $this->assertMatchesRegularExpression('/^select .* from `cash_reference_sequences` .* for update$/', $statements[4], "{$name}: then its series row");
            } else {
                $this->assertSame([], array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'cash_reference_sequences'))), "{$name}: takes no number");
            }
        }
    }

    public function test_a_cash_entry_waits_for_a_save_holding_its_series_and_never_takes_its_number(): void
    {
        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', $this->number(1));

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('cash_reference_sequences')->where('prefix', 'CSH')->lockForUpdate()->first();

        $this->ledgerEntry()->assertStatus(409)->assertExactJson(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT]);
        $this->assertSame(1, ShareCapitalLedger::query()->count());

        $other->rollBack();
        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', $this->number(2));
    }

    public function test_a_save_beaten_to_its_series_takes_the_number_after_the_one_committed(): void
    {
        $neighbour = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $interleaved = false;

        // Right before this save locks its series, another save commits the
        // first number on the other connection, through the same code.
        DB::connection()->beforeExecuting(function (string $query) use (&$interleaved, $neighbour): void {
            if ($interleaved || ! str_contains($query, 'from `cash_reference_sequences`') || ! str_contains($query, 'for update')) {
                return;
            }

            $interleaved = true;
            $this->onTheOtherConnection(fn () => app(ShareCapitalService::class)->createEntry($neighbour->id, '300.00', 'credit', '2026-11-24', $this->admin, 'Other counter'));
        });

        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', $this->number(2));

        $this->assertTrue($interleaved);
        $this->assertSame($this->number(1), ShareCapitalLedger::query()->where('borrower_id', $neighbour->id)->value('reference_number'));
        $this->assertSame(2, ShareCapitalLedger::query()->distinct()->count('reference_number'));
    }

    public function test_a_bulk_batch_beaten_to_its_series_numbers_on_from_the_other_saves_number(): void
    {
        $neighbour = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $second = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $interleaved = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$interleaved, $neighbour): void {
            if ($interleaved || ! str_contains($query, 'from `cash_reference_sequences`') || ! str_contains($query, 'for update')) {
                return;
            }

            $interleaved = true;
            $this->onTheOtherConnection(fn () => app(ShareCapitalService::class)->createEntry($neighbour->id, '300.00', 'credit', '2026-11-24', $this->admin, 'Other counter'));
        });

        $this->postJson('/api/pledges/bulk-entries', ['entries' => [
            ['pledge_id' => $this->member->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
            ['pledge_id' => $second->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
        ]])->assertCreated()->assertJsonPath('data.reference_numbers', [$this->number(2), $this->number(3)]);

        $this->assertTrue($interleaved);
        $this->assertSame(3, ShareCapitalLedger::query()->distinct()->count('reference_number'));
    }

    public function test_a_deadlock_starting_a_series_is_retried_for_one_entry_and_answered_409_for_a_bulk_batch(): void
    {
        $this->deadlockOnce('insert into `cash_reference_sequences`');
        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', $this->number(1));

        DB::table('cash_reference_sequences')->delete();
        ShareCapitalLedger::query()->delete();

        $this->deadlockOnce('insert into `cash_reference_sequences`');
        $this->postJson('/api/pledges/bulk-entries', ['entries' => [['pledge_id' => $this->member->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24']]])
            ->assertStatus(409)->assertExactJson(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT]);
        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    private function number(int $sequence): string
    {
        $id = DB::table('users')->where('status', 'active')
            ->whereIn('id', DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'super_admin')->select('model_has_roles.model_id'))
            ->min('id');

        return sprintf('CSH-%04d-2026-%04d', $id, $sequence);
    }

    private function ledgerEntry(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id,
            'date' => '2026-11-24',
            'description' => 'Contribution',
            'type' => 'credit',
            'amount' => 1000,
            ...$overrides,
        ]);
    }

    /** Run `$save` as another request would, on the other connection, committed. */
    private function onTheOtherConnection(Closure $save): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::OTHER_CONNECTION);

        try {
            $save();
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /**
     * The SQL run inside the write's own transaction, in order: the request's
     * validation reads run outside it.
     *
     * @return list<string>
     */
    private function statementsInsideTheWrite(Closure $write): array
    {
        $statements = [];
        $recording = true;

        DB::listen(function (QueryExecuted $query) use (&$statements, &$recording): void {
            if ($recording && $query->connectionName !== self::OTHER_CONNECTION && $query->connection->transactionLevel() > 0) {
                $statements[] = $query->sql;
            }
        });

        $write();
        $recording = false;

        return $statements;
    }

    private function deadlockOnce(string $statementStart): void
    {
        $fired = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $statementStart): void {
            if ($fired || ! str_starts_with($query, $statementStart)) {
                return;
            }

            $fired = true;
            $errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            $pdo = new PDOException("SQLSTATE[{$errorInfo[0]}]: General error: {$errorInfo[1]} {$errorInfo[2]}");
            $pdo->errorInfo = $errorInfo;
            (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $errorInfo[0]);

            throw new QueryException(DB::connection()->getName(), $query, $bindings, $pdo);
        });
    }
}
