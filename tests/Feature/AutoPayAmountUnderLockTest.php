<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Auto-pay works out each loan's amount after taking the loan's lock, as a
 * payment's own allocation does. A payment that lands between auto-pay
 * reading the periods and locking the loan is then seen, so auto-pay never
 * charges the stale full amount on top of it (which spilled into future
 * periods or share capital).
 */
class AutoPayAmountUnderLockTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_period_paid_between_the_read_and_the_lock_is_not_charged_again(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $slippedIn = $this->atTheLoanLock(function () use ($period): void {
            DB::table('amortization_schedules')->where('id', $period->id)->update([
                'principal_paid' => DB::raw('principal_due'),
                'interest_paid' => DB::raw('interest_due'),
                'status' => 'paid',
            ]);
        });

        $response = $this->processFor($period)->assertCreated();

        $this->assertTrue($slippedIn(), 'the other payment never landed, so this test proved nothing');
        $this->assertSame(0, $response->json('data.processed'));
        $this->assertSame(1, $response->json('data.skipped'));
        $this->assertSame([[
            'loan_id' => $loan->id,
            'reason' => 'Nothing was due any more once the loan was locked: another payment settled it.',
        ]], $response->json('data.skips'));
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertSame(0, AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', '>', 1)->sum('principal_paid') + 0);
    }

    public function test_a_period_part_paid_between_the_read_and_the_lock_is_charged_only_what_is_left(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $this->atTheLoanLock(function () use ($period): void {
            DB::table('amortization_schedules')->where('id', $period->id)->update([
                'interest_paid' => DB::raw('interest_due'),
                'status' => 'partial',
            ]);
        });

        $response = $this->postJson('/api/auto-pay/process', [
            'date_from' => $period->due_date->toDateString(),
            'date_to' => $period->due_date->toDateString(),
            'include_schedule_ids' => [$period->id],
        ])->assertCreated();

        // ₱11,800 due, ₱1,800 of it paid in between: ₱10,000 left.
        $this->assertSame(1, $response->json('data.processed'));
        $this->assertEquals(10000, $response->json('data.repayments.0.amount_paid'));
        $this->assertSame(0, AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', '>', 1)->sum('principal_paid') + 0);
    }

    public function test_without_a_competing_payment_the_full_amount_is_charged(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $response = $this->processFor($period)->assertCreated();

        $this->assertSame(1, $response->json('data.processed'));
        $this->assertSame([], $response->json('data.skips'));
        $this->assertEquals(11800, $response->json('data.repayments.0.amount_paid'));
    }

    private function autoPayLoan(): Loan
    {
        $loan = $this->createReleasedLoan();
        $loan->update([
            'auto_pay' => true,
            'cbs_reference' => 'CBS-2026-00001',
            'auto_pay_enabled_at' => now(),
            'auto_pay_enabled_by' => $this->admin->id,
        ]);

        return $loan->fresh('amortizationSchedules');
    }

    private function processFor(AmortizationSchedule $period): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auto-pay/process', [
            'date_from' => $period->due_date->toDateString(),
            'date_to' => $period->due_date->toDateString(),
        ]);
    }

    /**
     * Run `$change` right after the first locking read of `loans`: a payment
     * committing between auto-pay's read of the periods and its lock.
     *
     * @return Closure(): bool
     */
    private function atTheLoanLock(Closure $change): Closure
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $change): void {
            $sql = strtolower($query->sql);

            if ($fired || ! str_contains($sql, 'from `loans`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $fired = true;
            $change();
        });

        return function () use (&$fired): bool {
            return $fired;
        };
    }
}
