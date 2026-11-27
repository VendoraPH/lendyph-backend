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
 * The data migration that puts back penalty payments a penalty waiver erased.
 *
 * Deployed databases already hold the damage when it lands, so each test takes
 * real payments through the API and then writes what the old waiver did
 * straight into the tables (an applied waiver, its periods' penalty zeroed),
 * then runs up() the way a deploy does.
 *
 * The loan: ₱60,000 over six months at 3% straight (₱10,000 principal and
 * ₱1,800 interest a period), 2% penalty, started 2026-01-15. On 2026-03-01
 * period 1 is late with a ₱200 penalty.
 */
class RestorePenaltyPaymentsErasedByWaiversTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = 'migrations/2026_10_01_140000_restore_penalty_payments_erased_by_waivers.php';

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_a_partly_paid_penalty_the_waiver_erased_is_restored(): void
    {
        $this->pay(80);
        $this->oldWaiver();
        $this->assertPenalty(1, charged: 0, paid: 0);
        $this->assertMoney(0, $this->summary()['total_paid']);

        $this->migration()->up();

        $this->assertPenalty(1, charged: 80, paid: 80);
        $summary = $this->summary();
        $this->assertMoney(80, $summary['total_paid']);
        $this->assertMoney(0, $summary['outstanding_penalty']);
        $this->assertPaymentsReconcile();
    }

    public function test_the_payment_goes_back_on_a_period_settled_after_the_waiver(): void
    {
        $this->pay(80);
        $this->oldWaiver();

        // The next payment re-charges the ₱200 penalty and settles period 1
        // (₱12,000), then puts ₱1,000 on period 2's interest.
        $this->travelTo(Carbon::parse('2026-03-02 09:00'));
        $this->pay(13000);
        $this->assertPenalty(1, charged: 200, paid: 200);

        $this->migration()->up();

        $this->assertPenalty(1, charged: 280, paid: 280);
        $this->assertPenalty(2, charged: 0, paid: 0);
        $this->assertSame('paid', $this->schedule(1)->status);
        $this->assertMoney(13080, $this->summary()['total_paid']);
        $this->assertPaymentsReconcile();
    }

    public function test_each_restore_is_audited_as_a_system_change_against_the_loan(): void
    {
        $this->pay(80);
        $waiver = $this->oldWaiver();

        $this->migration()->up();

        $entry = AuditLog::where('action', 'penalty_payment_restored')->sole();

        $this->assertNull($entry->user_id, 'A system change names no user.');
        $this->assertNull($entry->ip_address);
        $this->assertSame(Loan::class, $entry->auditable_type);
        $this->assertSame($this->loan->id, $entry->auditable_id);
        $this->assertSame($this->schedule(1)->id, $entry->old_values['schedule_id']);
        $this->assertMoney(0, $entry->old_values['penalty_paid']);
        $this->assertMoney(80, $entry->new_values['penalty_paid']);
        $this->assertMoney(80, $entry->new_values['penalty_amount']);
        $this->assertMoney(80, $entry->new_values['restored']);
        $this->assertSame($waiver->adjustment_number, $entry->new_values['waiver']);
        $this->assertStringStartsWith('System correction:', $entry->description);
    }

    public function test_a_waiver_that_erased_nothing_is_left_alone(): void
    {
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->oldWaiver();

        $this->assertSame([], $this->migration()->plan());

        $this->migration()->up();

        $this->assertPenalty(1, charged: 0, paid: 0);
        $this->assertSame(0, $this->restores());
    }

    public function test_a_waiver_applied_by_the_fixed_code_is_left_alone(): void
    {
        $this->pay(80);
        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $this->assertSame([], $this->migration()->plan());

        $this->migration()->up();

        $this->assertPenalty(1, charged: 80, paid: 80);
        $this->assertSame(0, $this->restores());
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        $this->pay(80);
        $this->oldWaiver();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertPenalty(1, charged: 80, paid: 80);
        $this->assertSame(1, $this->restores());
        $this->assertSame([], $this->migration()->plan());
    }

    public function test_a_loan_with_a_voided_payment_is_undetermined(): void
    {
        $this->pay(80);
        $second = $this->pay(50);
        $this->patchJson("/api/repayments/{$second->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();
        $this->oldWaiver();

        $this->assertUndetermined('the loan has a voided payment');
    }

    public function test_a_loan_with_another_applied_adjustment_is_undetermined(): void
    {
        $this->pay(80);
        $this->oldWaiver();
        $this->oldWaiver();

        $this->assertUndetermined('another adjustment was also applied to the loan');
    }

    public function test_receipts_that_disagree_with_the_periods_on_interest_are_undetermined(): void
    {
        $this->pay(80);
        $this->oldWaiver();
        AmortizationSchedule::whereKey($this->schedule(2)->id)->update(['interest_paid' => 100]);

        $this->assertUndetermined('the receipts and the periods disagree on principal or interest');
    }

    public function test_a_period_outside_the_waivers_selection_is_undetermined(): void
    {
        $this->pay(80);
        // Period 1 holds the payment, yet the waiver on record selected only
        // period 2: the records do not explain how period 1 lost it.
        $this->oldWaiver([$this->schedule(2)->id]);
        AmortizationSchedule::whereKey($this->schedule(1)->id)->update(['penalty_amount' => 0, 'penalty_paid' => 0]);

        $this->assertUndetermined('the matching period was not covered by the waiver');
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
     * What applyPenaltyWaiver() did before the fix, an hour after the last
     * payment: an applied waiver on record, and every open period it covered
     * stripped of its penalty, paid part included.
     *
     * @param  list<int>|null  $scheduleIds  null waives every period, as the loan screen sends it
     */
    private function oldWaiver(?array $scheduleIds = null): LoanAdjustment
    {
        $this->travel(1)->hours();

        $waiver = LoanAdjustment::create([
            'loan_id' => $this->loan->id,
            'adjustment_type' => 'penalty_waiver',
            'old_values' => ['penalties' => []],
            'new_values' => $scheduleIds === null ? ['waive_all' => true] : ['schedule_ids' => $scheduleIds],
            'status' => 'applied',
            'adjusted_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'applied_at' => now(),
        ]);

        $open = AmortizationSchedule::where('loan_id', $this->loan->id)->whereIn('status', ['pending', 'partial', 'overdue']);

        if ($scheduleIds !== null) {
            $open->whereIn('id', $scheduleIds);
        }

        $open->update(['penalty_amount' => 0, 'penalty_paid' => 0]);

        $this->travel(1)->hours();

        return $waiver;
    }

    private function assertUndetermined(string $reason): void
    {
        $before = AmortizationSchedule::where('loan_id', $this->loan->id)->orderBy('period_number')->get()->toArray();

        $plan = $this->migration()->plan();
        $this->assertCount(1, $plan);
        $this->assertSame('undetermined', $plan[0]->verdict);
        $this->assertSame($reason, $plan[0]->undetermined_reason);

        $this->migration()->up();

        $this->assertSame($before, AmortizationSchedule::where('loan_id', $this->loan->id)->orderBy('period_number')->get()->toArray());
        $this->assertSame(0, $this->restores());
    }

    private function schedule(int $period): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $this->loan->id)->where('period_number', $period)->firstOrFail();
    }

    private function assertPenalty(int $period, float $charged, float $paid): void
    {
        $schedule = $this->schedule($period);

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

    private function restores(): int
    {
        return AuditLog::where('action', 'penalty_payment_restored')->count();
    }

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
