<?php

namespace Tests\Feature\M6Reports;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Carbon\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Statement of Account (decision 6, 2026-11-02): `summary.overdue_amount`
 * is the loan summary's `overdue_amount` (late periods, past grace), and each
 * schedule row carries `amount_remaining`, its unpaid principal, interest and
 * penalty, for the Balance column.
 */
class StatementOfAccountOverdueTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_summary_s_overdue_amount_is_the_loan_summary_s(): void
    {
        $loan = $this->releasedDaysAgo(90, grace: 3);
        $this->moveSchedule($loan, 1, 40);
        $this->moveSchedule($loan, 2, 10);
        // Inside grace: owed, not past due.
        $this->moveSchedule($loan, 3, 2);
        $this->pay($loan, 4000, Carbon::today()->subDays(5));

        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');
        $soa = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');

        $this->assertGreaterThan(0, $summary['overdue_amount']);
        $this->assertEquals($summary['overdue_amount'], $soa['summary']['overdue_amount']);

        $periodThree = collect($soa['amortization_schedule'])->firstWhere('period_number', 3);
        $this->assertGreaterThan(0, $periodThree['amount_remaining']);
        $lateRows = collect($soa['amortization_schedule'])->whereIn('period_number', [1, 2]);
        $this->assertEqualsWithDelta($lateRows->sum('amount_remaining'), $soa['summary']['overdue_amount'], 0.001, 'Only the periods past grace.');
    }

    public function test_a_loan_inside_grace_has_a_zero_overdue_amount(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 5);
        $this->moveSchedule($loan, 1, 3);

        $soa = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');

        $this->assertNotNull($soa['summary']['overdue_amount'], 'A real ₱0.00, not a missing figure.');
        $this->assertEquals(0, $soa['summary']['overdue_amount']);
    }

    public function test_the_insurance_premium_owed_is_never_past_due(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $this->moveSchedule($loan, 1, 20);
        $loan->forceFill(['insurance_remaining_balance' => 500])->save();

        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');
        $soa = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');

        $this->assertEquals($summary['overdue_amount'], $soa['summary']['overdue_amount']);
        $this->assertEquals(500, $soa['summary']['insurance_owed']);
    }

    public function test_each_row_carries_its_amount_remaining(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $first = $this->moveSchedule($loan, 1, 20);
        // The payment stamps period 1's late penalty and pays part of the period.
        $this->pay($loan, 1500, Carbon::today());

        $first->refresh();
        $soa = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');
        $rows = collect($soa['amortization_schedule'])->keyBy('period_number');

        $expected = round(
            max(0, (float) $first->principal_due - (float) $first->principal_paid)
            + max(0, (float) $first->interest_due - (float) $first->interest_paid)
            + max(0, (float) $first->penalty_amount - (float) $first->penalty_paid),
            2,
        );
        $this->assertGreaterThan(0, (float) $first->principal_paid + (float) $first->interest_paid + (float) $first->penalty_paid, 'The payment must land for this to test anything.');
        $this->assertEquals($expected, $rows[1]['amount_remaining']);

        foreach ($rows as $row) {
            $this->assertEquals(
                round($row['remaining']['principal'] + $row['remaining']['interest'] + $row['remaining']['penalty'], 2),
                $row['amount_remaining'],
            );
        }

        $this->assertEqualsWithDelta(
            $soa['summary']['outstanding_principal'] + $soa['summary']['outstanding_interest'] + $soa['summary']['outstanding_penalty'],
            $rows->sum('amount_remaining'),
            0.001,
        );
    }

    public function test_a_paid_period_has_nothing_remaining(): void
    {
        $loan = $this->releasedDaysAgo(60, grace: 0);
        $period = $this->moveSchedule($loan, 1, 20);
        $period->update([
            'principal_paid' => $period->principal_due,
            'interest_paid' => $period->interest_due,
            'status' => 'paid',
        ]);

        $soa = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');
        $row = collect($soa['amortization_schedule'])->firstWhere('period_number', 1);

        $this->assertEquals(0, $row['amount_remaining']);
        $this->assertEquals(0, $soa['summary']['overdue_amount']);
    }

    private function releasedDaysAgo(int $daysAgo, int $grace): Loan
    {
        $loan = $this->createReleasedLoan(['product' => ['grace_period_days' => $grace]]);
        $loan->forceFill(['released_at' => Carbon::today()->subDays($daysAgo)])->save();

        return $loan->fresh();
    }

    private function moveSchedule(Loan $loan, int $periodNumber, int $daysAgo): AmortizationSchedule
    {
        $schedule = $loan->amortizationSchedules()->where('period_number', $periodNumber)->firstOrFail();
        $schedule->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);

        return $schedule->fresh();
    }

    private function pay(Loan $loan, float $amount, Carbon $date): int
    {
        return $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => $date->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');
    }
}
