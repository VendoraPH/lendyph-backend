<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A balance adjustment spread over a loan's open periods adds up to exactly
 * the amount approved, to the centavo.
 *
 * Each period's share used to be rounded on its own, so ₱1,000 off six equal
 * periods came off as six ₱166.67s: ₱1,000.02. The rounding remainder now
 * lands on the last period that still owes principal.
 *
 * The loan from createReleasedLoan(): ₱60,000 over 6 monthly periods of
 * ₱10,000 principal.
 */
class BalanceAdjustmentSpreadTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_reduction_over_six_equal_periods_adds_up_to_exactly_the_amount(): void
    {
        $loan = $this->createReleasedLoan();
        $before = $this->principalDue($loan);

        $this->applyBalanceAdjustment($loan, -1000);

        $after = $this->principalDue($loan);
        $this->assertSame(-100000, array_sum($after) - array_sum($before));
        // Five shares of -₱166.67, and the last takes what is left: -₱166.65.
        $this->assertSame([983333, 983333, 983333, 983333, 983333, 983335], $after);
    }

    public function test_an_increase_over_six_equal_periods_adds_up_to_exactly_the_amount(): void
    {
        $loan = $this->createReleasedLoan();
        $before = $this->principalDue($loan);

        $this->applyBalanceAdjustment($loan, 1000);

        $after = $this->principalDue($loan);
        $this->assertSame(100000, array_sum($after) - array_sum($before));
        $this->assertSame([1016667, 1016667, 1016667, 1016667, 1016667, 1016665], $after);
    }

    public function test_shares_follow_each_periods_unpaid_principal_and_still_add_up(): void
    {
        $loan = $this->createReleasedLoan();
        // ₱1,800 interest and ₱3,200 principal on period 1: it owes ₱6,800.
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);
        $before = $this->principalDue($loan);

        $this->applyBalanceAdjustment($loan, -1000);

        $after = $this->principalDue($loan);
        $this->assertSame(-100000, array_sum($after) - array_sum($before));
        // ₱6,800 / ₱56,800 of it on period 1, ₱10,000 / ₱56,800 on each other.
        $this->assertSame([-11972, -17606, -17606, -17606, -17606, -17604], array_map(
            fn (int $new, int $old): int => $new - $old,
            $after,
            $before,
        ));
    }

    public function test_total_due_follows_the_new_principal(): void
    {
        $loan = $this->createReleasedLoan();

        $this->applyBalanceAdjustment($loan, -1000);

        foreach ($loan->amortizationSchedules()->get() as $schedule) {
            $this->assertSame(
                $this->centavos($schedule->principal_due) + $this->centavos($schedule->interest_due),
                $this->centavos($schedule->total_due),
            );
        }
    }

    public function test_a_share_a_period_cannot_take_moves_to_the_periods_that_can(): void
    {
        $loan = $this->createReleasedLoan();

        // Each period owes one centavo of principal. ₱0.02 off spread six ways
        // rounds to nothing on each; it used to vanish without trace. Now the
        // last period takes the remainder, can take only one centavo of it,
        // and the period before it takes the other.
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->update([
            'principal_paid' => DB::raw('principal_due - 0.01'),
            'status' => 'partial',
        ]);
        $before = $this->principalDue($loan);

        $this->applyBalanceAdjustment($loan, -0.02);

        $after = $this->principalDue($loan);
        $this->assertSame([0, 0, 0, 0, -1, -1], array_map(fn (int $new, int $old): int => $new - $old, $after, $before));

        // No period owes less principal than has been paid on it.
        foreach ($loan->amortizationSchedules()->get() as $schedule) {
            $this->assertGreaterThanOrEqual($this->centavos($schedule->principal_paid), $this->centavos($schedule->principal_due));
        }
    }

    public function test_a_reduction_larger_than_the_unpaid_principal_is_refused_and_changes_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $adjustment = $this->approvedBalanceAdjustment($loan, -70000);
        $before = $this->principalDue($loan);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_values.adjustment_amount');

        $this->assertSame($before, $this->principalDue($loan));
        $this->assertSame('approved', $adjustment->fresh()->status);
    }

    private function applyBalanceAdjustment(Loan $loan, float $amount): void
    {
        $adjustment = $this->approvedBalanceAdjustment($loan, $amount);

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertOk();
    }

    private function approvedBalanceAdjustment(Loan $loan, float $amount): LoanAdjustment
    {
        $service = app(LoanAdjustmentService::class);

        $adjustment = $service->createAdjustment($loan->fresh(), [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $amount],
        ], $this->admin);

        return $service->approveAdjustment($adjustment, $this->admin, 'ok')->fresh();
    }

    /**
     * Each period's principal due, in centavos, in period order.
     *
     * @return list<int>
     */
    private function principalDue(Loan $loan): array
    {
        return AmortizationSchedule::where('loan_id', $loan->id)
            ->orderBy('period_number')
            ->pluck('principal_due')
            ->map(fn (mixed $pesos): int => $this->centavos($pesos))
            ->all();
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round((float) $pesos * 100);
    }
}
