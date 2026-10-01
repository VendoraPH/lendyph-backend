<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use App\Services\ExtensionPeriodRebuilder;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The data fix for periods extensions deleted before 2026-10-02.
 *
 * Each loan is first extended the way the app does now, then put back into
 * the state older code left: the period the extension closed is deleted and
 * the payments keep no allocation rows, as on portfolio staging's LN-000001,
 * LN-000003, LN-000007, LN-000012 and LN-000013 and binhs staging's LN-000002.
 *
 * The loans: ₱60,000 at 3% for one month, paid at maturity, so the first
 * period is due ₱60,000 principal and ₱1,800 interest.
 */
class RebuildExtensionPeriodsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-08-04 09:00'));
    }

    public function test_it_rebuilds_the_period_an_extension_deleted_after_collecting_its_interest(): void
    {
        $loan = $this->oneMonthLoan();
        $this->extend($loan, 'pay');
        $this->forgetClosedPeriods($loan);
        $this->assertTotalPaid($loan, 0);

        $this->rebuild();

        $period = $this->period($loan, 1);
        $this->assertSame('2026-09-04', $period->due_date->toDateString());
        $this->assertEqualsWithDelta(1800, (float) $period->interest_due, 0.001);
        $this->assertEqualsWithDelta(1800, (float) $period->interest_paid, 0.001);
        $this->assertEqualsWithDelta(0, (float) $period->principal_due, 0.001);
        $this->assertSame('paid', $period->status);
        $this->assertSame(LoanAdjustment::where('loan_id', $loan->id)->sole()->id, $period->closed_by_adjustment_id);

        $this->assertTotalPaid($loan, 1800);
        $this->assertSame(
            [[1, $period->id, 1800.0]],
            $this->allocations(Repayment::where('loan_id', $loan->id)->sole()),
        );
        $this->assertSame(1, AuditLog::where('action', ExtensionPeriodRebuilder::AUDIT_ACTION)->where('auditable_id', $loan->id)->count());
    }

    public function test_a_payment_between_extensions_goes_back_on_the_period_open_when_it_was_made(): void
    {
        $loan = $this->oneMonthLoan();
        $this->extend($loan, 'defer');                    // period 2: ₱60,000 + ₱3,600
        $this->travelTo(Carbon::parse('2026-08-10 09:00'));
        $payment = $this->pay($loan, 10000);               // ₱3,600 interest, ₱6,400 principal on period 2
        $this->travelTo(Carbon::parse('2026-08-12 09:00'));
        $this->extend($loan, 'defer');                     // closes period 2, opens period 3
        $this->extend($loan, 'defer');                     // period 4
        $this->forgetClosedPeriods($loan);

        $this->rebuild();

        $period = $this->period($loan, 2);
        $this->assertSame('2026-10-04', $period->due_date->toDateString());
        $this->assertEqualsWithDelta(3600, (float) $period->interest_paid, 0.001);
        $this->assertEqualsWithDelta(6400, (float) $period->principal_paid, 0.001);
        $this->assertSame([2, 4], AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')->pluck('period_number')->all());
        $this->assertTotalPaid($loan, 10000);
        $this->assertSame([[2, $period->id, 10000.0]], $this->allocations($payment));

        // Voidable now: the period it paid exists again and owes it back.
        $this->patchJson("/api/repayments/{$payment->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();
        $this->assertEqualsWithDelta(0, (float) $period->fresh()->interest_paid, 0.001);
    }

    public function test_a_loan_a_restructure_closed_into_a_new_loan_is_rebuilt_from_its_original_schedule(): void
    {
        $loan = $this->oneMonthLoan();
        $this->pay($loan, 1800);                           // all of period 1's interest
        $this->extend($loan, 'defer');                     // closes period 1, opens period 2
        $this->forgetClosedPeriods($loan);
        AmortizationSchedule::where('loan_id', $loan->id)->delete();
        $loan->update(['status' => 'restructured']);

        $this->rebuild();

        $period = $this->period($loan, 1);
        $this->assertEqualsWithDelta(1800, (float) $period->interest_paid, 0.001);
        $this->assertTotalPaid($loan, 1800);
    }

    public function test_a_loan_a_restructure_renumbered_is_left_as_it_is(): void
    {
        $loan = $this->oneMonthLoan();
        $this->extend($loan, 'pay');
        $this->forgetClosedPeriods($loan);
        LoanAdjustment::create([
            'loan_id' => $loan->id,
            'adjustment_type' => 'restructure',
            'old_values' => [],
            'new_values' => ['term' => 3],
            'status' => 'applied',
            'adjusted_by' => $this->admin->id,
            'applied_at' => now(),
        ]);
        $before = $this->rows($loan);

        $result = app(ExtensionPeriodRebuilder::class)->run(dryRun: false);

        $this->assertSame($before, $this->rows($loan));
        $this->assertSame(
            [['loan' => $loan->loan_account_number, 'reason' => 'a restructure or term extension numbered its new periods over the ones it deleted']],
            $result['skipped'],
        );
    }

    public function test_loans_whose_payments_reconcile_are_untouched(): void
    {
        $extended = $this->oneMonthLoan();
        $this->extend($extended, 'pay');                   // closed by today's code, nothing missing
        $paid = $this->oneMonthLoan();
        $this->pay($paid, 5000);
        $before = [$this->rows($extended), $this->rows($paid), RepaymentAllocation::count()];

        $result = app(ExtensionPeriodRebuilder::class)->run(dryRun: false);

        $this->assertSame([[], []], [$result['rebuilt'], $result['skipped']]);
        $this->assertSame($before, [$this->rows($extended), $this->rows($paid), RepaymentAllocation::count()]);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $loan = $this->oneMonthLoan();
        $this->extend($loan, 'pay');
        $this->forgetClosedPeriods($loan);

        $this->rebuild();
        $after = [$this->rows($loan), RepaymentAllocation::count(), AuditLog::count()];

        $this->artisan('loans:rebuild-extension-periods')
            ->expectsOutputToContain('Rebuilt 0 period(s) on 0 loan(s); 0 loan(s) with missing payments left as they are.')
            ->assertSuccessful();

        $this->assertSame($after, [$this->rows($loan), RepaymentAllocation::count(), AuditLog::count()]);
    }

    public function test_a_dry_run_lists_the_period_and_writes_nothing(): void
    {
        $loan = $this->oneMonthLoan();
        $this->extend($loan, 'pay');
        $this->forgetClosedPeriods($loan);
        $before = [$this->rows($loan), RepaymentAllocation::count(), AuditLog::count()];
        $extension = LoanAdjustment::where('loan_id', $loan->id)->sole();
        $receipt = Repayment::where('loan_id', $loan->id)->sole()->receipt_number;

        $this->artisan('loans:rebuild-extension-periods', ['--dry-run' => true])
            ->expectsOutputToContain("{$loan->loan_account_number} period 1 due 2026-09-04: principal 0.00, interest 1,800.00, penalty 0.00, closed by {$extension->adjustment_number}, paid by {$receipt}")
            ->expectsOutputToContain('Would rebuild 1 period(s) on 1 loan(s)')
            ->assertSuccessful();

        $this->assertSame($before, [$this->rows($loan), RepaymentAllocation::count(), AuditLog::count()]);
    }

    private function oneMonthLoan(): Loan
    {
        return $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => 60000,
            'start_date' => '2026-08-04',
        ]);
    }

    private function extend(Loan $loan, string $interestOption): void
    {
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => $interestOption])->assertOk();
    }

    private function pay(Loan $loan, float $amount): Repayment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /**
     * Put the loan back as older code left it: the periods its extensions
     * closed are gone, and its payments record no periods.
     */
    private function forgetClosedPeriods(Loan $loan): void
    {
        AmortizationSchedule::where('loan_id', $loan->id)->whereNotNull('closed_by_adjustment_id')->delete();
        RepaymentAllocation::whereIn('repayment_id', Repayment::where('loan_id', $loan->id)->pluck('id'))->delete();
    }

    private function rebuild(): void
    {
        $this->artisan('loans:rebuild-extension-periods')->assertSuccessful();
    }

    private function period(Loan $loan, int $number): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', $number)->sole();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Loan $loan): array
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->orderBy('id')->get()->toArray();
    }

    /**
     * @return list<array{int, int, float}> [period, schedule id, amount]
     */
    private function allocations(Repayment $repayment): array
    {
        return RepaymentAllocation::where('repayment_id', $repayment->id)
            ->orderBy('period_number')
            ->get()
            ->map(fn (RepaymentAllocation $a) => [$a->period_number, $a->amortization_schedule_id, round((float) $a->penalty + (float) $a->interest + (float) $a->principal, 2)])
            ->all();
    }

    private function assertTotalPaid(Loan $loan, float $expected): void
    {
        $totalPaid = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data.total_paid');

        $this->assertEqualsWithDelta($expected, (float) $totalPaid, 0.001, 'total paid');
    }
}
