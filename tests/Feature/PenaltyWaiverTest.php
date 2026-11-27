<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A penalty waiver forgives only the penalty still owed.
 *
 * It used to set both `penalty_amount` and `penalty_paid` to 0, so penalty the
 * borrower had already paid vanished from the period while the receipt still
 * carried it, Total Paid dropped by that amount, and a later void took the
 * missing penalty out of interest or principal instead.
 *
 * The loan: ₱60,000 over six months at 3% straight (₱10,000 principal and
 * ₱1,800 interest a period), 2% penalty, 3 days grace, started 2026-01-15.
 * On 2026-03-01 period 1 (due 2026-02-15) is late and carries a ₱200 penalty.
 */
class PenaltyWaiverTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
    }

    public function test_a_waiver_on_an_unpaid_penalty_cancels_it(): void
    {
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->assertPenalty(1, charged: 200, paid: 0);

        $this->waiveAll();

        $this->assertPenalty(1, charged: 0, paid: 0);
        $summary = $this->summary();
        $this->assertMoney(0, $summary['total_paid']);
        $this->assertMoney(0, $summary['outstanding_penalty']);
        $this->assertPaymentsReconcile();
    }

    public function test_a_waiver_on_a_partly_paid_penalty_keeps_the_payment(): void
    {
        $this->pay(80);
        $this->assertPenalty(1, charged: 200, paid: 80);

        $before = $this->summary();
        $this->assertMoney(80, $before['total_paid']);
        $this->assertMoney(70920, $before['outstanding_balance']);
        $this->assertMoney(11920, $before['overdue_amount']);
        $this->assertMoney(120, $this->period(1)['penalty']['balance']);
        $this->assertMoney(11920, $this->period(1)['balance']);

        $this->waiveAll();

        // Only the unpaid ₱120 is forgiven; the ₱80 paid stays on the period.
        $after = $this->summary();
        $this->assertMoney(80, $after['total_paid']);
        $this->assertPenalty(1, charged: 80, paid: 80);
        $this->assertMoney(70800, $after['outstanding_balance']);
        $this->assertMoney(11800, $after['overdue_amount']);
        $this->assertMoney(0, $this->period(1)['penalty']['balance']);
        $this->assertMoney(11800, $this->period(1)['balance']);
        $this->assertPaymentsReconcile();
    }

    public function test_a_waiver_on_a_fully_paid_penalty_changes_nothing(): void
    {
        // ₱200 settles the penalty, the other ₱300 goes to interest.
        $this->pay(500);
        $this->assertPenalty(1, charged: 200, paid: 200);

        $this->waiveAll();

        $this->assertMoney(500, $this->summary()['total_paid']);
        $this->assertPenalty(1, charged: 200, paid: 200);
        $this->assertMoney(300, AmortizationSchedule::find($this->scheduleId(1))->interest_paid);
        $this->assertPaymentsReconcile();
    }

    public function test_voiding_a_payment_after_a_waiver_reverses_only_that_payment(): void
    {
        $this->pay(80);
        $second = $this->pay(50);
        $this->assertPenalty(1, charged: 200, paid: 130);

        $this->waiveAll();
        $this->assertPenalty(1, charged: 130, paid: 130);

        $this->patchJson("/api/repayments/{$second->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();

        // The voided ₱50 is owed again; the ₱70 waived stays waived.
        $this->assertPenalty(1, charged: 130, paid: 80);
        $summary = $this->summary();
        $this->assertMoney(80, $summary['total_paid']);
        $this->assertMoney(50, $summary['outstanding_penalty']);
        $this->assertPaymentsReconcile();
    }

    public function test_the_waiver_records_what_it_waived_and_who_applied_it(): void
    {
        // A month later periods 1 and 2 are both late, ₱200 penalty each.
        $this->travelTo(Carbon::parse('2026-04-01 09:00'));
        $this->pay(80);
        $this->assertPenalty(1, charged: 200, paid: 80);
        $this->assertPenalty(2, charged: 200, paid: 0);

        $adjustment = $this->waiveAll();

        $log = AuditLog::where('action', 'penalty_waived')
            ->where('auditable_type', LoanAdjustment::class)
            ->where('auditable_id', $adjustment->id)
            ->sole();

        $this->assertSame($this->admin->id, $log->user_id);
        // assertEquals: the JSON column reorders keys and stores 200.0 as 200.
        $this->assertEquals([
            ['schedule_id' => $this->scheduleId(1), 'period_number' => 1, 'penalty_amount' => 200.0, 'penalty_paid' => 80.0],
            ['schedule_id' => $this->scheduleId(2), 'period_number' => 2, 'penalty_amount' => 200.0, 'penalty_paid' => 0.0],
        ], $log->old_values['periods']);
        $this->assertEquals([
            ['schedule_id' => $this->scheduleId(1), 'period_number' => 1, 'penalty_amount' => 80.0, 'penalty_paid' => 80.0, 'waived' => 120.0],
            ['schedule_id' => $this->scheduleId(2), 'period_number' => 2, 'penalty_amount' => 0.0, 'penalty_paid' => 0.0, 'waived' => 200.0],
        ], $log->new_values['periods']);
        $this->assertSame(320.0, (float) $log->new_values['total_waived']);
    }

    public function test_a_waiver_holds_through_the_nightly_penalty_run(): void
    {
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->waiveAll();
        $this->assertPenalty(1, charged: 0, paid: 0);

        $this->travelTo(Carbon::parse('2026-03-02 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->assertPenalty(1, charged: 0, paid: 0);

        // Period 2 falls late later and is charged as usual; period 1 stays waived.
        $this->travelTo(Carbon::parse('2026-03-20 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->assertPenalty(1, charged: 0, paid: 0);
        $this->assertPenalty(2, charged: 200, paid: 0);
        $this->assertMoney(23800, $this->summary()['overdue_amount']);
    }

    public function test_a_waiver_holds_through_the_next_payment(): void
    {
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->waiveAll();

        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $payment = $this->pay(5000);

        // Nothing is charged back, so the ₱5,000 goes to interest, then principal.
        $this->assertMoney(0, $payment->penalty_applied);
        $this->assertMoney(1800, $payment->interest_applied);
        $this->assertMoney(3200, $payment->principal_applied);
        $this->assertPenalty(1, charged: 0, paid: 0);

        $this->travelTo(Carbon::parse('2026-03-11 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->assertPenalty(1, charged: 0, paid: 0);
        $this->assertPaymentsReconcile();
    }

    public function test_a_waiver_keeps_a_partly_paid_penalty_at_what_was_paid(): void
    {
        $this->pay(80);
        $this->waiveAll();

        $this->travelTo(Carbon::parse('2026-03-02 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-03-05 09:00'));
        $payment = $this->pay(1000);

        $this->assertMoney(0, $payment->penalty_applied);
        $this->assertPenalty(1, charged: 80, paid: 80);
        $this->assertPaymentsReconcile();
    }

    public function test_the_waiver_links_each_period_it_forgave(): void
    {
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        $adjustment = $this->waiveAll();

        // Period 2 is not late yet, so there was nothing on it to forgive.
        $this->assertSame($adjustment->id, AmortizationSchedule::find($this->scheduleId(1))->penalty_waiver_id);
        $this->assertNull(AmortizationSchedule::find($this->scheduleId(2))->penalty_waiver_id);
    }

    public function test_voiding_the_payment_a_waiver_kept_makes_that_penalty_owed_again(): void
    {
        $payment = $this->pay(80);
        $this->waiveAll();

        $this->patchJson("/api/repayments/{$payment->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();
        $this->assertPenalty(1, charged: 80, paid: 0);

        // Owed again, but never charged afresh: the ₱120 waived stays waived.
        $this->travelTo(Carbon::parse('2026-03-02 06:05'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->assertPenalty(1, charged: 80, paid: 0);
        $this->assertMoney(80, $this->summary()['outstanding_penalty']);
        $this->assertPaymentsReconcile();
    }

    public function test_a_request_for_selected_periods_records_only_those(): void
    {
        $this->travelTo(Carbon::parse('2026-04-01 09:00'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        $oldValues = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['schedule_ids' => [$this->scheduleId(2)]],
        ])->assertCreated()->json('data.old_values');

        $this->assertEquals(['penalties' => [[
            'schedule_id' => $this->scheduleId(2),
            'period_number' => 2,
            'penalty_amount' => 200.0,
            'penalty_paid' => 0.0,
            'penalty_owed' => 200.0,
        ]]], $oldValues);
    }

    public function test_the_request_records_only_the_penalty_still_owed(): void
    {
        // On 2026-04-01 periods 1 and 2 are late, ₱200 penalty each. ₱12,000
        // settles period 1 outright; ₱80 then goes to period 2's penalty.
        $this->travelTo(Carbon::parse('2026-04-01 09:00'));
        $this->pay(12000);
        $this->pay(80);
        $this->assertPenalty(1, charged: 200, paid: 200);
        $this->assertPenalty(2, charged: 200, paid: 80);

        $oldValues = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ])->assertCreated()->json('data.old_values');

        $this->assertEquals(['penalties' => [[
            'schedule_id' => $this->scheduleId(2),
            'period_number' => 2,
            'penalty_amount' => 200.0,
            'penalty_paid' => 80.0,
            'penalty_owed' => 120.0,
        ]]], $oldValues);
    }

    private function pay(float $amount): Repayment
    {
        $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /**
     * Request, approve and apply a waiver of every penalty on the loan, the
     * way the loan screen sends it.
     */
    private function waiveAll(): LoanAdjustment
    {
        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');

        return LoanAdjustment::findOrFail($id);
    }

    private function scheduleId(int $period): int
    {
        return $this->loan->amortizationSchedules()->where('period_number', $period)->value('id');
    }

    private function assertPenalty(int $period, float $charged, float $paid): void
    {
        $schedule = AmortizationSchedule::findOrFail($this->scheduleId($period));

        $this->assertMoney($charged, $schedule->penalty_amount);
        $this->assertMoney($paid, $schedule->penalty_paid);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data');
    }

    /**
     * One period of the Amortization Balance tab.
     *
     * @return array<string, mixed>
     */
    private function period(int $period): array
    {
        $periods = $this->getJson("/api/loans/{$this->loan->id}/amortization-balances")->assertOk()->json('data.periods');

        return collect($periods)->firstWhere('period_number', $period);
    }

    /**
     * Every peso on a posted receipt is still on a period: the period records
     * and the payment records agree on penalty and on Total Paid.
     */
    private function assertPaymentsReconcile(): void
    {
        $posted = Repayment::where('loan_id', $this->loan->id)->where('status', 'posted');

        $this->assertMoney(
            (clone $posted)->sum('penalty_applied'),
            AmortizationSchedule::where('loan_id', $this->loan->id)->sum('penalty_paid'),
        );
        $this->assertMoney(
            (clone $posted)->sum('principal_applied') + (clone $posted)->sum('interest_applied') + (clone $posted)->sum('penalty_applied'),
            $this->summary()['total_paid'],
        );
    }

    private function assertMoney(float|int|string $expected, float|int|string $actual): void
    {
        $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.001);
    }
}
