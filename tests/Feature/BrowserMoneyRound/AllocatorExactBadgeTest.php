<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Decision m5-1: the allocator works in whole centavos, so the preview of an
 * exact preset says "Exact Payment", as the payment it previews is recorded.
 *
 * ₱45,678.91 over 6 monthly periods at 3% straight: ₱7,613.15 principal and
 * ₱1,370.37 interest a period, ₱8,983.52 due. In floats the 1-period preset
 * left about 9e-13 behind, which reached period 2 and made the badge
 * "Advance Payment".
 */
class AllocatorExactBadgeTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    public function test_the_preview_of_an_exact_preset_says_exact_and_reaches_only_its_period(): void
    {
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-02-15 09:00'));
        $loan = $this->releasedLoanFor(principal: 45678.91, startDate: '2026-02-15');
        $this->travelTo(Carbon::parse('2026-03-15 09:00'));

        $amount = (float) $this->summaryOf($loan)['advance_presets'][0]['amount'];
        $this->assertEqualsWithDelta(8983.52, $amount, 0.001, 'setup: the 1-period preset');

        $preview = $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => $amount,
            'payment_date' => '2026-03-15',
        ])->assertOk()->json('data');

        $this->assertSame(['type' => 'exact', 'label' => 'Exact Payment'], $preview['payment_badge']);
        $this->assertSame('exact', $preview['payment_type']);
        $this->assertSame([1], array_column($preview['allocations'], 'period'));
        $this->assertEqualsWithDelta(0.0, (float) $preview['excess'], 0.001);
        $this->assertEqualsWithDelta(7613.15, (float) $preview['total_principal'], 0.001);
        $this->assertEqualsWithDelta(1370.37, (float) $preview['total_interest'], 0.001);
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count(), 'the preview records nothing');
        $this->assertSame(0, RepaymentAllocation::whereIn('amortization_schedule_id', $loan->amortizationSchedules()->pluck('id'))->count(), 'the preview leaves no allocation rows');
    }
}
