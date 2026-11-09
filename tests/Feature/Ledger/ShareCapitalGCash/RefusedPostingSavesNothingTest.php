<?php

namespace Tests\Feature\Ledger\ShareCapitalGCash;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\ShareCapitalLedger;
use App\Services\GCashService;
use Closure;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A posting the books refuse, or a lock collision, leaves nothing behind.
 *
 * Outside the suite's wrapping transaction, so each action's own transaction
 * is the outermost one and really commits or rolls back: inside the wrapper
 * GeneratedNumberWrite::run() skips its own transaction and a rollback here
 * would prove nothing.
 *
 * The refusal is an unmapped settlement role (`gcash`): the row is written,
 * the journal is refused with a 422 on `errors.accounting`, and the row goes
 * with it.
 */
class RefusedPostingSavesNothingTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /** The actions must open and close their own transactions. */
    protected bool $wrapsEachTestInTransaction = false;

    private const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    private const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    private function unmapTheGCashWallet(): void
    {
        AccountingAccountMapping::query()->where('role', 'gcash')->delete();
    }

    private function journals(): int
    {
        return AccountingJournal::count();
    }

    public function test_a_refused_single_entry_saves_no_row_and_no_journal(): void
    {
        $this->unmapTheGCashWallet();

        $this->postJson("/api/pledges/{$this->member->shareCapitalPledge->id}/entries", [
            'amount' => 100, 'type' => 'credit', 'date' => now()->toDateString(), 'method' => 'gcash', 'reference_number' => 'GC-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('accounting');

        $this->assertSame(0, ShareCapitalLedger::count());
        $this->assertSame(0, $this->journals());
    }

    public function test_a_refused_ledger_endpoint_entry_saves_no_row_and_no_journal(): void
    {
        $this->unmapTheGCashWallet();

        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id, 'date' => now()->toDateString(), 'description' => 'Contribution',
            'type' => 'credit', 'amount' => 100, 'method' => 'gcash', 'reference_number' => 'GC-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('accounting');

        $this->assertSame(0, ShareCapitalLedger::count());
        $this->assertSame(0, $this->journals());
    }

    public function test_a_refused_gcash_create_saves_no_transaction_and_no_journal(): void
    {
        $this->unmapTheGCashWallet();
        $audits = AuditLog::count();

        $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_out', 'amount' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('accounting');

        $this->assertSame(0, GCashTransaction::count());
        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(0, $this->journals());
    }

    public function test_a_refused_mark_paid_leaves_the_cash_in_pending_and_posts_nothing(): void
    {
        $id = $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_in', 'amount' => 1000, 'is_pending' => true])
            ->assertCreated()->json('data.id');
        $this->unmapTheGCashWallet();
        $audits = AuditLog::count();

        $this->patchJson("/api/gcash/transactions/{$id}/paid")
            ->assertUnprocessable()->assertJsonValidationErrors('accounting');

        $tx = GCashTransaction::findOrFail($id);
        $this->assertSame('pending', $tx->status);
        $this->assertNull($tx->paid_at);
        $this->assertNull($tx->paid_by_user_id);
        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(0, $this->journals());
    }

    /**
     * An entry dated in a closed accounting period is refused by the books'
     * period guard (PeriodGuard): 422 on `errors.date`, not
     * `errors.accounting`, and nothing is saved.
     */
    public function test_an_entry_dated_in_a_closed_period_is_refused_by_the_books(): void
    {
        // Periods are provisioned from the first journal's month.
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-10-01']);
        $this->getJson('/api/accounting/periods?per_page=100')->assertOk();
        $october = AccountingPeriod::where('code', '2026-10')->sole();
        $this->postJson("/api/accounting/periods/{$october->id}/close")->assertOk();

        $this->postJson("/api/pledges/{$this->member->shareCapitalPledge->id}/entries", ['amount' => 100, 'type' => 'credit', 'date' => '2026-10-15'])
            ->assertUnprocessable()->assertJsonValidationErrors('date')->assertJsonMissingValidationErrors('accounting');

        $this->assertSame(0, ShareCapitalLedger::count());
        $this->assertSame(0, AccountingJournal::query()->where('postable_type', ShareCapitalLedger::class)->count());
    }

    // ── Lock collisions ──

    public function test_a_gcash_create_that_deadlocks_answers_409_and_saves_nothing(): void
    {
        $fired = $this->failOn(fn (string $sql): bool => str_starts_with($sql, 'insert into `gcash_transactions`'), self::DEADLOCK);

        $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_out', 'amount' => 1000])
            ->assertStatus(409)->assertExactJson(['message' => GCashService::CREATE_CONFLICT]);

        $this->assertSame(1, $fired());
        $this->assertSame(0, GCashTransaction::count());
        $this->assertSame(0, $this->journals());
    }

    public function test_a_mark_paid_that_times_out_on_its_lock_answers_409_and_leaves_it_pending(): void
    {
        $id = $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_in', 'amount' => 1000, 'is_pending' => true])
            ->assertCreated()->json('data.id');
        $fired = $this->failOn(fn (string $sql): bool => str_contains($sql, 'from `gcash_transactions`') && str_ends_with($sql, 'for update'), self::LOCK_WAIT_TIMEOUT);

        $this->patchJson("/api/gcash/transactions/{$id}/paid")
            ->assertStatus(409)->assertExactJson(['message' => GCashService::MARK_PAID_CONFLICT]);

        $this->assertSame(1, $fired());
        $this->assertSame('pending', GCashTransaction::findOrFail($id)->status);
        $this->assertSame(0, $this->journals());
    }

    /**
     * Throws the given driver error, once, on the first query `$matches`
     * accepts.
     *
     * @param  Closure(string): bool  $matches
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function failOn(Closure $matches, array $errorInfo): Closure
    {
        $fired = 0;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fired, $matches, $errorInfo): void {
            if ($fired > 0 || ! $matches($query)) {
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
