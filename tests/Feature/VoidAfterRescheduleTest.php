<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\LoanAdjustmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A payment made before the loan was extended, restructured or given more
 * terms can't be voided: the schedule it was made against no longer exists
 * as it was. The void answers 422 on `repayment` and writes nothing. A
 * penalty waiver or a balance adjustment closes no periods, so a payment
 * made before one still voids, and so does a payment on a period opened by
 * the reschedule.
 *
 * The instalment loan: ₱60,000 over six months at 3% straight (₱10,000
 * principal and ₱1,800 interest a period), 2% penalty, 3 days grace,
 * started 2026-01-15, so period n falls due on the 15th of the month after it.
 */
class VoidAfterRescheduleTest extends TestCase
{
    use SetupLendyPH;

    private const REFUSAL = 'This payment was made before the loan was extended or restructured, so it cannot be voided. Use a balance adjustment to correct the loan instead.';

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_payment_on_a_period_a_restructure_closed_cannot_be_voided(): void
    {
        $partial = $this->payTwoPeriodsAndPartOfTheThird();
        $this->applyAdjustment('restructure', ['term' => 6]);

        $this->assertNotNull($this->schedule(3)->closed_by_adjustment_id, 'period 3 was not closed, so this test proves nothing');
        $this->assertVoidRefused($partial);
    }

    public function test_a_payment_on_a_period_a_term_extension_closed_cannot_be_voided(): void
    {
        $partial = $this->payTwoPeriodsAndPartOfTheThird();
        $this->applyAdjustment('term_extension', ['additional_terms' => 2]);

        $this->assertNotNull($this->schedule(3)->closed_by_adjustment_id, 'period 3 was not closed, so this test proves nothing');
        $this->assertVoidRefused($partial);
    }

    public function test_a_payment_on_a_period_an_extension_closed_cannot_be_voided(): void
    {
        $this->loan = $this->oneMonthLoan();
        $payment = $this->payOn(now()->toDateString(), 900);

        $this->postJson("/api/loans/{$this->loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $this->assertNotNull($this->schedule(1)->closed_by_adjustment_id, 'period 1 was not closed, so this test proves nothing');
        $this->assertVoidRefused($payment);
    }

    public function test_a_payment_that_paid_its_periods_in_full_before_a_restructure_cannot_be_voided(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $this->payOn('2026-02-10', 11800);
        $second = $this->payOn('2026-03-10', 11800);

        $this->travelTo(Carbon::parse('2026-03-11 09:00'));
        $this->applyAdjustment('restructure', ['term' => 6]);

        // Period 2 was paid in full, so the restructure left it as it was:
        // only the adjustment applied after the payment refuses this void.
        $this->assertNull($this->schedule(2)->closed_by_adjustment_id);
        $this->assertVoidRefused($second);
    }

    public function test_a_payment_on_a_period_opened_by_a_term_extension_can_be_voided(): void
    {
        $this->payTwoPeriodsAndPartOfTheThird();
        $this->travelTo(Carbon::parse('2026-04-11 09:00'));
        $this->applyAdjustment('term_extension', ['additional_terms' => 2]);

        $later = $this->payOn('2026-04-12', 1000);

        $this->void($later);
        $this->assertSame('voided', $later->fresh()->status);
        $this->assertPaymentsReconcile();
    }

    public function test_a_payment_on_a_period_opened_by_an_in_place_restructure_can_be_voided(): void
    {
        $this->payTwoPeriodsAndPartOfTheThird();
        $this->travelTo(Carbon::parse('2026-04-11 09:00'));
        $this->applyAdjustment('restructure', ['term' => 6]);
        $this->assertSame('ongoing', $this->loan->fresh()->status, 'the restructure was not in place, so this test proves nothing');

        $later = $this->payOn('2026-04-12', 1000);
        $this->assertSame([4], $later->allocations()->pluck('period_number')->unique()->values()->all());

        $this->void($later);
        $this->assertEqualsWithDelta(0, (float) $this->schedule(4)->principal_paid + (float) $this->schedule(4)->interest_paid, 0.001);
        $this->assertPaymentsReconcile();
    }

    public function test_a_payment_on_a_period_opened_by_an_extension_can_be_voided(): void
    {
        $this->loan = $this->oneMonthLoan();
        $this->postJson("/api/loans/{$this->loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $this->travelTo(now()->addDay());
        $later = $this->payOn(now()->toDateString(), 900);
        $this->assertSame([2], $later->allocations()->pluck('period_number')->unique()->values()->all());

        $this->void($later);
        $this->assertEqualsWithDelta(0, (float) $this->schedule(2)->interest_paid, 0.001);
        $this->assertPaymentsReconcile();
    }

    public function test_a_payment_made_before_a_penalty_waiver_can_be_voided(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $first = $this->payOn('2026-02-10', 11800);

        // Period 2 falls overdue and is charged a penalty, which is then waived.
        $this->travelTo(Carbon::parse('2026-03-25 09:00'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
        $this->assertGreaterThan(0, (float) $this->schedule(2)->penalty_amount, 'no penalty to waive, so this test proves nothing');
        $this->applyAdjustment('penalty_waiver', ['waive_all' => true]);
        $this->assertNotNull($this->schedule(2)->fresh()->penalty_waiver_id, 'the waiver did not land, so this test proves nothing');

        $this->void($first);
        $this->assertContains($this->schedule(1)->status, AmortizationSchedule::UNPAID_STATUSES);
        $this->assertEqualsWithDelta(0, (float) $this->schedule(1)->principal_paid, 0.001);
        $this->assertPaymentsReconcile();
    }

    public function test_a_payment_made_before_a_balance_adjustment_can_be_voided(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $first = $this->payOn('2026-02-10', 11800);

        $this->travelTo(Carbon::parse('2026-02-11 09:00'));
        $service = app(LoanAdjustmentService::class);
        $adjustment = $service->createAdjustment($this->loan->fresh(), [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => 1000],
        ], $this->admin);
        $service->approveAdjustment($adjustment, $this->admin, 'ok');
        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertOk();
        $this->assertSame('applied', $adjustment->fresh()->status);

        $this->void($first);
        $this->assertEqualsWithDelta(0, (float) $this->schedule(1)->principal_paid, 0.001);
        $this->assertPaymentsReconcile();
    }

    public function test_a_payment_on_an_open_period_with_no_reschedule_can_still_be_voided(): void
    {
        $partial = $this->payTwoPeriodsAndPartOfTheThird();

        $this->void($partial);
        $this->assertEqualsWithDelta(0, (float) $this->schedule(3)->principal_paid + (float) $this->schedule(3)->interest_paid, 0.001);
        $this->assertPaymentsReconcile();
    }

    private function payTwoPeriodsAndPartOfTheThird(): Repayment
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);

        $this->payOn('2026-02-10', 11800);
        $this->payOn('2026-03-10', 11800);

        $partial = $this->payOn('2026-04-10', 5000);
        $this->travelTo(Carbon::parse('2026-04-10 10:00'));

        return $partial;
    }

    /**
     * ₱60,000 at 3% for one month, paid at maturity, due a week from now so no
     * penalty takes part of the payment.
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
     * Refused with the message on `repayment`, and nothing of the void is
     * written: the payment, the periods, the loan, the books, the audit log
     * and the notifications are as they were.
     */
    private function assertVoidRefused(Repayment $repayment): void
    {
        $before = $this->snapshot();

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.repayment.0', self::REFUSAL);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('posted', $repayment->fresh()->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'repayments' => DB::table('repayments')->where('loan_id', $this->loan->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'schedules' => DB::table('amortization_schedules')->where('loan_id', $this->loan->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'loan' => (array) DB::table('loans')->where('id', $this->loan->id)->first(),
            'journals' => DB::table('accounting_journals')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'notifications' => DB::table('notifications')->count(),
            'share_capital_ledger' => DB::table('share_capital_ledger')->count(),
        ];
    }

    private function schedule(int $period): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $this->loan->id)->where('period_number', $period)->sole();
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
