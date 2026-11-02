<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The allocator works in floats. RepaymentService::allocateToSchedule()
 * subtracts each period's interest and principal from the money left as
 * floats, so a payment of exactly what the periods owe, in whole centavos,
 * can leave about 1e-12 behind. The loop then carries that dust on to the
 * next period: it writes a 0.00 / 0.00 / 0.00 allocation row there and marks
 * the payment as reaching a future period ('advance' instead of 'exact').
 *
 * ₱45,678.91 over 6 monthly periods at 3% straight: ₱7,613.15 principal and
 * ₱1,370.37 interest a period. 8,983.52 − 1,370.37 − 7,613.15 leaves
 * 9.09e-13 in floats.
 *
 * Paying preset n must leave periods 1..n paid and period n+1 untouched,
 * with no allocation row for it.
 */
class AllocatorCentavoRemainderTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    private const PRINCIPAL = 45678.91;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_paying_the_one_period_preset_on_its_due_date_is_exact_and_leaves_the_next_period_untouched(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-02-15');
        $this->travelTo(Carbon::parse('2026-03-15 09:00'));
        $this->assertSame('2026-03-15', $this->period($loan, 1)->due_date->toDateString(), 'setup: period 1 falls due today');

        $amount = (float) $this->summaryOf($loan)['advance_presets'][0]['amount'];
        $this->assertEqualsWithDelta(8983.52, $amount, 0.001, 'setup: the 1-period preset');

        $repayment = $this->payLoan($loan, $amount, '2026-03-15');

        $this->assertSame('paid', $this->period($loan, 1)->status);
        $this->assertNextPeriodUntouched($loan, $repayment, 2);
        $this->assertSame('exact', $repayment->payment_type);
        $this->assertEqualsWithDelta(0.0, (float) $repayment->overpayment, 0.001);
    }

    public function test_paying_the_two_period_preset_leaves_the_third_period_untouched(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 09:00'));
        $loan = $this->releasedLoanFor(principal: self::PRINCIPAL, startDate: '2026-02-15');

        $amount = (float) $this->summaryOf($loan)['advance_presets'][1]['amount'];
        $this->assertEqualsWithDelta(17967.04, $amount, 0.001, 'setup: the 2-period preset');

        $repayment = $this->payLoan($loan, $amount, '2026-02-15');

        $this->assertSame(['paid', 'paid'], [$this->period($loan, 1)->status, $this->period($loan, 2)->status]);
        $this->assertNextPeriodUntouched($loan, $repayment, 3);
    }

    private function assertNextPeriodUntouched(Loan $loan, Repayment $repayment, int $number): void
    {
        $next = $this->period($loan, $number);

        $this->assertSame('pending', $next->status);
        $this->assertEqualsWithDelta(0.0, (float) $next->principal_paid + (float) $next->interest_paid + (float) $next->penalty_paid, 0.001);
        $this->assertSame(
            [],
            $repayment->allocations()->where('period_number', $number)->get()->map->only(['period_number', 'penalty', 'interest', 'principal'])->all(),
            "no allocation row on period {$number}",
        );
    }

    private function period(Loan $loan, int $number): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', $number)->sole();
    }
}
