<?php

namespace Tests\Feature\Ledger\Release;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Sending an approved restructure back while another request holds its row
 * or its source's row, on two real connections: 409, never 500, with nothing
 * written. The restructure's row is locked first, then the source's.
 */
class RestructureSendBackApprovedConflictTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    /**
     * Opted out of the per-test transaction: the other connection can only
     * see rows this one has committed.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'restructure_send_back_race';

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

    public function test_a_send_back_waiting_on_the_restructure_row_answers_409_and_writes_nothing(): void
    {
        $new = $this->approvedRestructureOf($this->createReleasedLoan(), 50000, 'Board approved write-off');

        $this->assertWaitingOnRowAnswers409($new, $new->id);
    }

    public function test_a_send_back_waiting_on_the_source_row_answers_409_and_writes_nothing(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');

        $this->assertWaitingOnRowAnswers409($new, $source->id);
    }

    public function test_a_second_send_back_after_the_first_committed_answers_422_on_status(): void
    {
        $new = $this->approvedRestructureOf($this->createReleasedLoan(), 50000, 'Board approved write-off');

        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'First'])->assertOk();
        $after = $this->releaseState();

        // The second arrives once the first committed: the loan is in review now.
        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Second'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame($after, $this->releaseState());
        $this->assertSame(1, AuditLog::where('action', 'restructure_sent_back')->count());
    }

    private function assertWaitingOnRowAnswers409(Loan $new, int $heldLoanId): void
    {
        $bookkeeper = User::factory()->create(['branch_id' => $this->branch->id]);
        $bookkeeper->assignRole(Role::where('name', 'general_bookkeeper')->firstOrFail());
        $before = $this->releaseState();
        $notificationsBefore = DB::table('notifications')->count();

        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('loans')->where('id', $heldLoanId)->lockForUpdate()->first();

        try {
            $this->actingAs($bookkeeper)
                ->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'Figures changed'])
                ->assertStatus(409)
                ->assertExactJson(['message' => self::CONFLICT]);
        } finally {
            $other->rollBack();
        }

        $this->assertSame($before, $this->releaseState());
        $this->assertSame('approved', $new->fresh()->status);
        $this->assertSame(0, LoanApprovalStep::where('loan_id', $new->id)->where('status', LoanApprovalStep::STATUS_SENT_BACK)->count());
        $this->assertSame($notificationsBefore, DB::table('notifications')->count());
    }
}
