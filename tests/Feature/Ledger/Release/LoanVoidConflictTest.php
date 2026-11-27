<?php

namespace Tests\Feature\Ledger\Release;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Voiding a loan while a release (or any other write) holds or has just
 * changed its row, on two real connections: 409 with nothing written, never
 * a void over a release or a 500 on the lock wait.
 */
class LoanVoidConflictTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    /**
     * Opted out of the per-test transaction: the other connection can only
     * see rows this one has committed.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'loan_void_race';

    private const CONFLICT = 'Another change to this loan was saved at the same time. Reload and try again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');

        parent::tearDown();
    }

    public function test_a_void_waiting_on_a_release_holding_the_loan_row_answers_409_and_writes_nothing(): void
    {
        $loan = $this->approvedLoan();
        $before = $this->releaseState();

        // A release in flight holds the loan's row.
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('loans')->where('id', $loan->id)->lockForUpdate()->first();

        try {
            $this->patchJson("/api/loans/{$loan->id}/void")
                ->assertStatus(409)
                ->assertExactJson(['message' => self::CONFLICT]);
        } finally {
            $other->rollBack();
        }

        $this->assertSame($before, $this->releaseState());
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_a_void_that_read_approved_before_a_release_committed_answers_409_and_the_loan_stays_released(): void
    {
        $loan = $this->approvedLoan();
        $stale = Loan::findOrFail($loan->id);

        // The release commits on the other connection after the void read the loan.
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->transaction(fn () => $other->table('loans')->where('id', $loan->id)->update(['status' => 'released']));
        $before = $this->releaseState();

        try {
            app(LoanService::class)->voidLoan($stale);
            $this->fail('Expected a 409.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame(['message' => self::CONFLICT], json_decode($e->getResponse()->getContent(), true));
        }

        $this->assertSame('released', $loan->fresh()->status);
        $this->assertSame($before, $this->releaseState());
        $this->assertSame(0, AuditLog::where('auditable_type', Loan::class)->where('auditable_id', $loan->id)->where('new_values->status', 'void')->count());
    }

    public function test_an_approved_loan_with_nothing_in_the_way_is_voided(): void
    {
        $loan = $this->approvedLoan();

        $this->patchJson("/api/loans/{$loan->id}/void")->assertOk()->assertJsonPath('data.status', 'void');

        $this->assertSame('void', $loan->fresh()->status);
    }
}
