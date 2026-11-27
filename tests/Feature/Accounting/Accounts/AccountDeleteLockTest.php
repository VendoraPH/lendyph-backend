<?php

namespace Tests\Feature\Accounting\Accounts;

use App\Models\AccountingAccount;
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
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The account delete locks the account row itself right after the opening
 * balance draft, before its reference checks, so a first reference to the
 * account (a journal line, a role mapping, a sub-account) waits its turn
 * instead of deadlocking against the DELETE. A deadlock or lock wait that
 * still happens answers 409 in words, never 500, and deletes nothing.
 */
class AccountDeleteLockTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /**
     * Opted out of the per-test transaction, as LoanApprovalCollisionTest is:
     * inside one, the delete's own transaction is a savepoint that Laravel
     * does not roll back on a deadlock.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    private const CONFLICT = 'Someone else changed the chart of accounts at the same time. Reload and try again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_the_account_row_is_locked_after_the_draft_and_before_the_reference_checks(): void
    {
        $id = $this->account('1420');
        $queries = $this->transactionQueries(fn () => $this->deleteJson("/api/accounting/accounts/{$id}")->assertOk());

        $draft = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'from `accounting_opening_balances`') && str_contains($sql, 'for update'));
        $row = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'from `accounting_accounts` where `accounting_accounts`.`id` = ?') && str_contains($sql, 'for update'));
        $firstCheck = $this->firstIndex($queries, fn (string $sql): bool => str_contains($sql, 'from `accounting_accounts` where `accounting_accounts`.`parent_id`'));

        $this->assertNotNull($draft);
        $this->assertNotNull($row, 'The account row is locked for update.');
        $this->assertGreaterThan($draft, $row);
        $this->assertLessThan($firstCheck, $row);
        $this->assertSame([$id], $queries[$row]['bindings']);
    }

    public function test_a_delete_that_deadlocks_answers_409_and_deletes_nothing(): void
    {
        $id = $this->account('1420');
        $this->failOn(fn (string $sql): bool => str_starts_with($sql, 'delete from `accounting_accounts`'), self::DEADLOCK);

        $this->deleteJson("/api/accounting/accounts/{$id}")->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue(AccountingAccount::query()->whereKey($id)->exists());
    }

    public function test_a_delete_whose_row_lock_times_out_answers_409_and_deletes_nothing(): void
    {
        $id = $this->account('1420');
        $this->failOn(
            fn (string $sql): bool => str_contains($sql, 'from `accounting_accounts` where `accounting_accounts`.`id` = ?') && str_contains($sql, 'for update'),
            self::LOCK_WAIT_TIMEOUT,
        );

        $this->deleteJson("/api/accounting/accounts/{$id}")->assertStatus(409)->assertExactJson(['message' => self::CONFLICT]);

        $this->assertTrue(AccountingAccount::query()->whereKey($id)->exists());
    }

    /**
     * The first query `$matches` fails with `$errorInfo`, as MySQL would.
     *
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function failOn(Closure $matches, array $errorInfo): void
    {
        $fired = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $matches, $errorInfo): void {
            if ($fired || ! $matches($query)) {
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
     * Every query issued inside the transaction `$act` opens, in order.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function transactionQueries(callable $act): array
    {
        $recording = false;
        $queries = [];

        Event::listen(TransactionBeginning::class, function () use (&$recording): void {
            $recording = true;
        });

        DB::listen(function (QueryExecuted $query) use (&$recording, &$queries): void {
            if ($recording) {
                $queries[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
            }
        });

        $act();

        return $queries;
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $queries
     */
    private function firstIndex(array $queries, Closure $matches): ?int
    {
        foreach ($queries as $index => $query) {
            if ($matches($query['sql'])) {
                return $index;
            }
        }

        return null;
    }
}
