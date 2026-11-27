<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 7. Dashboard daily dues list a loan once per date. A restructure
 * adjustment closes a partly paid period at what it collected and starts the
 * rescheduled remainder on the same date; today both rows are listed, the
 * closed one as "collected" next to the remainder as "pending".
 *
 * ₱60,000 over six at 3% straight from 2026-01-15; periods 1 and 2 paid in
 * full, period 3 (due 2026-04-15) paid ₱5,000, then a restructure over six.
 */
class DailyDuesClosedPeriodTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function payOn(string $date, float $amount): void
    {
        $this->travelTo(Carbon::parse("{$date} 09:00"));
        $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertCreated();
    }

    public function test_a_closed_period_and_its_rescheduled_remainder_are_one_due(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $this->payOn('2026-02-10', 11800);
        $this->payOn('2026-03-10', 11800);
        $this->payOn('2026-04-10', 5000);

        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 6],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        // Setup: two rows of this loan now fall due on 2026-04-15.
        $this->assertSame(2, AmortizationSchedule::where('loan_id', $this->loan->id)->whereDate('due_date', '2026-04-15')->count());

        $items = collect($this->getJson('/api/dashboard/daily-dues?date=2026-04-15')->assertOk()->json('data'))
            ->where('loan_id', $this->loan->id);

        $this->assertCount(1, $items, 'the loan is listed once on 2026-04-15');
    }
}
