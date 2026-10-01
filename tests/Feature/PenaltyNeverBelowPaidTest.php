<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Recalculating a period's penalty never takes the charge below what was
 * already paid toward it. How the charge itself is worked out is unchanged.
 *
 * The loan: ₱60,000 over six months at 3% straight (₱10,000 principal and
 * ₱1,800 interest a period), 2% penalty, 3 days grace, started 2026-01-15, so
 * period 1 falls due 2026-02-15 and is late from 2026-02-19.
 */
class PenaltyNeverBelowPaidTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);

        // Late: ₱200 penalty (2% of ₱10,000), then ₱1,800 interest and ₱3,000 principal.
        $this->payOn('2026-02-20', 5000);
    }

    public function test_the_nightly_run_keeps_the_penalty_at_what_was_paid(): void
    {
        $this->travelTo(Carbon::parse('2026-02-21 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        // 2% of the ₱7,000 left would be ₱140, below the ₱200 already paid.
        $this->assertPenalty(charged: 200, paid: 200);
    }

    public function test_the_next_payment_keeps_the_penalty_at_what_was_paid(): void
    {
        $this->payOn('2026-02-22', 1000);

        $this->assertPenalty(charged: 200, paid: 200);
    }

    public function test_a_higher_recalculated_penalty_still_applies(): void
    {
        $schedule = $this->period1();
        $schedule->update(['penalty_amount' => 100, 'penalty_paid' => 100]);

        $this->travelTo(Carbon::parse('2026-02-21 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        // 2% of ₱7,000 is ₱140, above the ₱100 paid, so it is charged as before.
        $this->assertPenalty(charged: 140, paid: 100);
    }

    private function payOn(string $date, float $amount): void
    {
        $this->travelTo(Carbon::parse("{$date} 09:00"));

        $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertCreated();
    }

    private function period1(): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $this->loan->id)->where('period_number', 1)->sole();
    }

    private function assertPenalty(float $charged, float $paid): void
    {
        $schedule = $this->period1();

        $this->assertEqualsWithDelta($charged, (float) $schedule->penalty_amount, 0.001, 'penalty charged');
        $this->assertEqualsWithDelta($paid, (float) $schedule->penalty_paid, 0.001, 'penalty paid');
    }
}
