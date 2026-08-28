<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `days_overdue` on the Due / Past Due report honours the loan's grace period.
 *
 * `grace_period_days` is printed on the promissory note and the disclosure
 * statement, and was honoured by nothing: a borrower one day past a due date
 * with a seven-day grace window was reported as one day late and chased as if
 * they were. This widens a rule the field already followed — the report has
 * always said "due today is not late" — from a single day to the contractual
 * window.
 *
 * The row STAYS in the report throughout. Grace governs lateness, not
 * owed-ness: the money is due and collections have to see it. That split is
 * duePastDueQuery()'s, and this field is where the distinction gets drawn.
 *
 * The boundary is defined once, in AmortizationSchedule::pastGraceCutoff() and
 * its SQL twin pastGraceSql(), and read here and by
 * ReportService::listOfDuePastDueTotals(). These tests assert both sides of that
 * contract together, because the failure mode worth guarding is not either one
 * being wrong on its own — it is a page of rows all reading 0 days overdue
 * above a totals block claiming several are late.
 */
class DuePastDueGraceTest extends TestCase
{
    use SetupLendyPH;

    private const GRACE = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @return array<string, array{0: int, 1: int}> daysAgo => expected days_overdue
     */
    public static function graceWindow(): array
    {
        return [
            'due today' => [0, 0],
            'first day of grace' => [1, 0],
            'last day of grace' => [self::GRACE, 0],
            'the day grace expires' => [self::GRACE + 1, 1],
            'a day after that' => [self::GRACE + 2, 2],
            'long past grace' => [45, 45 - self::GRACE],
        ];
    }

    #[DataProvider('graceWindow')]
    public function test_days_overdue_counts_from_grace_expiry_and_the_row_is_listed_throughout(int $daysAgo, int $expected): void
    {
        $loan = $this->graceLoan(self::GRACE);
        $schedule = $this->moveSchedule($loan, 1, $daysAgo);

        $response = $this->getJson('/api/reports/due-past-due')->assertOk();
        $rows = $response->json('data');

        // The row is present for every day in the window, not just once it is
        // late. Deleting rows during grace would hide live receivables from
        // collections.
        $this->assertCount(1, $rows, 'The schedule must stay in the report throughout grace.');
        $this->assertSame($schedule->id, $rows[0]['id']);

        $this->assertSame($expected, $rows[0]['days_overdue']);
        $this->assertSame($expected, $rows[0]['days_past_due'], 'The two aliases must never disagree.');

        // And the totals block draws the same line, via the SQL twin.
        $this->assertSame(1, $response->json('totals.count'), 'Owed is owed, grace or not.');
        $this->assertSame(
            $expected > 0 ? 1 : 0,
            $response->json('totals.overdue_count'),
            'overdue_count must equal the number of rows whose days_overdue exceeds 0.',
        );
    }

    /**
     * The regression check: grace 0 collapses the cutoff back to today, so the
     * figure is the plain due-date difference it always was.
     */
    public function test_a_loan_with_no_grace_behaves_exactly_as_before(): void
    {
        $loan = $this->graceLoan(0);
        $this->moveSchedule($loan, 1, 12);

        $response = $this->getJson('/api/reports/due-past-due')->assertOk();

        $this->assertSame(12, $response->json('data.0.days_overdue'));
        $this->assertSame(1, $response->json('totals.overdue_count'));
    }

    public function test_a_loan_with_no_grace_is_late_the_day_after_it_is_due(): void
    {
        $loan = $this->graceLoan(0);
        $this->moveSchedule($loan, 1, 1);

        $response = $this->getJson('/api/reports/due-past-due')->assertOk();

        $this->assertSame(1, $response->json('data.0.days_overdue'));
        $this->assertSame(1, $response->json('totals.overdue_count'));
    }

    /**
     * Two loans on different products, in one response: the cutoff is per-loan,
     * not one figure applied to the page.
     */
    public function test_grace_is_read_per_loan_not_per_report(): void
    {
        $lenient = $this->graceLoan(30);
        $strict = $this->graceLoan(0);
        $this->moveSchedule($lenient, 1, 10);
        $this->moveSchedule($strict, 1, 10);

        $response = $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();
        $byLoan = collect($response->json('data'))->keyBy('loan_id');

        $this->assertSame(0, $byLoan[$lenient->id]['days_overdue'], 'Inside a 30-day window.');
        $this->assertSame(10, $byLoan[$strict->id]['days_overdue'], 'No window at all.');

        $this->assertSame(2, $response->json('totals.count'));
        $this->assertSame(1, $response->json('totals.overdue_count'), 'Only the strict loan is late.');
    }

    /**
     * The totals block and the rows must agree on a mixed page — the specific
     * contradiction the shared cutoff exists to prevent.
     */
    public function test_overdue_count_equals_the_rows_reporting_a_positive_figure(): void
    {
        $loan = $this->graceLoan(self::GRACE);
        $this->moveSchedule($loan, 1, 2);                    // inside grace
        $this->moveSchedule($loan, 2, self::GRACE);          // last day of grace
        $this->moveSchedule($loan, 3, self::GRACE + 1);      // 1 day late
        $this->moveSchedule($loan, 4, 60);                   // 53 days late

        $response = $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();
        $rows = $response->json('data');

        $this->assertCount(4, $rows, 'All four are owed and all four are listed.');

        $late = count(array_filter(array_column($rows, 'days_overdue'), fn (int $d) => $d > 0));

        $this->assertSame(2, $late);
        $this->assertSame($late, $response->json('totals.overdue_count'));
    }

    /**
     * Reading grace off the loan must not cost a query per row: this report
     * pages to 1,000.
     *
     * Measured as the DIFFERENCE between two page sizes rather than as an
     * absolute number, so it stays true when an unrelated change adds or
     * removes a fixed query.
     */
    public function test_grace_is_read_from_the_eager_loaded_loan_without_an_n_plus_one(): void
    {
        $loan = $this->graceLoan(self::GRACE);
        $this->moveSchedule($loan, 1, 30);

        // One unmeasured call first. The first request of a test warms caches
        // that have nothing to do with row count — permissions in particular —
        // and would otherwise show up as a difference between the two samples.
        $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();

        $oneRow = $this->queriesForDuePastDue(expectedRows: 1);

        // Four more rows on a SECOND loan, so a per-row lazy load of the loan's
        // grace would have four more loans to go and fetch.
        $second = $this->graceLoan(self::GRACE);
        for ($period = 1; $period <= 4; $period++) {
            $this->moveSchedule($second, $period, 30 + $period);
        }

        $fiveRows = $this->queriesForDuePastDue(expectedRows: 5);

        $this->assertSame(
            $oneRow,
            $fiveRows,
            "Query count went from {$oneRow} to {$fiveRows} between 1 and 5 rows. grace_period_days is "
            .'being lazy loaded per row; it is already on the eager loaded `loans` row, so reading it '
            .'off $s->loan should cost nothing.',
        );
    }

    /**
     * Queries issued while rendering the report, counted from a clean log.
     */
    private function queriesForDuePastDue(int $expectedRows): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/reports/due-past-due?per_page=100')
            ->assertOk()
            ->assertJsonCount($expectedRows, 'data');

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function graceLoan(int $graceDays): Loan
    {
        return $this->createReleasedLoan(['product' => ['grace_period_days' => $graceDays]]);
    }

    private function moveSchedule(Loan $loan, int $periodNumber, int $daysAgo): AmortizationSchedule
    {
        $schedule = $loan->amortizationSchedules()
            ->where('period_number', $periodNumber)
            ->firstOrFail();

        $schedule->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);

        return $schedule->fresh();
    }
}
