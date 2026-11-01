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

    public function test_a_reduction_equal_to_the_last_open_period_s_unpaid_principal_settles_it_and_completes_the_loan(): void
    {
        $loan = $this->loanOwingOnItsLastPeriod();

        $this->applyBalanceAdjustment($loan, -600);

        $last = $loan->amortizationSchedules()->reorder('period_number', 'desc')->first();
        $this->assertSame(40000, $this->centavos($last->principal_due));
        $this->assertSame('paid', $last->status);
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_a_smaller_reduction_leaves_the_period_and_the_loan_as_they_were(): void
    {
        $loan = $this->loanOwingOnItsLastPeriod();
        $statusBefore = $loan->fresh()->status;

        $this->applyBalanceAdjustment($loan, -300);

        $last = $loan->amortizationSchedules()->reorder('period_number', 'desc')->first();
        $this->assertSame(70000, $this->centavos($last->principal_due));
        $this->assertSame('partial', $last->status);
        $this->assertSame($statusBefore, $loan->fresh()->status);
    }

    public function test_a_reduction_settles_only_the_periods_it_touches_and_never_rewrites_paid_ones(): void
    {
        // Their amounts and status. Their remaining_balance is still rewritten
        // by the older chain recalculation after the spread (flagged, not
        // changed here).
        $loan = $this->loanOwingOnItsLastPeriod();
        $paidBefore = AmortizationSchedule::where('loan_id', $loan->id)->where('status', 'paid')
            ->orderBy('period_number')->get(['id', 'principal_due', 'interest_due', 'total_due', 'principal_paid', 'interest_paid', 'status'])->toArray();

        $this->applyBalanceAdjustment($loan, -600);

        $this->assertSame($paidBefore, AmortizationSchedule::where('loan_id', $loan->id)->whereIn('id', array_column($paidBefore, 'id'))
            ->orderBy('period_number')->get(['id', 'principal_due', 'interest_due', 'total_due', 'principal_paid', 'interest_paid', 'status'])->toArray());
    }

    public function test_an_adjustment_amount_with_more_than_two_decimals_is_refused_not_rounded(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -100.005],
        ])->assertUnprocessable()->assertJsonValidationErrors('new_values.adjustment_amount');

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => -100.01],
        ])->assertCreated();

        $this->assertSame(1, LoanAdjustment::where('loan_id', $loan->id)->count());
    }

    /**
     * Periods 1 to 5 paid in full; period 6 owes ₱1,000 principal with ₱400
     * of it and all its interest paid, so ₱600 of principal is all that is
     * still owed on the loan.
     */
    private function loanOwingOnItsLastPeriod(): Loan
    {
        $loan = $this->createReleasedLoan();

        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', '<', 6)->update([
            'principal_paid' => DB::raw('principal_due'),
            'interest_paid' => DB::raw('interest_due'),
            'status' => 'paid',
        ]);
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 6)->update([
            'principal_due' => 1000,
            'principal_paid' => 400,
            'interest_paid' => DB::raw('interest_due'),
            'total_due' => DB::raw('1000 + interest_due'),
            'status' => 'partial',
        ]);

        return $loan;
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
