<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Voiding a payment reverses the periods that payment paid, and no others.
 *
 * The loan: ₱60,000 over six months at 3% straight (₱10,000 principal and
 * ₱1,800 interest a period), 2% penalty, 3 days grace, started 2026-01-15, so
 * period n falls due on the 15th of the month after it.
 */
class VoidRepaymentPeriodsTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
    }

    public function test_voiding_the_payment_for_period_3_reverses_period_3_only(): void
    {
        $this->payOn('2026-02-10', 11800);
        $this->payOn('2026-03-10', 11800);
        $third = $this->payOn('2026-04-10', 11800);

        $this->void($third);

        $this->assertPeriod(1, interest: 1800, principal: 10000, status: 'paid');
        $this->assertPeriod(2, interest: 1800, principal: 10000, status: 'paid');
        $this->assertPeriod(3, interest: 0, principal: 0, status: 'pending');
        $this->assertPaymentsReconcile();
    }

    public function test_voiding_a_payment_that_paid_later_periods_keeps_the_earlier_payment(): void
    {
        // ₱1,800 interest and ₱3,200 principal on period 1.
        $this->payOn('2026-02-10', 5000);
        // The other ₱6,800 of period 1, then all of period 2.
        $second = $this->payOn('2026-02-12', 18600);

        $this->void($second);

        $this->assertPeriod(1, interest: 1800, principal: 3200, status: 'partial');
        $this->assertPeriod(2, interest: 0, principal: 0, status: 'pending');
        $this->assertPaymentsReconcile();
    }

    public function test_each_payment_records_what_it_paid_on_each_period(): void
    {
        $first = $this->payOn('2026-02-10', 5000);
        $second = $this->payOn('2026-02-12', 18600);

        $this->assertSame([
            [1, 0.0, 1800.0, 3200.0],
        ], $this->allocations($first));
        $this->assertSame([
            [1, 0.0, 0.0, 6800.0],
            [2, 0.0, 1800.0, 10000.0],
        ], $this->allocations($second));
    }

    public function test_voiding_a_late_payment_for_period_3_puts_back_its_penalty_there_only(): void
    {
        $this->payOn('2026-02-10', 11800);
        $this->payOn('2026-03-10', 11800);
        // Period 3 fell due on 2026-04-15 and is past its grace: ₱200 penalty first.
        $late = $this->payOn('2026-04-25', 12000);
        $this->assertSame([[3, 200.0, 1800.0, 10000.0]], $this->allocations($late));

        $this->void($late);

        $this->assertPeriod(1, interest: 1800, principal: 10000, status: 'paid');
        $this->assertPeriod(2, interest: 1800, principal: 10000, status: 'paid');
        $this->assertPeriod(3, interest: 0, principal: 0, status: 'overdue');
        $this->assertEqualsWithDelta(0, (float) $this->schedule(3)->penalty_paid, 0.001);
        $this->assertPaymentsReconcile();

        // The next run charges period 3 again, as for any late period.
        $this->travelTo(Carbon::parse('2026-04-26 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->assertEqualsWithDelta(200, (float) $this->schedule(3)->penalty_amount, 0.001);
    }

    public function test_a_payment_on_a_period_a_term_extension_replaced_cannot_be_voided(): void
    {
        $partial = $this->payOn('2026-02-10', 5000);

        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 1],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $this->patchJson("/api/repayments/{$partial->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.repayment.0', 'A period this payment paid has since been replaced by a restructure or an extension, so it cannot be voided. Use a balance adjustment to correct the loan instead.');
        $this->assertSame('posted', $partial->fresh()->status);
    }

    private function payOn(string $date, float $amount): Repayment
    {
        $this->travelTo(Carbon::parse("{$date} 09:00"));

        $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /**
     * @return list<array{int, float, float, float}> [period, penalty, interest, principal]
     */
    private function allocations(Repayment $repayment): array
    {
        return RepaymentAllocation::where('repayment_id', $repayment->id)
            ->orderBy('period_number')
            ->get()
            ->map(fn (RepaymentAllocation $a) => [$a->period_number, (float) $a->penalty, (float) $a->interest, (float) $a->principal])
            ->all();
    }

    private function schedule(int $period): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $this->loan->id)->where('period_number', $period)->sole();
    }

    private function void(Repayment $repayment): void
    {
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');
    }

    private function assertPeriod(int $period, float $interest, float $principal, string $status): void
    {
        $schedule = $this->schedule($period);

        $this->assertEqualsWithDelta($interest, (float) $schedule->interest_paid, 0.001, "period {$period} interest");
        $this->assertEqualsWithDelta($principal, (float) $schedule->principal_paid, 0.001, "period {$period} principal");
        $this->assertSame($status, $schedule->status, "period {$period} status");
    }

    /**
     * The posted receipts and the periods agree on every peso collected.
     */
    private function assertPaymentsReconcile(): void
    {
        $posted = Repayment::where('loan_id', $this->loan->id)->where('status', 'posted');
        $schedules = AmortizationSchedule::where('loan_id', $this->loan->id);

        foreach (['principal' => 'principal_paid', 'interest' => 'interest_paid', 'penalty' => 'penalty_paid'] as $component => $column) {
            $this->assertEqualsWithDelta(
                (float) (clone $posted)->sum("{$component}_applied"),
                (float) (clone $schedules)->sum($column),
                0.001,
                $component,
            );
        }
    }
}
