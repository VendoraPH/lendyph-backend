<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use App\Services\LoanTermSchedule;
use App\Services\RepaymentService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Monthly due dates are anchored to the loan's original day of the month,
 * capped at the last day of a shorter month: 31 Jan -> 28 Feb (29 in a leap
 * year) -> 31 Mar -> 30 Apr.
 *
 * Each date is computed from the start date and the instalment number. The
 * schedule used to chain one overflowing month onto the previous due date, so
 * a loan started 30 Sep fell due 2 Mar and 2 Apr — after its own maturity
 * date. A loan started on day 1–28 never overflowed, and must come out exactly
 * as it always has.
 *
 * A schedule continuing from an existing row keeps the loan's anchor day. A
 * row an overflowing month step pushed into the first days of the next month
 * (3 Mar for February) continues at that month's anchored date, 31 Mar rather
 * than 30 Apr; every other row continues in the following month. Stored rows
 * are never rewritten.
 */
class AnchoredMonthlyDueDatesTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * The schedule's old algorithm: one overflowing month after the previous
     * due date. Kept here as the reference the day 1–28 loans must still match.
     *
     * @return list<string>
     */
    private function chainedMonthSteps(Carbon $start, int $term): array
    {
        $dates = [];
        $date = $start->copy();

        for ($i = 1; $i <= $term; $i++) {
            $date = $date->copy()->addMonth();
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * @return list<string>
     */
    private function dueDates(string $start, int $term, string $frequency = 'monthly', ?int $anchorDay = null): array
    {
        return array_map(
            fn (array $instalment) => $instalment['due_date']->toDateString(),
            LoanTermSchedule::instalments(Carbon::parse($start), $term, 'months', $frequency, $anchorDay),
        );
    }

    /**
     * An unsaved months-term loan, carrying only what buildAmortizationPreview() reads.
     */
    private function unsavedLoan(string $start, string $interestMethod, int $term = 6): Loan
    {
        return new Loan([
            'principal_amount' => 60000,
            'interest_rate' => 3.0,
            'interest_rate_frequency' => 'monthly',
            'interest_method' => $interestMethod,
            'frequency' => 'monthly',
            'term' => $term,
            'term_unit' => 'months',
            'start_date' => $start,
        ]);
    }

    /**
     * A released loan, by default a six-month straight loan paying monthly,
     * whose first instalment has been paid in full through the real repayment
     * path.
     */
    private function releasedLoanWithFirstInstalmentPaid(array $overrides): Loan
    {
        $loan = $this->createReleasedLoan($overrides);
        $first = $loan->amortizationSchedules->sortBy('period_number')->first();

        app(RepaymentService::class)->processRepayment(
            $loan,
            (float) $first->total_due,
            $first->due_date->toDateString(),
            $this->admin,
        );

        $this->assertSame('paid', $first->fresh()->status);

        return $loan->fresh();
    }

    private function applyAdjustment(Loan $loan, array $payload): void
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", $payload)->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();
    }

    /**
     * @return array<int, string>
     */
    private function storedDueDates(Loan $loan): array
    {
        return $loan->amortizationSchedules()
            ->orderBy('period_number')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->period_number => $row->due_date->toDateString()])
            ->all();
    }

    /**
     * Store due dates the anchored schedule would not produce: the ones the
     * old, overflowing schedule stored, or an imported file's own maturity
     * date. Stored rows are never rewritten, so live loans still carry them.
     *
     * @param  array<int, string>  $dueDatesByPeriod
     */
    private function overwriteStoredDueDates(Loan $loan, array $dueDatesByPeriod, string $maturityDate): void
    {
        foreach ($dueDatesByPeriod as $period => $dueDate) {
            $loan->amortizationSchedules()->where('period_number', $period)->update(['due_date' => $dueDate]);
        }

        $loan->update(['maturity_date' => $maturityDate]);
    }

    /**
     * Settle every row up to `$lastPeriod` outright, the state a loan paid on
     * schedule is in when an adjustment rebuilds the rest.
     */
    private function markPaidThrough(Loan $loan, int $lastPeriod): void
    {
        $loan->amortizationSchedules()->where('period_number', '<=', $lastPeriod)->get()->each(
            fn ($row) => $row->update([
                'principal_paid' => $row->principal_due,
                'interest_paid' => $row->interest_due,
                'status' => 'paid',
            ]),
        );
    }

    // ── LoanTermSchedule ─────────────────────────────────────────────────

    /**
     * @return array<string, array{string, int, list<string>}>
     */
    public static function monthEndStarts(): array
    {
        return [
            'the 31st into a leap February' => ['2028-01-31', 4, ['2028-02-29', '2028-03-31', '2028-04-30', '2028-05-31']],
            'the 31st into a common February' => ['2027-01-31', 4, ['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31']],
            'the 31st across 30-day months' => ['2026-08-31', 4, ['2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31']],
            'the 30th across February' => ['2026-09-30', 6, ['2026-10-30', '2026-11-30', '2026-12-30', '2027-01-30', '2027-02-28', '2027-03-30']],
            'the 29th across a common February' => ['2026-08-29', 7, ['2026-09-29', '2026-10-29', '2026-11-29', '2026-12-29', '2027-01-29', '2027-02-28', '2027-03-29']],
            'the 29th across a leap February' => ['2027-12-29', 3, ['2028-01-29', '2028-02-29', '2028-03-29']],
            'the 28th never moves' => ['2027-01-28', 3, ['2027-02-28', '2027-03-28', '2027-04-28']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('monthEndStarts')]
    public function test_a_month_end_start_keeps_its_day_capped_at_each_months_end(string $start, int $term, array $expected): void
    {
        foreach (['monthly', 'upon_maturity'] as $frequency) {
            $this->assertSame($expected, $this->dueDates($start, $term, $frequency), "[{$frequency}] due dates.");
            $this->assertSame(
                end($expected),
                LoanTermSchedule::maturityDate(Carbon::parse($start), $term, 'months', $frequency)->toDateString(),
                "[{$frequency}] maturity is the last due date.",
            );
        }

        // Every calendar-month instalment is still charged as one 30-day month.
        $this->assertSame(
            array_fill(0, $term, LoanTermSchedule::DAYS_PER_MONTH),
            array_column(LoanTermSchedule::instalments(Carbon::parse($start), $term, 'months', 'monthly'), 'days'),
        );
    }

    public function test_the_primitive_lands_on_the_anchor_day_capped_at_the_months_end(): void
    {
        $cases = [
            ['2027-01-31', 1, 31, '2027-02-28'],
            ['2028-01-31', 1, 31, '2028-02-29'],
            ['2027-02-28', 1, 31, '2027-03-31'],
            ['2027-02-28', 2, 31, '2027-04-30'],
            ['2027-02-28', 0, 31, '2027-02-28'],
            ['2026-11-30', 3, 29, '2027-02-28'],
            ['2026-12-15', 14, 15, '2028-02-15'],
        ];

        foreach ($cases as [$from, $months, $anchorDay, $expected]) {
            $this->assertSame(
                $expected,
                LoanTermSchedule::calendarMonthDueDate(Carbon::parse($from), $months, $anchorDay)->toDateString(),
                "{$months} month(s) after {$from} on day {$anchorDay}.",
            );
        }
    }

    public function test_the_next_anchored_date_after_a_row_never_skips_a_month(): void
    {
        $cases = [
            // On the anchor, or capped to it: whole months on.
            ['2027-01-31', 1, 31, '2027-02-28'],
            ['2027-02-28', 1, 31, '2027-03-31'],
            ['2027-02-28', 2, 31, '2027-04-30'],
            ['2027-01-15', 1, 15, '2027-02-15'],
            ['2027-01-15', 12, 15, '2028-01-15'],
            // Overflowed rows the old schedule stored, day 1-3 with an anchor
            // of 29-31: that same month's anchored date comes first.
            ['2027-03-03', 1, 31, '2027-03-31'],
            ['2027-03-03', 2, 31, '2027-04-30'],
            ['2027-03-01', 1, 29, '2027-03-29'],
            ['2027-03-01', 2, 29, '2027-04-29'],
            ['2027-05-01', 1, 31, '2027-05-31'],
            // Any other row off the anchor continues in the following month,
            // whether it sits before the anchor day or after it.
            ['2027-02-14', 1, 15, '2027-03-15'],
            ['2027-03-29', 1, 30, '2027-04-30'],
            ['2027-03-20', 1, 15, '2027-04-15'],
            ['2027-03-02', 1, 15, '2027-04-15'],
            ['2027-03-04', 1, 31, '2027-04-30'],
        ];

        foreach ($cases as [$from, $n, $anchorDay, $expected]) {
            $this->assertSame(
                $expected,
                LoanTermSchedule::nthAnchoredDateAfter(Carbon::parse($from), $n, $anchorDay)->toDateString(),
                "anchored date {$n} after {$from} on day {$anchorDay}.",
            );
        }
    }

    public function test_a_schedule_rebuilt_from_an_overflowed_row_starts_that_same_month(): void
    {
        $this->assertSame(['2027-03-29', '2027-04-29', '2027-05-29'], $this->dueDates('2027-03-01', 3, anchorDay: 29));
        $this->assertSame(['2027-03-31', '2027-04-30'], $this->dueDates('2027-03-03', 2, anchorDay: 31));
        $this->assertSame(
            '2027-05-29',
            LoanTermSchedule::maturityDate(Carbon::parse('2027-03-01'), 3, 'months', 'monthly', 29)->toDateString(),
        );
    }

    public function test_an_explicit_anchor_day_outlives_a_capped_start(): void
    {
        // A schedule rebuilt from a paid 28 Feb row of a loan started on the
        // 31st carries on at the 31st, not the 28th.
        $this->assertSame(['2027-03-31', '2027-04-30', '2027-05-31'], $this->dueDates('2027-02-28', 3, anchorDay: 31));
        $this->assertSame(
            '2027-05-31',
            LoanTermSchedule::maturityDate(Carbon::parse('2027-02-28'), 3, 'months', 'monthly', 31)->toDateString(),
        );

        // Without one, the start's own day is the anchor.
        $this->assertSame(['2027-03-28', '2027-04-28', '2027-05-28'], $this->dueDates('2027-02-28', 3));
    }

    public function test_a_start_on_day_1_to_28_gives_the_same_dates_as_the_old_chained_steps(): void
    {
        foreach (['2027-01', '2027-02', '2027-06', '2027-11', '2027-12', '2028-01', '2028-02', '2028-08'] as $month) {
            for ($day = 1; $day <= 28; $day++) {
                $start = Carbon::parse(sprintf('%s-%02d', $month, $day));

                foreach ([1, 2, 6, 12, 25] as $term) {
                    $label = "{$start->toDateString()} over {$term} month(s)";

                    $this->assertSame($this->chainedMonthSteps($start, $term), $this->dueDates($start->toDateString(), $term), $label);
                    $this->assertSame(
                        $start->copy()->addMonths($term)->toDateString(),
                        LoanTermSchedule::maturityDate($start, $term, 'months', 'monthly')->toDateString(),
                        $label,
                    );
                }
            }
        }
    }

    public function test_a_mid_month_preview_keeps_its_amounts_to_the_centavo(): void
    {
        $service = app(LoanService::class);

        $straight = $service->buildAmortizationPreview($this->unsavedLoan('2027-03-14', 'straight'));
        $this->assertSame(
            ['2027-04-14', '2027-05-14', '2027-06-14', '2027-07-14', '2027-08-14', '2027-09-14'],
            array_column($straight, 'due_date'),
        );
        $this->assertEquals([10000, 10000, 10000, 10000, 10000, 10000], array_column($straight, 'principal_due'));
        $this->assertEquals([1800, 1800, 1800, 1800, 1800, 1800], array_column($straight, 'interest_due'));
        $this->assertEquals([50000, 40000, 30000, 20000, 10000, 0], array_column($straight, 'remaining_balance'));

        $diminishing = $service->buildAmortizationPreview($this->unsavedLoan('2027-03-14', 'diminishing'));
        $this->assertEquals([11075.85, 11075.85, 11075.85, 11075.85, 11075.85, 11075.85], array_column($diminishing, 'total_due'));
        $this->assertEquals([1800, 1521.72, 1235.1, 939.88, 635.8, 322.6], array_column($diminishing, 'interest_due'));

        $interestOnly = $service->buildAmortizationPreview($this->unsavedLoan('2027-03-14', 'upon_maturity'));
        $this->assertEquals([0, 0, 0, 0, 0, 60000], array_column($interestOnly, 'principal_due'));
        $this->assertEquals([1800, 1800, 1800, 1800, 1800, 1800], array_column($interestOnly, 'interest_due'));
    }

    public function test_a_month_end_preview_charges_what_a_mid_month_one_does(): void
    {
        $service = app(LoanService::class);

        foreach (['straight', 'diminishing', 'upon_maturity'] as $method) {
            $midMonth = $service->buildAmortizationPreview($this->unsavedLoan('2027-01-15', $method));
            $monthEnd = $service->buildAmortizationPreview($this->unsavedLoan('2027-01-31', $method));

            $withoutDates = fn (array $rows) => array_map(fn (array $row) => array_diff_key($row, ['due_date' => true]), $rows);

            $this->assertSame($withoutDates($midMonth), $withoutDates($monthEnd), "[{$method}] amounts.");
            $this->assertSame(
                ['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31'],
                array_column($monthEnd, 'due_date'),
                "[{$method}] due dates.",
            );
        }
    }

    // ── Create, preview and release ──────────────────────────────────────

    public function test_a_loan_released_on_the_31st_stores_anchored_rows_ending_on_its_maturity(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => '2027-01-31']);

        $this->assertSame(
            [1 => '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30', '2027-07-31'],
            $this->storedDueDates($loan),
        );
        $this->assertSame('2027-07-31', $loan->maturity_date->toDateString());
    }

    public function test_a_one_month_loan_on_the_31st_matures_at_the_end_of_february(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['term' => 1, 'frequency' => 'upon_maturity'],
            'start_date' => '2028-01-31',
        ]);

        $this->assertSame([1 => '2028-02-29'], $this->storedDueDates($loan));
        $this->assertSame('2028-02-29', $loan->maturity_date->toDateString());
    }

    public function test_the_amortization_preview_endpoint_returns_anchored_dates(): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 4,
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 40000,
            'start_date' => '2026-10-31',
        ], $this->admin);

        $this->assertSame('2027-02-28', $loan->fresh()->maturity_date->toDateString());

        $rows = $this->getJson("/api/loans/{$loan->id}/amortization-preview")->assertOk()->json('data');

        $this->assertSame(['2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28'], array_column($rows, 'due_date'));
    }

    // ── One-month extension ──────────────────────────────────────────────

    public function test_extending_a_one_month_loan_on_the_31st_keeps_rolling_on_the_31st(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly'],
            'start_date' => '2027-01-31',
        ]);
        $this->assertSame([1 => '2027-02-28'], $this->storedDueDates($loan));

        foreach (['2027-03-31', '2027-04-30', '2027-05-31'] as $expected) {
            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

            $loan->refresh();
            $open = $loan->amortizationSchedules()->whereIn('status', ['pending', 'partial', 'overdue'])->get();

            $this->assertCount(1, $open);
            $this->assertSame($expected, $open->first()->due_date->toDateString());
            $this->assertSame($expected, $loan->maturity_date->toDateString());
        }
    }

    public function test_extending_a_single_payment_loan_on_the_31st_keeps_rolling_on_the_31st(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['term' => 1, 'frequency' => 'upon_maturity'],
            'start_date' => '2027-01-31',
        ]);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();
        $this->assertSame('2027-03-31', $loan->fresh()->maturity_date->toDateString());

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();
        $this->assertSame('2027-04-30', $loan->fresh()->maturity_date->toDateString());
    }

    public function test_extending_from_an_overflowed_row_rolls_to_the_same_months_anchor(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly'],
            'start_date' => '2027-01-31',
        ]);

        // Released before the anchor rule: 31 Jan + 1 month overflowed to 3 Mar.
        $this->overwriteStoredDueDates($loan, [1 => '2027-03-03'], '2027-03-03');

        // 3 Mar stands for February, so the next cycle is March's, not April's.
        foreach (['2027-03-31', '2027-04-30'] as $expected) {
            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

            $loan->refresh();
            $open = $loan->amortizationSchedules()->whereIn('status', ['pending', 'partial', 'overdue'])->get();

            $this->assertCount(1, $open);
            $this->assertSame($expected, $open->first()->due_date->toDateString());
            $this->assertSame($expected, $loan->maturity_date->toDateString());
        }
    }

    public function test_extending_from_an_imported_maturity_short_of_the_anchor_rolls_a_whole_month(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly'],
            'start_date' => '2027-01-15',
        ]);

        // An imported file's 30-day maturity, a day short of the anchor.
        $this->overwriteStoredDueDates($loan, [1 => '2027-02-14'], '2027-02-14');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        // Next month on the anchor, not 15 Feb: one day would be charged as a month.
        $this->assertSame('2027-03-15', $loan->fresh()->maturity_date->toDateString());
    }

    // ── In-place adjustments ─────────────────────────────────────────────

    public function test_a_term_extension_after_a_paid_february_row_keeps_the_31st(): void
    {
        $loan = $this->releasedLoanWithFirstInstalmentPaid(['start_date' => '2026-01-31']);

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 2],
        ]);

        $loan->refresh();

        // Five unpaid rows plus two, rebuilt from the paid 28 Feb row on the
        // loan's own anchor day rather than on the 28th.
        $this->assertSame(
            [1 => '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30'],
            $this->storedDueDates($loan),
        );
        $this->assertSame(8, $loan->term);
        $this->assertSame('2026-09-30', $loan->maturity_date->toDateString());
    }

    public function test_a_restructure_after_a_paid_february_row_keeps_the_31st(): void
    {
        $loan = $this->releasedLoanWithFirstInstalmentPaid(['start_date' => '2026-01-31']);

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 3, 'interest_rate' => 2.0],
        ]);

        $loan->refresh();

        $this->assertSame(
            [1 => '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            $this->storedDueDates($loan),
        );
        $this->assertSame(4, $loan->term);
        $this->assertSame('2026-05-31', $loan->maturity_date->toDateString());
    }

    public function test_a_restructure_continues_from_the_latest_paid_row(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => '2026-01-31']);
        $this->markPaidThrough($loan, 2);

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 2],
        ]);

        $loan->refresh();

        // From the paid 31 Mar row, not the first paid row (28 Feb), which
        // would have dated the new rows on top of the paid ones.
        $this->assertSame(
            [1 => '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            $this->storedDueDates($loan),
        );
        $this->assertSame('2026-05-31', $loan->maturity_date->toDateString());
    }

    public function test_a_restructure_to_a_weekly_frequency_still_steps_by_days_from_the_paid_row(): void
    {
        $loan = $this->releasedLoanWithFirstInstalmentPaid(['start_date' => '2026-01-31']);

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 3, 'frequency' => 'weekly'],
        ]);

        $loan->refresh();

        $this->assertSame(
            [1 => '2026-02-28', '2026-03-07', '2026-03-14', '2026-03-21'],
            $this->storedDueDates($loan),
        );
        $this->assertSame('days', $loan->term_unit->value);
        $this->assertSame('2026-03-21', $loan->maturity_date->toDateString());
    }

    public function test_a_term_extension_after_a_paid_overflowed_row_resumes_that_month(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['term' => 8],
            'start_date' => '2025-08-29',
        ]);

        // Released before the anchor rule: 29 Jan + 1 month overflowed to
        // 1 Mar 2026, and every later row stepped on from there.
        $this->overwriteStoredDueDates($loan, [6 => '2026-03-01', 7 => '2026-04-01', 8 => '2026-05-01'], '2026-04-29');

        $this->markPaidThrough($loan, 6);

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 1],
        ]);

        $loan->refresh();

        // Two unpaid rows plus one, rebuilt from the paid 1 Mar row on the 29th:
        // March is not skipped, and the paid rows are left exactly as stored.
        $this->assertSame(
            [
                1 => '2025-09-29', '2025-10-29', '2025-11-29', '2025-12-29', '2026-01-29', '2026-03-01',
                '2026-03-29', '2026-04-29', '2026-05-29',
            ],
            $this->storedDueDates($loan),
        );
        $this->assertSame(9, $loan->term);
        $this->assertSame('2026-05-29', $loan->maturity_date->toDateString());
    }

    public function test_a_term_extension_on_a_single_payment_loan_moves_its_one_row_with_the_maturity(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['term' => 1, 'frequency' => 'upon_maturity'],
            'start_date' => '2027-01-31',
        ]);

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 2],
        ]);

        $loan->refresh();

        // The single payment is dated from `maturity_date`, so it moves to the
        // new maturity rather than staying on the one it replaced.
        $this->assertSame([1 => '2027-04-30'], $this->storedDueDates($loan));
        $this->assertSame(3, $loan->term);
        $this->assertSame('2027-04-30', $loan->maturity_date->toDateString());
    }

    public function test_a_term_extension_on_a_days_term_monthly_loan_matures_on_its_last_30_day_row(): void
    {
        $loan = $this->releasedLoanWithFirstInstalmentPaid([
            'product' => ['term' => 90, 'term_unit' => 'days'],
            'start_date' => '2026-03-01',
        ]);
        $this->assertSame([1 => '2026-03-31', '2026-04-30', '2026-05-30'], $this->storedDueDates($loan));

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 1],
        ]);

        $loan->refresh();

        // A days term steps by 30 days, not calendar months, and the stored
        // maturity is where those steps end.
        $this->assertSame(
            [1 => '2026-03-31', '2026-04-30', '2026-05-30', '2026-06-29'],
            $this->storedDueDates($loan),
        );
        $this->assertSame(120, $loan->term);
        $this->assertSame('2026-06-29', $loan->maturity_date->toDateString());
    }
}
