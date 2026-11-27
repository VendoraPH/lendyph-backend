<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The data migration that records which periods existing payments paid.
 *
 * A payment made before `repayment_allocations` existed is simulated by making
 * it through the API and deleting the rows it wrote, which are also what the
 * migration has to reproduce.
 *
 * The loan: ₱60,000 over six months at 3% straight (₱10,000 principal and
 * ₱1,800 interest a period), 2% penalty, 3 days grace, started 2026-01-15.
 */
class BackfillRepaymentAllocationsTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = 'migrations/2026_10_01_150200_backfill_repayment_allocations.php';

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_it_records_what_each_legacy_payment_paid(): void
    {
        $this->payLegacyHistory();
        $recorded = $this->allocationsOf($this->loan);
        $this->forget($this->loan);

        $this->migration()->up();

        $this->assertSame($recorded, $this->allocationsOf($this->loan));
    }

    public function test_a_backfilled_payment_voids_only_the_periods_it_paid(): void
    {
        [$first] = $this->payLegacyHistory();
        $this->forget($this->loan);
        $this->migration()->up();

        $this->patchJson("/api/repayments/{$first->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();

        // The first payment's ₱1,800 interest and ₱3,200 principal come off
        // period 1; what the second payment put there stays.
        $period1 = $this->schedule($this->loan, 1);
        $this->assertMoney(0, $period1->interest_paid);
        $this->assertMoney(6800, $period1->principal_paid);
        $this->assertMoney(136, $period1->penalty_paid);
        $this->assertMoney(10000, $this->schedule($this->loan, 2)->principal_paid);
        $this->assertMoney(1264, $this->schedule($this->loan, 3)->interest_paid);
    }

    public function test_rows_already_recorded_are_untouched_and_a_second_run_changes_nothing(): void
    {
        $this->payLegacyHistory();
        $live = $this->createReleasedLoan(['start_date' => '2026-03-01']);
        $this->payOn($live, '2026-03-01', 5000);
        $liveRows = RepaymentAllocation::whereIn('repayment_id', $live->repayments()->pluck('id'))->get()->toArray();
        $this->forget($this->loan);

        $this->migration()->up();
        $afterFirst = RepaymentAllocation::orderBy('id')->get()->toArray();
        $this->migration()->up();

        $this->assertSame($afterFirst, RepaymentAllocation::orderBy('id')->get()->toArray());
        $this->assertSame($liveRows, RepaymentAllocation::whereIn('repayment_id', $live->repayments()->pluck('id'))->get()->toArray());
    }

    public function test_a_loan_whose_periods_disagree_with_its_payments_gets_no_rows_and_cannot_be_voided(): void
    {
        [$first] = $this->payLegacyHistory();
        $this->forget($this->loan);
        // What an extension deleting a partly paid period leaves behind:
        // money on a receipt that no period holds any more.
        $this->schedule($this->loan, 3)->update(['interest_paid' => 0]);

        $this->migration()->up();

        $this->assertSame([], $this->allocationsOf($this->loan));
        $this->patchJson("/api/repayments/{$first->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.repayment.0', 'This payment was recorded before Lendyph kept track of which periods each payment paid, and they cannot be worked out from this loan\'s history, so it cannot be voided. Use a balance adjustment to correct the loan instead.');
        $this->assertSame('posted', $first->fresh()->status);
    }

    public function test_a_loan_with_a_voided_payment_gets_no_rows(): void
    {
        [, $second] = $this->payLegacyHistory();
        $this->patchJson("/api/repayments/{$second->id}/void", ['void_reason' => 'Keyed in error'])->assertOk();
        $this->forget($this->loan);

        $this->migration()->up();

        $this->assertSame([], $this->allocationsOf($this->loan));
    }

    /**
     * ₱5,000 on 2026-02-10: period 1's ₱1,800 interest and ₱3,200 principal.
     * ₱20,000 on 2026-03-01, with period 1 late on its ₱6,800 (₱136 penalty):
     * period 1's penalty and principal, all of period 2, ₱1,264 of period 3's
     * interest.
     *
     * @return array{Repayment, Repayment}
     */
    private function payLegacyHistory(): array
    {
        return [
            $this->payOn($this->loan, '2026-02-10', 5000),
            $this->payOn($this->loan, '2026-03-01', 20000),
        ];
    }

    private function payOn(Loan $loan, string $date, float $amount): Repayment
    {
        $this->travelTo(Carbon::parse("{$date} 09:00"));

        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /**
     * Make the loan's payments look like they predate allocation rows.
     */
    private function forget(Loan $loan): void
    {
        RepaymentAllocation::whereIn('repayment_id', $loan->repayments()->pluck('id'))->delete();
    }

    /**
     * @return list<array{int, int, int, string, string, string}> [repayment, schedule, period, penalty, interest, principal]
     */
    private function allocationsOf(Loan $loan): array
    {
        return RepaymentAllocation::whereIn('repayment_id', $loan->repayments()->pluck('id'))
            ->orderBy('repayment_id')
            ->orderBy('period_number')
            ->get()
            ->map(fn (RepaymentAllocation $a) => [
                $a->repayment_id,
                $a->amortization_schedule_id,
                $a->period_number,
                $a->penalty,
                $a->interest,
                $a->principal,
            ])
            ->all();
    }

    private function schedule(Loan $loan, int $period): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', $period)->sole();
    }

    private function assertMoney(float|int|string $expected, float|int|string $actual): void
    {
        $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.001);
    }
}
