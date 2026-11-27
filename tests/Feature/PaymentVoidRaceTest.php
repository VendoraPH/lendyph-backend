<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A payment and a void of an earlier payment on the same loan, on two real
 * connections at once.
 *
 * Not a same-connection simulation: the void runs on a second connection to
 * the same database, in its own transaction, while the payment's transaction
 * is open on the default one. That is the only way to show what the payment's
 * REPEATABLE READ snapshot does with a write committed after it was taken.
 *
 * Without the payment's loan lock the payment fixed its snapshot before the
 * void committed, then wrote each period it paid from that snapshot, putting
 * back on the voided payment's period the money the void had just taken off.
 * With the lock, the void waits behind the payment and is refused with a 409
 * (its lock wait times out after one second on this connection), or, had it
 * got there first, the payment would start from what the void left.
 */
class PaymentVoidRaceTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction: the second connection can only
     * see rows the first one has committed.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'payment_void_race';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    public function test_a_payment_racing_a_void_never_loses_the_voids_reversal(): void
    {
        $loan = $this->createReleasedLoan();
        $service = app(RepaymentService::class);

        // ₱1,800 interest and ₱3,200 principal on period 1, which stays partly
        // paid, so the payment below pays the same period.
        $earlier = $service->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $voidOutcome = null;
        $default = DB::getDefaultConnection();

        // The moment the payment's transaction first reads the schedule, the
        // void of the earlier payment runs on the other connection.
        DB::listen(function (QueryExecuted $query) use (&$voidOutcome, $default, $earlier): void {
            $sql = strtolower($query->sql);

            if ($voidOutcome !== null
                || $query->connectionName !== $default
                || DB::connection($default)->transactionLevel() === 0
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'from `amortization_schedules`')
                || str_contains($sql, 'for update')) {
                return;
            }

            $voidOutcome = $this->voidOnTheOtherConnection($earlier->id);
        });

        $later = $service->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $this->assertNotNull($voidOutcome, 'the void never ran while the payment was open, so this test proved nothing');
        $this->assertSame('posted', $later->fresh()->status);

        if ($voidOutcome === 'voided') {
            // The void landed first: the payment must have started from it.
            $this->assertSame('voided', $earlier->fresh()->status);
        } else {
            $this->assertSame('409', $voidOutcome);
            $this->assertSame('posted', $earlier->fresh()->status);
        }

        // Never "voided but its reversal overwritten": each period carries
        // exactly what the payments still posted put on it.
        foreach (AmortizationSchedule::where('loan_id', $loan->id)->get() as $schedule) {
            $posted = DB::table('repayment_allocations')
                ->join('repayments', 'repayments.id', '=', 'repayment_allocations.repayment_id')
                ->where('repayments.status', 'posted')
                ->where('repayment_allocations.amortization_schedule_id', $schedule->id)
                ->selectRaw('COALESCE(SUM(repayment_allocations.principal), 0) as principal, COALESCE(SUM(repayment_allocations.interest), 0) as interest')
                ->first();

            $this->assertEqualsWithDelta((float) $posted->principal, (float) $schedule->principal_paid, 0.001, "period {$schedule->period_number} principal");
            $this->assertEqualsWithDelta((float) $posted->interest, (float) $schedule->interest_paid, 0.001, "period {$schedule->period_number} interest");
        }

        $this->assertSame(['ongoing'], Loan::whereKey($loan->id)->pluck('status')->all());
    }

    /**
     * Void the payment on the other connection, as another request would,
     * and say how it ended: `voided`, or the status code it was refused with.
     */
    private function voidOnTheOtherConnection(int $repaymentId): string
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::OTHER_CONNECTION);

        try {
            app(RepaymentService::class)->voidRepayment(Repayment::findOrFail($repaymentId), 'Keyed twice', $this->admin);

            return 'voided';
        } catch (HttpResponseException $e) {
            return (string) $e->getResponse()->getStatusCode();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
