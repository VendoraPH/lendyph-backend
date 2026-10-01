<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A restructure, a term extension or an extension keeps every period that
 * money was collected on, closed at what was collected, and carries only
 * the unpaid remainder forward. The new periods are numbered after the kept
 * ones but fall on the same dates as before, and `term` stays the loan's length.
 *
 * The instalment loan: ₱60,000 over six months at 3% straight (₱10,000
 * principal and ₱1,800 interest a period), 2% penalty, 3 days grace, started
 * 2026-01-15, so period n falls due on the 15th of the month after it.
 * Periods 1 and 2 are paid in full and period 3 is paid ₱5,000 (₱1,800
 * interest, ₱3,200 principal) before it falls due.
 */
class ReschedulingKeepsPaidPeriodsTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_restructure_keeps_the_partly_paid_period_and_carries_only_the_remainder(): void
    {
        $partial = $this->payThreePeriodsPartly();

        $this->applyAdjustment('restructure', ['term' => 6]);

        $this->assertClosedAt(3, interest: 1800, principal: 3200);
        $this->assertTotalPaid(28600);
        $this->assertPaymentsReconcile();

        // ₱60,000 - ₱20,000 - ₱3,200 moves into six new periods numbered
        // after period 3, the first still due on period 3's date.
        $new = $this->periodsAfter(3);
        $this->assertSame([4, 5, 6, 7, 8, 9], $new->pluck('period_number')->all());
        $this->assertEqualsWithDelta(36800, (float) $new->sum('principal_due'), 0.001);
        $this->assertSame('2026-04-15', $new->first()->due_date->toDateString());
        $this->assertSame('2026-09-15', $new->last()->due_date->toDateString());
        // Two instalments behind it and six ahead: eight months, as before.
        $this->assertSame(8, $this->loan->fresh()->term);

        $this->void($partial);
        $this->assertOwed(3, interest: 1800, principal: 3200);
        $this->assertPaymentsReconcile();
    }

    public function test_a_term_extension_keeps_the_partly_paid_period_and_carries_only_the_remainder(): void
    {
        $partial = $this->payThreePeriodsPartly();

        $this->applyAdjustment('term_extension', ['additional_terms' => 2]);

        $this->assertClosedAt(3, interest: 1800, principal: 3200);
        $this->assertTotalPaid(28600);
        $this->assertPaymentsReconcile();

        // Periods 3 to 6 were open; their ₱36,800 principal now runs over six,
        // from period 3's date as before.
        $new = $this->periodsAfter(3);
        $this->assertSame([4, 5, 6, 7, 8, 9], $new->pluck('period_number')->all());
        $this->assertEqualsWithDelta(36800, (float) $new->sum('principal_due'), 0.001);
        $this->assertSame('2026-04-15', $new->first()->due_date->toDateString());
        $this->assertSame(8, $this->loan->fresh()->term);

        $this->void($partial);
        $this->assertOwed(3, interest: 1800, principal: 3200);
        $this->assertPaymentsReconcile();
    }

    public function test_the_amortization_balance_totals_still_match_the_summary_after_a_restructure(): void
    {
        $this->payThreePeriodsPartly();
        $this->applyAdjustment('restructure', ['term' => 6]);

        $summary = $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data');
        $balances = $this->getJson("/api/loans/{$this->loan->id}/amortization-balances")->assertOk()->json('data');

        $period3 = collect($balances['periods'])->firstWhere('period_number', 3);
        $this->assertEqualsWithDelta(5000, $period3['principal']['paid'] + $period3['interest']['paid'], 0.001);
        $this->assertEqualsWithDelta(0, $period3['balance'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_principal'], $balances['totals']['principal']['balance'], 0.001);
        $this->assertEqualsWithDelta($summary['total_paid'], $balances['totals']['paid'], 0.001);
        $this->assertEqualsWithDelta(28600, $balances['totals']['paid'], 0.001);
    }

    public function test_an_extension_that_collects_the_interest_keeps_it_on_the_period_it_paid(): void
    {
        $this->loan = $this->oneMonthLoan();

        $this->postJson("/api/loans/{$this->loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $this->assertClosedAt(1, interest: 1800, principal: 0);
        $this->assertTotalPaid(1800);
        $this->assertPaymentsReconcile();

        $next = $this->periodsAfter(1)->sole();
        $this->assertSame(2, $next->period_number);
        $this->assertEqualsWithDelta(60000, (float) $next->principal_due, 0.001);
        $this->assertEqualsWithDelta(1800, (float) $next->interest_due, 0.001);
    }

    public function test_the_payment_an_extension_collected_its_interest_with_cannot_be_voided(): void
    {
        $this->loan = $this->oneMonthLoan();
        $this->postJson("/api/loans/{$this->loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $collection = Repayment::where('loan_id', $this->loan->id)->sole();
        $extension = LoanAdjustment::where('loan_id', $this->loan->id)->sole();

        $this->patchJson("/api/repayments/{$collection->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.repayment.0', "This payment collected the interest when the loan was extended ({$extension->adjustment_number}), and that extension's ledger credit records it, so it cannot be voided. Use a balance adjustment to correct the loan instead.");

        $this->assertSame('posted', $collection->fresh()->status);
        $this->assertClosedAt(1, interest: 1800, principal: 0);
    }

    public function test_closing_a_period_is_recorded_on_the_adjustment(): void
    {
        $this->payThreePeriodsPartly();
        $this->applyAdjustment('restructure', ['term' => 6]);

        $row = AuditLog::where('action', 'periods_closed')->sole();

        $this->assertSame(3, $row->old_values['periods'][0]['period_number']);
        $this->assertEqualsWithDelta(10000, $row->old_values['periods'][0]['principal_due'], 0.001);
        $this->assertEqualsWithDelta(3200, $row->new_values['periods'][0]['principal_due'], 0.001);
        $this->assertEqualsWithDelta(5000, $row->new_values['total_collected'], 0.001);
        $this->assertSame(
            LoanAdjustment::where('loan_id', $this->loan->id)->sole()->id,
            $this->schedule(3)->closed_by_adjustment_id,
        );
    }

    public function test_a_restructure_after_an_extension_closed_a_period_keeps_its_first_due_date(): void
    {
        $this->loan = $this->oneMonthLoan();
        $this->postJson("/api/loans/{$this->loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $this->applyAdjustment('restructure', ['term' => 3]);

        // No period was paid in full, so the schedule starts from the loan's
        // own start, as it always has, and not after the closed period 1.
        $new = $this->periodsAfter(1);
        $this->assertSame([2, 3, 4], $new->pluck('period_number')->all());
        $this->assertSame(
            $this->loan->start_date->copy()->addMonthNoOverflow()->toDateString(),
            $new->first()->due_date->toDateString(),
        );
        $this->assertSame(3, $this->loan->fresh()->term);
        $this->assertClosedAt(1, interest: 1800, principal: 0);
        $this->assertTotalPaid(1800);
    }

    public function test_an_extension_keeps_interest_paid_before_it_and_carries_the_rest(): void
    {
        $this->loan = $this->oneMonthLoan();
        $payment = $this->payOn(now()->toDateString(), 900);

        $this->postJson("/api/loans/{$this->loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $this->assertClosedAt(1, interest: 900, principal: 0);
        $this->assertTotalPaid(900);

        // ₱900 unpaid interest carried, plus the fresh cycle's ₱1,800.
        $next = $this->periodsAfter(1)->sole();
        $this->assertEqualsWithDelta(2700, (float) $next->interest_due, 0.001);

        $this->void($payment);
        $this->assertOwed(1, interest: 900, principal: 0);
        $this->assertPaymentsReconcile();
    }

    private function payThreePeriodsPartly(): Repayment
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);

        $this->payOn('2026-02-10', 11800);
        $this->payOn('2026-03-10', 11800);

        return $this->payOn('2026-04-10', 5000);
    }

    /**
     * ₱60,000 at 3% for one month, paid at maturity, due a week from now so no
     * penalty takes part of the interest collection.
     */
    private function oneMonthLoan(): Loan
    {
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => 60000,
        ]);
        $loan->amortizationSchedules()->update(['due_date' => now()->addWeek()->toDateString()]);

        return $loan;
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function applyAdjustment(string $type, array $newValues): void
    {
        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => $type,
            'new_values' => $newValues,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();
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

    private function void(Repayment $repayment): void
    {
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');
    }

    /**
     * @return Collection<int, AmortizationSchedule>
     */
    private function periodsAfter(int $period): Collection
    {
        return AmortizationSchedule::where('loan_id', $this->loan->id)
            ->where('period_number', '>', $period)
            ->orderBy('period_number')
            ->get();
    }

    private function schedule(int $period): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $this->loan->id)->where('period_number', $period)->sole();
    }

    /**
     * Closed: the period is due exactly what was collected on it, and paid.
     */
    private function assertClosedAt(int $period, float $interest, float $principal): void
    {
        $schedule = $this->schedule($period);

        $this->assertEqualsWithDelta($interest, (float) $schedule->interest_paid, 0.001, "period {$period} interest paid");
        $this->assertEqualsWithDelta($principal, (float) $schedule->principal_paid, 0.001, "period {$period} principal paid");
        $this->assertEqualsWithDelta($interest, (float) $schedule->interest_due, 0.001, "period {$period} interest due");
        $this->assertEqualsWithDelta($principal, (float) $schedule->principal_due, 0.001, "period {$period} principal due");
        $this->assertSame('paid', $schedule->status, "period {$period} status");
    }

    /**
     * After a void, the closed period owes again exactly what the payment had covered.
     */
    private function assertOwed(int $period, float $interest, float $principal): void
    {
        $schedule = $this->schedule($period);

        $this->assertEqualsWithDelta($interest, (float) $schedule->interest_due - (float) $schedule->interest_paid, 0.001, "period {$period} interest owed");
        $this->assertEqualsWithDelta($principal, (float) $schedule->principal_due - (float) $schedule->principal_paid, 0.001, "period {$period} principal owed");
        $this->assertContains($schedule->status, AmortizationSchedule::UNPAID_STATUSES, "period {$period} status");
    }

    private function assertTotalPaid(float $expected): void
    {
        $totalPaid = $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data.total_paid');

        $this->assertEqualsWithDelta($expected, (float) $totalPaid, 0.001, 'total paid');
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
