<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\SavingsApproval;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\ApprovalGate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Two approved payouts of one placement, carried out on two real connections
 * at once (review M1). Each executor takes the placement's row lock itself
 * and settles only while the row is still open, so exactly one pays out,
 * even when the approvals desk did not lock the placement first.
 *
 * Without the lock, the first payout read the placement from its snapshot,
 * the second settled and committed in the meantime, and the first was only
 * stopped by the settlement journal's unique index (a 500), not by the rule.
 */
class TimeDepositPayoutRaceTest extends TestCase
{
    use TimeDepositFixtures;

    /** The second connection can only see rows the first has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'td_payout_race';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    public function test_two_approved_payouts_racing_on_one_placement_pay_exactly_once(): void
    {
        $deposit = TimeDeposit::query()->findOrFail($this->place()->assertCreated()->json('data.id'));
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));

        $gate = app(ApprovalGate::class);
        $payload = ['date' => '2027-02-08', 'action' => 'payout', 'payout_method' => 'cash'];
        $first = $gate->open('td_payout', 10_073_973, ['time_deposit_id' => $deposit->id, 'payload' => $payload], $this->admin->id);
        $second = $gate->open('td_payout', 10_073_973, ['time_deposit_id' => $deposit->id, 'payload' => $payload], $this->admin->id);
        $checkerA = User::factory()->create(['branch_id' => $this->branch->id]);
        $checkerB = User::factory()->create(['branch_id' => $this->branch->id]);

        $otherOutcome = null;
        $default = DB::getDefaultConnection();

        // The moment the first payout reads the placement, the second runs on
        // the other connection, as a second checker's request would.
        DB::listen(function (QueryExecuted $query) use (&$otherOutcome, $default, $second, $checkerB): void {
            if ($otherOutcome !== null
                || $query->connectionName !== $default
                || DB::connection($default)->transactionLevel() === 0
                || ! str_contains(strtolower($query->sql), 'from `time_deposits`')) {
                return;
            }

            $otherOutcome = $this->executeOnTheOtherConnection($second->id, $checkerB);
        });

        $firstOutcome = $this->outcome(fn () => DB::transaction(
            fn () => $gate->executor('td_payout')->execute(SavingsApproval::query()->findOrFail($first->id), $checkerA),
        ));

        $this->assertNotNull($otherOutcome, 'the second payout never ran while the first was open, so this test proved nothing');
        $this->assertSame(1, collect([$firstOutcome, $otherOutcome])->filter(fn ($o) => $o === 'paid')->count(), "first: {$firstOutcome}, second: {$otherOutcome}");

        // The placement's own row lock decides it: the payout that read it
        // first pays, and the other waits behind the lock and is refused,
        // never stopped only by the journal's unique index.
        $this->assertSame('paid', $firstOutcome, "second: {$otherOutcome}");
        $this->assertStringStartsWith('refused', $otherOutcome);

        $deposit->refresh();
        $this->assertSame('withdrawn', $deposit->status);
        $this->assertSame(1, AccountingJournal::query()->where('postable_type', TimeDeposit::class)
            ->where('postable_id', $deposit->id)->where('source', 'time_deposit_settlement')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'time_deposit_settled')->where('auditable_id', $deposit->id)->count());
    }

    private function executeOnTheOtherConnection(int $approvalId, User $checker): string
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::OTHER_CONNECTION);

        try {
            return $this->outcome(fn () => DB::transaction(
                fn () => app(ApprovalGate::class)->executor('td_payout')->execute(SavingsApproval::query()->findOrFail($approvalId), $checker),
            ));
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /** `paid`, or how it was refused. */
    private function outcome(callable $payout): string
    {
        try {
            $payout();

            return 'paid';
        } catch (Throwable $e) {
            return 'refused: '.class_basename($e);
        }
    }
}
