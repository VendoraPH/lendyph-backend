<?php

namespace Tests\Feature\CsvImport;

use App\Models\AmortizationSchedule;
use App\Services\CsvImport\LoanReconstructionInput;
use App\Services\CsvImport\LoanScheduleReconstructor;
use App\Services\CsvImport\ReconstructedSchedule;
use App\Services\LoanService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * The arithmetic that produces cooperative members' real balances.
 *
 * Every assertion on money here is assertSame on INTEGER CENTAVOS. Not
 * assertEqualsWithDelta, not a float comparison: "within a centavo" is a member
 * and a bookkeeper disagreeing, and delta assertions are exactly how that ships.
 */
class LoanScheduleReconstructorTest extends TestCase
{
    private function reconstructor(): LoanScheduleReconstructor
    {
        return new LoanScheduleReconstructor;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function input(array $overrides = []): LoanReconstructionInput
    {
        $defaults = [
            'principalCentavos' => 7000007,
            'balanceCentavos' => 3000003,
            'interestCentavos' => 1470015,
            'interestBalanceCentavos' => 630007,
            'interestRate' => '3.0000',
            'frequency' => 'monthly',
            'interestMethod' => 'straight',
            'dateReleased' => '2025-01-15',
            'maturityDate' => '2025-08-15',
            'termInMonths' => 7,
        ];

        return new LoanReconstructionInput(...array_merge($defaults, $overrides));
    }

    /**
     * @return list<int>
     */
    private function column(ReconstructedSchedule $schedule, string $property): array
    {
        return array_map(fn ($period) => $period->{$property}, $schedule->periods);
    }

    /**
     * @return list<string>
     */
    private function statuses(ReconstructedSchedule $schedule): array
    {
        return array_map(fn ($period) => $period->status, $schedule->periods);
    }

    public function test_six_months_weekly_becomes_a_term_of_26_not_6(): void
    {
        // `loans.term` is a PERIOD COUNT in units of `frequency`, not months.
        // Writing 6 here would have the app believe a six-month loan matures in
        // six weeks, and every non-monthly loan in the import would be wrong.
        $schedule = $this->reconstructor()->reconstruct($this->input([
            'frequency' => 'weekly',
            'dateReleased' => '2025-01-15',
            'maturityDate' => '2025-07-15',
            'termInMonths' => 6,
            'balanceCentavos' => 7000007,
            'interestBalanceCentavos' => 1470015,
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame(26, $schedule->term);
        $this->assertNotSame(6, $schedule->term);
        $this->assertCount(26, $schedule->periods);

        // 26 weeks from 15 Jan is 16 Jul, one day past the stated maturity, so
        // the last instalment takes the file's date verbatim and the drift is
        // reported rather than silently absorbed.
        $this->assertSame('2025-07-15', $schedule->periods[25]->dueDate);
        $this->assertContains('maturity_off_schedule', array_column(
            array_map(fn ($n) => $n->toArray(), $schedule->warnings), 'code'
        ));
    }

    public function test_every_frequency_lands_the_last_due_date_exactly_on_the_csv_maturity_date(): void
    {
        $loanService = new LoanService;
        $released = '2025-01-15';

        $expectations = [
            'daily' => 30,
            'weekly' => 26,
            'bi_weekly' => 13,
            'semi_monthly' => 12,
            'monthly' => 6,
            'upon_maturity' => 6,
        ];

        foreach ($expectations as $frequency => $expectedTerm) {
            $maturity = $loanService->computeMaturityDate($released, $expectedTerm, $frequency)->toDateString();

            $schedule = $this->reconstructor()->reconstruct($this->input([
                'frequency' => $frequency,
                'dateReleased' => $released,
                'maturityDate' => $maturity,
                'balanceCentavos' => 7000007,
                'interestBalanceCentavos' => 1470015,
            ]));

            $this->assertTrue($schedule->isValid(), "[{$frequency}] should reconstruct.");
            $this->assertSame($expectedTerm, $schedule->term, "[{$frequency}] term.");

            // maturity_date === the last schedule's due_date. The two are read
            // side by side on the promissory note, the disclosure statement and
            // the Due/Past Due report; a final instalment dated after the
            // stated maturity looks like a bug to every operator, forever.
            $last = $schedule->periods[count($schedule->periods) - 1];
            $this->assertSame($maturity, $last->dueDate, "[{$frequency}] last due date.");

            // A bullet loan is ONE row whatever its term — exactly what
            // LoanService::buildAmortizationPreview() emits for a released one.
            $expectedRows = $frequency === 'upon_maturity' ? 1 : $expectedTerm;
            $this->assertCount($expectedRows, $schedule->periods, "[{$frequency}] row count.");
        }
    }

    public function test_a_month_end_release_derives_the_term_from_computematuritydate_not_a_stepwise_walk(): void
    {
        // A six-month loan released 31 Aug 2024. Carbon here OVERFLOWS short
        // months rather than clamping them (31 Aug + 1 month = 1 Oct), and the
        // error compounds when you step one period at a time:
        //
        //   stepwise:  1 Oct, 1 Nov, 1 Dec, 1 Jan, 1 Feb, 1 Mar, 1 Apr
        //              -> first reaches 3 Mar at step SEVEN
        //   from start: 1 Oct, 31 Oct, 1 Dec, 31 Dec, 31 Jan, 3 Mar
        //              -> reaches 3 Mar at step SIX, exactly
        //
        // Six is the right answer, and it is right for a reason beyond
        // arithmetic: LoanService::updateLoan() recomputes maturity_date as
        // computeMaturityDate(start_date, term, frequency) on every edit. Any
        // term that does not reproduce the file's maturity date through THAT
        // function means the maturity date silently moves the first time an
        // operator opens the loan and saves it.
        $loanService = new LoanService;
        $released = '2024-08-31';
        $maturity = '2025-03-03';

        $stepwise = Carbon::parse($released);
        $stepwiseTerm = null;

        for ($k = 1; $k <= 24; $k++) {
            $stepwise = $stepwise->copy()->addMonth();

            if ($stepwise->gte(Carbon::parse($maturity))) {
                $stepwiseTerm = $k;
                break;
            }
        }

        $this->assertSame(7, $stepwiseTerm, 'The naive walk really does come out one period too long.');

        $schedule = $this->reconstructor()->reconstruct($this->input([
            'frequency' => 'monthly',
            'dateReleased' => $released,
            'maturityDate' => $maturity,
            'balanceCentavos' => 7000007,
            'interestBalanceCentavos' => 1470015,
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame(6, $schedule->term);
        $this->assertCount(6, $schedule->periods);
        $this->assertSame($maturity, $schedule->periods[5]->dueDate);
        $this->assertNotContains('maturity_off_schedule', array_column(
            array_map(fn ($n) => $n->toArray(), $schedule->warnings), 'code'
        ));

        // The round trip the app itself will perform on the next edit.
        $this->assertSame(
            $maturity,
            $loanService->computeMaturityDate($released, $schedule->term, 'monthly')->toDateString(),
        );
    }

    public function test_the_balance_invariants_hold_exactly_over_seven_periods_of_10000_01(): void
    {
        // ₱10,000.01 is not representable in binary floating point. Seven of
        // them summed as floats and compared to a stored balance is precisely
        // how a schedule ends up a centavo away from the member's passbook.
        $input = $this->input();
        $schedule = $this->reconstructor()->reconstruct($input);

        $this->assertTrue($schedule->isValid());
        $this->assertCount(7, $schedule->periods);

        // SUM(GREATEST(principal_due - principal_paid, 0)) — literally
        // Loan::$outstanding_balance and remainingPrincipalSql().
        $this->assertSame(3000003, $schedule->outstandingPrincipal());
        $this->assertSame($input->balanceCentavos, $schedule->outstandingPrincipal());

        // SUM(GREATEST(interest_due - interest_paid, 0)) — remainingInterestSql().
        $this->assertSame(630007, $schedule->outstandingInterest());
        $this->assertSame($input->interestBalanceCentavos, $schedule->outstandingInterest());

        // And the columns themselves add up to what the file said.
        $this->assertSame(7000007, $schedule->totalPrincipalDue());
        $this->assertSame(1470015, $schedule->totalInterestDue());

        $this->assertSame([1000001, 1000001, 1000001, 1000001, 1000001, 1000001, 1000001], $this->column($schedule, 'principalDue'));

        // floor(I/n) for k < n, remainder onto n: 1470015 / 7 = 210002 r1.
        $this->assertSame([210002, 210002, 210002, 210002, 210002, 210002, 210003], $this->column($schedule, 'interestDue'));

        foreach ($schedule->periods as $period) {
            $this->assertIsInt($period->principalDue);
            $this->assertIsInt($period->interestDue);
            $this->assertIsInt($period->principalPaid);
            $this->assertIsInt($period->interestPaid);
        }
    }

    public function test_principal_and_interest_may_run_out_on_different_periods(): void
    {
        // IB/I differs from B/P here, which is most loans that have ever had a
        // payment applied interest-first. Principal stops after period 4,
        // interest after period 6, and the periods in between are `partial`.
        $schedule = $this->reconstructor()->reconstruct($this->input([
            'interestBalanceCentavos' => 210003,
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame([1000001, 1000001, 1000001, 1000001, 0, 0, 0], $this->column($schedule, 'principalPaid'));
        $this->assertSame([210002, 210002, 210002, 210002, 210002, 210002, 0], $this->column($schedule, 'interestPaid'));
        $this->assertSame(['paid', 'paid', 'paid', 'paid', 'partial', 'partial', 'pending'], $this->statuses($schedule));

        $this->assertSame(3000003, $schedule->outstandingPrincipal());
        $this->assertSame(210003, $schedule->outstandingInterest());
    }

    public function test_principal_fully_paid_but_interest_short_is_partial_not_paid(): void
    {
        $schedule = $this->reconstructor()->reconstruct($this->input([
            'principalCentavos' => 1000000,
            'balanceCentavos' => 0,
            'interestCentavos' => 100000,
            'interestBalanceCentavos' => 50000,
            'dateReleased' => '2025-01-15',
            'maturityDate' => '2025-03-15',
            'termInMonths' => 2,
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame([500000, 500000], $this->column($schedule, 'principalDue'));
        $this->assertSame([500000, 500000], $this->column($schedule, 'principalPaid'));
        $this->assertSame([50000, 50000], $this->column($schedule, 'interestDue'));
        $this->assertSame([50000, 0], $this->column($schedule, 'interestPaid'));

        // The whole point. Period 2's principal is settled but ₱500 of interest
        // is not, and calling that row `paid` would take it out of
        // UNPAID_STATUSES — at which point the Due/Past Due report,
        // AutoPayService and RepaymentService all stop seeing interest the
        // borrower still owes. Not forgiven: invisible.
        $this->assertSame(['paid', 'partial'], $this->statuses($schedule));
        $this->assertContains('partial', AmortizationSchedule::UNPAID_STATUSES);
        $this->assertNotContains('paid', AmortizationSchedule::UNPAID_STATUSES);
        $this->assertSame(50000, $schedule->outstandingInterest());
    }

    public function test_a_balance_equal_to_the_amount_leaves_every_period_pending(): void
    {
        $schedule = $this->reconstructor()->reconstruct($this->input([
            'balanceCentavos' => 7000007,
            'interestBalanceCentavos' => 1470015,
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame(array_fill(0, 7, 'pending'), $this->statuses($schedule));
        $this->assertSame(array_fill(0, 7, 0), $this->column($schedule, 'principalPaid'));
        $this->assertSame(7000007, $schedule->outstandingPrincipal());
        $this->assertSame(1470015, $schedule->outstandingInterest());
    }

    public function test_a_zero_balance_leaves_every_period_paid(): void
    {
        $schedule = $this->reconstructor()->reconstruct($this->input([
            'balanceCentavos' => 0,
            'interestBalanceCentavos' => 0,
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame(array_fill(0, 7, 'paid'), $this->statuses($schedule));
        $this->assertSame(0, $schedule->outstandingPrincipal());
        $this->assertSame(0, $schedule->outstandingInterest());
    }

    public function test_an_allocation_residue_returns_null_so_the_caller_aborts_the_row(): void
    {
        $reconstructor = $this->reconstructor();

        // More paid principal than the schedule can absorb.
        $this->assertNull($reconstructor->allocate([100, 100], [50, 50], 300, 100));

        // More paid interest than the schedule can absorb.
        $this->assertNull($reconstructor->allocate([100, 100], [50, 50], 200, 500));

        // Negative remainders are incoherent, not merely unallocatable.
        $this->assertNull($reconstructor->allocate([100, 100], [50, 50], -1, 100));

        // The allocatable case still works, oldest first.
        $this->assertSame(
            ['principal_paid' => [100, 50], 'interest_paid' => [50, 0]],
            $reconstructor->allocate([100, 100], [50, 50], 150, 50),
        );
    }

    public function test_incoherent_balances_fail_the_row_with_a_named_reason(): void
    {
        $tooBig = $this->reconstructor()->reconstruct($this->input(['balanceCentavos' => 9999999]));
        $this->assertFalse($tooBig->isValid());
        $this->assertSame(['balance_out_of_range'], array_column(array_map(fn ($n) => $n->toArray(), $tooBig->errors), 'code'));

        $interestTooBig = $this->reconstructor()->reconstruct($this->input(['interestBalanceCentavos' => 9999999]));
        $this->assertFalse($interestTooBig->isValid());
        $this->assertSame(['interest_balance_out_of_range'], array_column(array_map(fn ($n) => $n->toArray(), $interestTooBig->errors), 'code'));

        $backwards = $this->reconstructor()->reconstruct($this->input(['maturityDate' => '2024-01-01']));
        $this->assertFalse($backwards->isValid());
        $this->assertSame(['maturity_not_after_release'], array_column(array_map(fn ($n) => $n->toArray(), $backwards->errors), 'code'));
    }

    public function test_zero_interest_reconstructs_cleanly(): void
    {
        $schedule = $this->reconstructor()->reconstruct($this->input([
            'interestCentavos' => 0,
            'interestBalanceCentavos' => 0,
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame(0, $schedule->totalInterestDue());
        $this->assertSame(0, $schedule->outstandingInterest());
    }

    public function test_a_diminishing_loan_still_sums_to_the_principal_exactly(): void
    {
        // The app's diminishing generator is float PMT arithmetic. Forcing the
        // last period to P minus the sum of the rest is what makes
        // SUM(principal_due) === P true by construction rather than by luck.
        $schedule = $this->reconstructor()->reconstruct($this->input([
            'interestMethod' => 'diminishing',
        ]));

        $this->assertTrue($schedule->isValid());
        $this->assertSame(7000007, $schedule->totalPrincipalDue());
        $this->assertSame(3000003, $schedule->outstandingPrincipal());
        $this->assertNotSame(
            $schedule->periods[0]->principalDue,
            $schedule->periods[6]->principalDue,
            'A diminishing ladder should not be flat — the app generator was actually used.',
        );
    }

    public function test_schedule_rows_are_exact_decimal_strings_with_zero_penalties(): void
    {
        $rows = $this->reconstructor()->reconstruct($this->input())->toScheduleRows();

        $this->assertSame('10000.01', $rows[0]['principal_due']);
        $this->assertSame('2100.02', $rows[0]['interest_due']);
        $this->assertSame('12100.03', $rows[0]['total_due']);
        $this->assertSame('60000.06', $rows[0]['remaining_balance']);
        $this->assertSame('0.00', $rows[6]['remaining_balance']);
        $this->assertSame(1, $rows[0]['period_number']);
        $this->assertSame('2025-08-15', $rows[6]['due_date']);

        // Penalties are zero on every imported row: the export does not carry
        // them, and inventing charges nobody levied is not a rounding decision.
        foreach ($rows as $row) {
            $this->assertSame('0.00', $row['penalty_amount']);
            $this->assertSame('0.00', $row['penalty_paid']);
        }
    }
}
