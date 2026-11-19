<?php

namespace Tests\Feature\Phase6\ExecutiveFigures;

use App\Models\Loan;
use App\Services\PortfolioRisk;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-A: PortfolioRisk, the one PAR and loan-aging service.
 *
 * PAR n is the unpaid principal of the loans whose oldest unpaid period is
 * more than n days past its bare due date (PAR 1: 1 day or more), over the
 * same loans' unpaid principal. PAR 30 is the Loan Balance Summary's existing
 * `at_risk_amount` and `par_ratio`, unchanged. Aging by loan puts each loan in
 * one bucket by its oldest unpaid period, LoanAgingBuilder's buckets.
 *
 * Each loan is ₱60,000.00 over 6 periods of ₱10,000.00 principal and
 * ₱1,800.00 interest, with a 3-day grace.
 */
class PortfolioRiskTest extends TestCase
{
    use SetupLendyPH;

    /** Days past due of each loan's first period, one loan each. */
    private const DAYS_LATE = [0, 1, 30, 31, 60, 61, 90, 91, 121];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    }

    public function test_par_1_30_60_and_90_count_each_loan_by_its_oldest_unpaid_period(): void
    {
        $this->bookOfLateLoans();

        $summary = $this->summary();

        // 9 loans, ₱540,000.00. More than 0, 30, 60 and 90 days late: 8, 6, 4 and 2 loans.
        $this->assertEquals(['amount' => 480000, 'ratio' => 88.89, 'loans' => 8], $summary['par_1']);
        $this->assertEquals(['amount' => 360000, 'ratio' => 66.67, 'loans' => 6], $summary['par_30']);
        $this->assertEquals(['amount' => 240000, 'ratio' => 44.44, 'loans' => 4], $summary['par_60']);
        $this->assertEquals(['amount' => 120000, 'ratio' => 22.22, 'loans' => 2], $summary['par_90']);
    }

    public function test_par_30_is_the_existing_at_risk_amount_and_par_ratio(): void
    {
        $this->bookOfLateLoans();

        $summary = $this->summary();

        $this->assertEquals(360000, $summary['at_risk_amount']);
        $this->assertEquals(66.67, $summary['par_ratio']);
        $this->assertSame($summary['at_risk_amount'], $summary['par_30']['amount']);
        $this->assertSame($summary['par_ratio'], $summary['par_30']['ratio']);
        $this->assertSame(30, $summary['par_threshold_days']);
    }

    public function test_par_counts_the_whole_unpaid_principal_of_a_late_loan_and_ignores_grace(): void
    {
        $loan = $this->createReleasedLoan();
        $this->dueDaysAgo($loan, 1, 2);
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $summary = $this->summary();

        // Within the 3-day grace, so not past due, but 2 days late: PAR 1. The
        // ₱5,000.00 paid ₱1,800.00 interest first, then ₱3,200.00 principal,
        // and the whole ₱56,800.00 still unpaid counts, not just the late period.
        $this->assertEquals(['amount' => 56800, 'ratio' => 100.0, 'loans' => 1], $summary['par_1']);
        $this->assertEquals(0, $summary['past_due_amount']);
        $this->assertSame(0, $summary['past_due_loan_count']);
    }

    public function test_a_defaulted_loan_is_at_risk_and_a_paid_off_loan_is_not_in_the_portfolio(): void
    {
        $defaulted = $this->createReleasedLoan();
        $this->dueDaysAgo($defaulted, 1, 100);
        DB::table('loans')->where('id', $defaulted->id)->update(['status' => 'defaulted']);

        $paidOff = $this->createReleasedLoan();
        app(RepaymentService::class)->processRepayment($paidOff->fresh(), 70800, now()->toDateString(), $this->admin);

        $par = app(PortfolioRisk::class)->par(Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES));

        $this->assertEquals(60000, $par['portfolio_principal']);
        $this->assertEquals(['amount' => 60000.0, 'ratio' => 100.0, 'loans' => 1], $par['par_90']);
    }

    public function test_an_empty_book_reads_zero_at_risk(): void
    {
        $summary = $this->summary();

        foreach (['par_1', 'par_30', 'par_60', 'par_90'] as $key) {
            $this->assertEquals(['amount' => 0, 'ratio' => 0, 'loans' => 0], $summary[$key], $key);
        }

        $this->assertSame(0, $summary['aging_by_loan']['totals']['loans']);
        $this->assertNull($summary['aging_by_loan']['buckets'][0]['share_percent']);
    }

    public function test_aging_by_loan_keeps_the_120_day_ladder_and_adds_up_to_the_portfolio(): void
    {
        $this->bookOfLateLoans();

        $aging = $this->summary()['aging_by_loan'];

        $this->assertSame('2026-11-19', $aging['as_of_date']);
        $this->assertSame(
            ['current', '1_30', '31_60', '61_90', '91_120', 'over_120'],
            array_column($aging['buckets'], 'bucket'),
        );
        $this->assertSame(
            ['Current', '1–30 days', '31–60 days', '61–90 days', '91–120 days', 'Over 120 days'],
            array_column($aging['buckets'], 'label'),
        );
        $this->assertSame([1, 2, 2, 2, 1, 1], array_column($aging['buckets'], 'loans'));
        $this->assertEquals([60000, 120000, 120000, 120000, 60000, 60000], array_column($aging['buckets'], 'principal'));
        $this->assertEquals([11.11, 22.22, 22.22, 22.22, 11.11, 11.11], array_column($aging['buckets'], 'share_percent'));
        $this->assertSame(9, $aging['totals']['loans']);
        $this->assertEquals($this->summary()['outstanding_principal'], $aging['totals']['principal']);
    }

    public function test_par_states_its_own_denominator(): void
    {
        $this->bookOfLateLoans();

        $summary = $this->summary();

        $this->assertEquals(540000, $summary['par_portfolio_principal']);
        $this->assertSame($summary['outstanding']['principal'], $summary['par_portfolio_principal']);
    }

    public function test_par_1_equals_the_aging_buckets_past_current_beside_completed_and_restructured_loans(): void
    {
        $this->bookOfLateLoans();

        $completed = $this->createReleasedLoan();
        app(RepaymentService::class)->processRepayment($completed->fresh(), 70800, now()->toDateString(), $this->admin);
        $this->assertSame('completed', $completed->fresh()->status);

        // A restructure closes the source loan's open periods.
        $restructured = $this->createReleasedLoan();
        $this->dueDaysAgo($restructured, 1, 45);
        DB::table('amortization_schedules')->where('loan_id', $restructured->id)->update([
            'principal_paid' => DB::raw('principal_due'),
            'interest_paid' => DB::raw('interest_due'),
            'status' => 'paid',
        ]);
        DB::table('loans')->where('id', $restructured->id)->update(['status' => 'restructured']);

        $summary = $this->summary();
        $pastCurrent = collect($summary['aging_by_loan']['buckets'])->reject(fn (array $bucket) => $bucket['bucket'] === 'current');

        $this->assertEquals(480000, $summary['par_1']['amount']);
        $this->assertEquals($pastCurrent->sum('principal'), $summary['par_1']['amount']);
        $this->assertSame($pastCurrent->sum('loans'), $summary['par_1']['loans']);
    }

    /**
     * Where the two rules part, each is the documented one: PAR measures every
     * ever-released loan from its oldest period still marked unpaid; the aging
     * measures collectible loans from their oldest period with something left
     * owing (PortfolioRisk's docblock).
     */
    public function test_par_and_the_aging_differ_only_where_their_documented_rules_do(): void
    {
        // A completed loan with a stray unpaid period: in PAR, not in the aging.
        $stray = $this->createReleasedLoan();
        $this->dueDaysAgo($stray, 1, 10);
        DB::table('loans')->where('id', $stray->id)->update(['status' => 'completed']);

        // A period marked partial with nothing left owing, 40 days ago, before
        // an owing period 10 days ago: PAR dates the loan from the first, the
        // aging from the second.
        $marked = $this->createReleasedLoan();
        $this->dueDaysAgo($marked, 1, 40);
        $this->dueDaysAgo($marked, 2, 10);
        DB::table('amortization_schedules')->where('loan_id', $marked->id)->where('period_number', 1)->update([
            'principal_paid' => DB::raw('principal_due'),
            'interest_paid' => DB::raw('interest_due'),
            'status' => 'partial',
        ]);

        $summary = $this->summary();
        $buckets = collect($summary['aging_by_loan']['buckets'])->keyBy('bucket');

        $this->assertEquals(['amount' => 110000, 'ratio' => 100.0, 'loans' => 2], $summary['par_1']);
        $this->assertEquals(['amount' => 50000, 'ratio' => 45.45, 'loans' => 1], $summary['par_30']);
        $this->assertSame(1, $buckets['1_30']['loans'], 'The marked loan, by its owing period; the completed loan is not aged.');
        $this->assertSame(0, $buckets['31_60']['loans']);
        $this->assertEquals(50000, $summary['aging_by_loan']['totals']['principal']);
    }

    public function test_past_due_is_the_past_due_loans_page_s_own_figure(): void
    {
        $this->bookOfLateLoans();
        $this->createReleasedLoan()->update(['insurance_remaining_balance' => 400]);

        $summary = $this->summary();
        $page = $this->getJson('/api/reports/due-past-due?past_due_only=1&per_page=100')->assertOk();
        $pageLoans = collect($page->json('data'))->pluck('loan_id')->unique();

        // Past grace (3 days): the loans 30 days late and more, ₱11,800.00 each.
        $this->assertEquals(82600, $summary['past_due_amount']);
        $this->assertSame(7, $summary['past_due_loan_count']);
        $this->assertSame($page->json('totals.past_due_amount'), $summary['past_due_amount']);
        $this->assertSame($pageLoans->count(), $summary['past_due_loan_count']);
    }

    public function test_past_due_is_as_of_today_whatever_the_release_dates(): void
    {
        $this->bookOfLateLoans();

        $summary = $this->getJson('/api/reports/loan-balance-summary?date_from=2020-01-01&date_to=2020-12-31')->assertOk()->json('data');
        $page = $this->getJson('/api/reports/due-past-due?past_due_only=1&per_page=1')->assertOk();

        $this->assertSame(0, $summary['portfolio']['loan_count'], 'No loan was released in 2020.');
        $this->assertEquals(82600, $summary['past_due_amount']);
        $this->assertSame($page->json('totals.past_due_amount'), $summary['past_due_amount']);
        $this->assertSame(7, $summary['past_due_loan_count']);
    }

    private function bookOfLateLoans(): void
    {
        foreach (self::DAYS_LATE as $days) {
            $this->dueDaysAgo($this->createReleasedLoan(), 1, $days);
        }
    }

    private function dueDaysAgo(Loan $loan, int $period, int $days): void
    {
        DB::table('amortization_schedules')
            ->where('loan_id', $loan->id)
            ->where('period_number', $period)
            ->update(['due_date' => Carbon::today()->subDays($days)->toDateString()]);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');
    }
}
