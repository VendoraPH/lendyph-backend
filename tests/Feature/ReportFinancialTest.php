<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\LoanService;
use Carbon\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reconciliation coverage for the seven financial reports.
 *
 * Every figure asserted here is computed BY HAND in the test from the fixture,
 * never read back out of the report and compared to itself. The point of this
 * suite is not "the endpoint answers" — ReportEndpointTest-style shape checks
 * cannot tell a right number from a wrong one — it is that the totals, the
 * breakdowns and the neighbouring reports all agree.
 *
 * Loan shape used throughout (see releaseLoan): straight interest, 3% per
 * period, 2% processing + 1% service fee withheld at release. So a 60,000 /
 * 6-month loan bills 10,000 principal + 1,800 interest = 11,800 per period and
 * pays out 60,000 - 1,800 = 58,200.
 */
class ReportFinancialTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Cash Flow ────────────────────────────────────────────────────────

    public function test_cash_flow_components_reconcile_and_by_branch_carries_loan_cash_only(): void
    {
        $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
        $product = $this->product();

        // Main: 60,000 gross, 1,800 withheld, 58,200 paid out.
        $main = $this->releaseLoan(['product' => $product, 'principal' => 60000]);
        // Satellite: 30,000 gross, 900 withheld, 29,100 paid out.
        $remote = $this->releaseLoan(['product' => $product, 'principal' => 30000, 'branch' => $satellite]);

        // 5,000 against a 1,800-interest / 10,000-principal instalment.
        $this->postRepayment($main, 5000);
        // 3,000 against a 900-interest / 5,000-principal instalment.
        $this->postRepayment($remote, 3000);

        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $today = Carbon::today()->toDateString();
        $this->ledgerEntry($member, $today, credit: 4000);
        $this->ledgerEntry($member, $today, debit: 1500);

        $data = $this->getJson('/api/reports/cash-flow')->assertOk()->json('data');

        $this->assertSame($today, $data['date_from']);
        $this->assertSame($today, $data['date_to']);

        // ── Inflows: the four components add up to the repayment total.
        $repayments = $data['inflows']['repayments'];
        $this->assertEqualsWithDelta(5300, $repayments['principal'], 0.01, '3,200 + 2,100 principal applied.');
        $this->assertEqualsWithDelta(2700, $repayments['interest'], 0.01, '1,800 + 900 interest applied.');
        $this->assertEqualsWithDelta(0, $repayments['penalty'], 0.01, 'Nothing was late, so no penalty was collected.');
        $this->assertEqualsWithDelta(0, $repayments['overpayment'], 0.01);
        $this->assertEqualsWithDelta(8000, $repayments['total'], 0.01, '5,000 + 3,000 across the counter.');
        $this->assertSame(2, $repayments['count']);
        $this->assertEqualsWithDelta(
            $repayments['total'],
            $repayments['principal'] + $repayments['interest'] + $repayments['penalty'] + $repayments['overpayment'],
            0.01,
            'The four repayment components must add back up to the repayment total exactly.',
        );

        $this->assertEqualsWithDelta(4000, $data['inflows']['share_capital_credit'], 0.01);
        $this->assertEqualsWithDelta(12000, $data['inflows']['total'], 0.01, '8,000 loan cash + 4,000 share capital.');

        // ── Outflows: net proceeds, never gross principal.
        $this->assertEqualsWithDelta(87300, $data['outflows']['releases']['net_proceeds'], 0.01, '58,200 + 29,100.');
        $this->assertEqualsWithDelta(87300, $data['outflows']['releases']['total'], 0.01);
        $this->assertSame(2, $data['outflows']['releases']['count']);
        $this->assertEqualsWithDelta(1500, $data['outflows']['share_capital_debit'], 0.01);
        $this->assertEqualsWithDelta(88800, $data['outflows']['total'], 0.01, '87,300 cash out + 1,500 share capital.');

        // ── Net movement is exactly inflows less outflows.
        $this->assertEqualsWithDelta(-76800, $data['net_movement'], 0.01);
        $this->assertEqualsWithDelta(
            $data['inflows']['total'] - $data['outflows']['total'],
            $data['net_movement'],
            0.01,
            'net_movement must be inflows.total - outflows.total and nothing else.',
        );

        // ── The non-cash identity: gross principal = cash out + what was withheld.
        $this->assertEqualsWithDelta(90000, $data['non_cash']['principal_released'], 0.01);
        $this->assertEqualsWithDelta(2700, $data['non_cash']['total_deductions'], 0.01, '1,800 + 900 in fees.');
        $this->assertEqualsWithDelta(
            $data['non_cash']['principal_released'],
            $data['outflows']['releases']['net_proceeds'] + $data['non_cash']['total_deductions'],
            0.01,
            'principal_released must equal net_proceeds + total_deductions.',
        );

        // ── Share capital is reported at organisation level, with no branch.
        $this->assertSame('organisation', $data['share_capital']['branch_scope']);
        $this->assertEqualsWithDelta(4000, $data['share_capital']['credit'], 0.01);
        $this->assertEqualsWithDelta(1500, $data['share_capital']['debit'], 0.01);
        $this->assertEqualsWithDelta(2500, $data['share_capital']['net_movement'], 0.01);
        $this->assertSame(2, $data['share_capital']['count']);

        // ── by_branch is LOAN cash only. This is the assertion that matters:
        // the branch rows reconcile with inflows.repayments.total, NOT with
        // inflows.total, because share capital has no branch to belong to.
        $branches = collect($data['by_branch'])->keyBy('branch_id');
        $this->assertCount(2, $branches);

        $mainRow = $branches[$this->branch->id];
        $this->assertEqualsWithDelta(3200, $mainRow['inflow_principal'], 0.01);
        $this->assertEqualsWithDelta(1800, $mainRow['inflow_interest'], 0.01);
        $this->assertEqualsWithDelta(5000, $mainRow['inflow_total'], 0.01);
        $this->assertSame(1, $mainRow['repayment_count']);
        $this->assertEqualsWithDelta(58200, $mainRow['outflow_total'], 0.01);
        $this->assertEqualsWithDelta(1800, $mainRow['total_deductions'], 0.01);
        $this->assertEqualsWithDelta(-53200, $mainRow['net_movement'], 0.01);

        $remoteRow = $branches[$satellite->id];
        $this->assertEqualsWithDelta(3000, $remoteRow['inflow_total'], 0.01);
        $this->assertEqualsWithDelta(29100, $remoteRow['outflow_total'], 0.01);
        $this->assertEqualsWithDelta(900, $remoteRow['total_deductions'], 0.01);
        $this->assertEqualsWithDelta(-26100, $remoteRow['net_movement'], 0.01);

        $branchInflow = array_sum(array_column($data['by_branch'], 'inflow_total'));
        $this->assertEqualsWithDelta(
            $repayments['total'],
            $branchInflow,
            0.01,
            'by_branch must sum to inflows.repayments.total — every peso of loan cash belongs to exactly one branch.',
        );
        $this->assertNotEqualsWithDelta(
            $data['inflows']['total'],
            $branchInflow,
            0.01,
            'by_branch must NOT sum to inflows.total: the 4,000 share capital credit is org-level and has no branch.',
        );

        $branchOutflow = array_sum(array_column($data['by_branch'], 'outflow_total'));
        $this->assertEqualsWithDelta($data['outflows']['releases']['total'], $branchOutflow, 0.01);
        $this->assertNotEqualsWithDelta(
            $data['outflows']['total'],
            $branchOutflow,
            0.01,
            'The 1,500 share capital debit is org-level and must stay out of by_branch.',
        );

        $this->assertEqualsWithDelta(
            -79300,
            array_sum(array_column($data['by_branch'], 'net_movement')),
            0.01,
            "by_branch's net differs from the headline net by exactly the org-level share capital net of +2,500.",
        );
    }

    public function test_cash_flow_excludes_deductions_from_every_total(): void
    {
        // 100,000 principal with a flat 10,000 withheld: only 90,000 leaves the till.
        $this->releaseLoan([
            'principal' => 100000,
            'deductions' => [['name' => 'Service Charge', 'amount' => 10000, 'type' => 'fixed']],
        ]);

        $data = $this->getJson('/api/reports/cash-flow')->assertOk()->json('data');

        $this->assertEqualsWithDelta(90000, $data['outflows']['releases']['net_proceeds'], 0.01);
        $this->assertEqualsWithDelta(90000, $data['outflows']['releases']['total'], 0.01);
        $this->assertEqualsWithDelta(90000, $data['outflows']['total'], 0.01, 'Cash out is 90,000, not the 100,000 booked.');
        $this->assertEqualsWithDelta(-90000, $data['net_movement'], 0.01);

        $this->assertEqualsWithDelta(100000, $data['non_cash']['principal_released'], 0.01);
        $this->assertEqualsWithDelta(10000, $data['non_cash']['total_deductions'], 0.01);
        $this->assertEqualsWithDelta(
            $data['non_cash']['principal_released'],
            $data['outflows']['releases']['net_proceeds'] + $data['non_cash']['total_deductions'],
            0.01,
        );

        // The withheld fee is reported per branch too, and is still excluded
        // from that branch's outflow.
        $this->assertCount(1, $data['by_branch']);
        $this->assertEqualsWithDelta(10000, $data['by_branch'][0]['total_deductions'], 0.01);
        $this->assertEqualsWithDelta(90000, $data['by_branch'][0]['outflow_total'], 0.01);
        $this->assertEqualsWithDelta(-90000, $data['by_branch'][0]['net_movement'], 0.01);
    }

    // ── Collection Efficiency ────────────────────────────────────────────

    public function test_collection_efficiency_rate_cannot_exceed_100_percent_under_a_branch_filter(): void
    {
        $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
        $product = $this->product();

        // Main bills 11,800 today and collects 5,900 of it.
        $billed = $this->releaseLoan(['product' => $product]);
        $this->moveScheduleDaysAgo($billed, periodNumber: 1, daysAgo: 0);
        $this->postRepayment($billed, 5900);

        // Satellite bills NOTHING today but takes 30,000 across the counter.
        // If the collected side were not branch-scoped, this money would land
        // against Main's 11,800 of dues and push its rate to 304%.
        $paying = $this->releaseLoan(['product' => $product, 'branch' => $satellite]);
        $this->postRepayment($paying, 30000);

        $today = Carbon::today()->toDateString();
        $window = "date_from={$today}&date_to={$today}";

        $filtered = $this->getJson("/api/reports/collection-efficiency?{$window}&branch_id={$this->branch->id}")
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(11800, $filtered['total_due'], 0.01);
        $this->assertEqualsWithDelta(
            5900,
            $filtered['total_collected'],
            0.01,
            "The satellite's 30,000 must not be counted against Main's dues.",
        );
        $this->assertEqualsWithDelta(50.0, $filtered['collection_rate'], 0.01);
        $this->assertEqualsWithDelta(5900, $filtered['uncollected'], 0.01);
        $this->assertLessThanOrEqual(
            100.0,
            $filtered['collection_rate'],
            'A branch-filtered rate above 100% means only one thing: the two sides of the ratio are scoped differently.',
        );
        $this->assertNotEqualsWithDelta(
            304.24,
            $filtered['collection_rate'],
            0.01,
            '304.24% is what an unscoped numerator would produce here.',
        );

        // The filtered report shows that branch and no other.
        $this->assertCount(1, $filtered['by_branch']);
        $this->assertSame($this->branch->id, $filtered['by_branch'][0]['branch_id']);
        $this->assertEqualsWithDelta(50.0, $filtered['by_branch'][0]['collection_rate'], 0.01);

        // Unfiltered, the ORG-level rate legitimately exceeds 100% — one branch
        // paid down arrears billed outside the window. The per-branch rows stay
        // correctly scoped even then, which is the actual guard.
        $orgWide = $this->getJson("/api/reports/collection-efficiency?{$window}")->assertOk()->json('data');
        $this->assertEqualsWithDelta(11800, $orgWide['total_due'], 0.01);
        $this->assertEqualsWithDelta(35900, $orgWide['total_collected'], 0.01);
        $this->assertEqualsWithDelta(304.24, $orgWide['collection_rate'], 0.01);

        $rows = collect($orgWide['by_branch'])->keyBy('branch_id');
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(11800, $rows[$this->branch->id]['total_due'], 0.01);
        $this->assertEqualsWithDelta(5900, $rows[$this->branch->id]['total_collected'], 0.01);
        $this->assertEqualsWithDelta(50.0, $rows[$this->branch->id]['collection_rate'], 0.01);
        $this->assertEqualsWithDelta(0, $rows[$satellite->id]['total_due'], 0.01);
        $this->assertEqualsWithDelta(30000, $rows[$satellite->id]['total_collected'], 0.01);
        $this->assertEqualsWithDelta(
            0.0,
            $rows[$satellite->id]['collection_rate'],
            0.01,
            'Nothing billed means no rate to report, not a division by zero.',
        );
    }

    public function test_collection_efficiency_by_period_bucket_may_legitimately_exceed_100_percent(): void
    {
        $loan = $this->releaseLoan();

        $monthOne = Carbon::today()->startOfMonth()->subMonths(2);
        $monthTwo = Carbon::today()->startOfMonth()->subMonth();

        // 11,800 billed in each of the two months...
        $this->setScheduleDueDate($loan, periodNumber: 1, dueDate: $monthOne->copy()->addDays(14));
        $this->setScheduleDueDate($loan, periodNumber: 2, dueDate: $monthTwo->copy()->addDays(14));
        // ...and 20,000 paid in the SECOND month, settling both.
        $this->postRepayment($loan, 20000, $monthTwo->copy()->addDays(19)->toDateString());

        $from = $monthOne->toDateString();
        $to = $monthTwo->copy()->endOfMonth()->toDateString();

        $data = $this->getJson("/api/reports/collection-efficiency?date_from={$from}&date_to={$to}")
            ->assertOk()->json('data');

        // Over the whole window nothing is odd: 20,000 against 23,600 billed.
        $this->assertEqualsWithDelta(23600, $data['total_due'], 0.01);
        $this->assertEqualsWithDelta(20000, $data['total_collected'], 0.01);
        $this->assertEqualsWithDelta(84.75, $data['collection_rate'], 0.01);

        $periods = collect($data['by_period'])->keyBy('period');
        $this->assertCount(2, $periods);

        $first = $periods[$monthOne->format('Y-m')];
        $this->assertEqualsWithDelta(11800, $first['total_due'], 0.01);
        $this->assertEqualsWithDelta(0, $first['total_collected'], 0.01);
        $this->assertEqualsWithDelta(0.0, $first['collection_rate'], 0.01);
        $this->assertEqualsWithDelta(11800, $first['uncollected'], 0.01);

        $second = $periods[$monthTwo->format('Y-m')];
        $this->assertEqualsWithDelta(11800, $second['total_due'], 0.01);
        $this->assertEqualsWithDelta(20000, $second['total_collected'], 0.01);
        $this->assertEqualsWithDelta(
            169.49,
            $second['collection_rate'],
            0.01,
            'A monthly bucket ABOVE 100% is correct here: the month collected its own 11,800 plus the previous '
            ."month's arrears. This is a timing effect and must not be \"fixed\" by clamping the rate.",
        );
        $this->assertGreaterThan(100.0, $second['collection_rate']);
        $this->assertEqualsWithDelta(
            0,
            $second['uncollected'],
            0.01,
            'uncollected floors at zero rather than going negative when a bucket over-collects.',
        );
    }

    // ── Loan Portfolio by Product ────────────────────────────────────────

    public function test_portfolio_by_product_totals_match_loan_balance_summary(): void
    {
        // TWELVE schedules on the salary loan: if the report ever regresses to
        // joining amortization_schedules straight onto loans, this product's
        // released figure becomes 60,000 x 12 = 720,000 and the test screams.
        $salary = $this->product(['name' => 'Salary Loan', 'term' => 12]);
        $emergency = $this->product(['name' => 'Emergency Loan', 'term' => 3]);

        $salaryLoan = $this->releaseLoan(['product' => $salary, 'principal' => 60000]);
        $emergencyLoan = $this->releaseLoan(['product' => $emergency, 'principal' => 30000]);

        // Only the salary loan is past the 30-day PAR threshold.
        $this->moveScheduleDaysAgo($salaryLoan, periodNumber: 1, daysAgo: 45);

        $portfolio = $this->getJson('/api/reports/portfolio-by-product')->assertOk()->json('data');
        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');

        // ── The four figures that must agree, report to report.
        $this->assertEqualsWithDelta(90000, $portfolio['totals']['total_released'], 0.01);
        $this->assertEqualsWithDelta(
            $summary['portfolio']['total_released'],
            $portfolio['totals']['total_released'],
            0.01,
            'A direct schedule join would inflate this to 60,000 x 12 + 30,000 x 3 = 810,000.',
        );

        $this->assertSame(2, $portfolio['totals']['loan_count']);
        $this->assertSame($summary['portfolio']['loan_count'], $portfolio['totals']['loan_count']);

        $this->assertEqualsWithDelta(90000, $portfolio['totals']['outstanding'], 0.01);
        $this->assertEqualsWithDelta($summary['outstanding']['balance'], $portfolio['totals']['outstanding'], 0.01);

        $this->assertEqualsWithDelta(66.67, $portfolio['totals']['par_ratio'], 0.01, '60,000 at risk over 90,000 outstanding.');
        $this->assertEqualsWithDelta($summary['par_ratio'], $portfolio['totals']['par_ratio'], 0.01);
        $this->assertEqualsWithDelta($summary['at_risk_amount'], $portfolio['totals']['at_risk_amount'], 0.01);
        $this->assertSame(30, $portfolio['par_threshold_days']);
        $this->assertEqualsWithDelta(3.0, $portfolio['totals']['avg_interest_rate'], 0.01);

        // ── Per-product rows.
        $products = collect($portfolio['products'])->keyBy('product_name');
        $this->assertCount(2, $products);

        $this->assertSame(1, $products['Salary Loan']['loan_count'], 'One loan, not one row per schedule.');
        $this->assertEqualsWithDelta(60000, $products['Salary Loan']['total_released'], 0.01);
        $this->assertEqualsWithDelta(60000, $products['Salary Loan']['outstanding'], 0.01);
        $this->assertEqualsWithDelta(100.0, $products['Salary Loan']['par_ratio'], 0.01);
        $this->assertEqualsWithDelta(66.67, $products['Salary Loan']['portfolio_share'], 0.01);
        $this->assertEqualsWithDelta(
            6800,
            $products['Salary Loan']['overdue_amount'],
            0.01,
            'One 45-day-late instalment: 60,000 over twelve periods is 5,000 principal + 1,800 interest.',
        );

        $this->assertSame(1, $products['Emergency Loan']['loan_count']);
        $this->assertEqualsWithDelta(30000, $products['Emergency Loan']['total_released'], 0.01);
        $this->assertEqualsWithDelta(30000, $products['Emergency Loan']['outstanding'], 0.01);
        $this->assertEqualsWithDelta(0.0, $products['Emergency Loan']['par_ratio'], 0.01);
        $this->assertEqualsWithDelta(33.33, $products['Emergency Loan']['portfolio_share'], 0.01);

        $this->assertEqualsWithDelta(
            $portfolio['totals']['total_released'],
            array_sum(array_column($portfolio['products'], 'total_released')),
            0.01,
            'The product rows must add up to the grand total.',
        );

        // ── Identical filters, identical figures: narrow to the loan released
        // today and both reports must move together.
        $emergencyLoan->forceFill(['released_at' => Carbon::today()->subDays(10)])->save();
        $today = Carbon::today()->toDateString();

        $narrowed = $this->getJson("/api/reports/portfolio-by-product?date_from={$today}&date_to={$today}")
            ->assertOk()->json('data');
        $narrowedSummary = $this->getJson("/api/reports/loan-balance-summary?date_from={$today}&date_to={$today}")
            ->assertOk()->json('data');

        $this->assertSame(1, $narrowed['totals']['loan_count']);
        $this->assertEqualsWithDelta(60000, $narrowed['totals']['total_released'], 0.01);
        $this->assertEqualsWithDelta(60000, $narrowed['totals']['outstanding'], 0.01);
        $this->assertEqualsWithDelta(100.0, $narrowed['totals']['par_ratio'], 0.01);
        $this->assertSame($narrowedSummary['portfolio']['loan_count'], $narrowed['totals']['loan_count']);
        $this->assertEqualsWithDelta($narrowedSummary['portfolio']['total_released'], $narrowed['totals']['total_released'], 0.01);
        $this->assertEqualsWithDelta($narrowedSummary['outstanding']['balance'], $narrowed['totals']['outstanding'], 0.01);
        $this->assertEqualsWithDelta($narrowedSummary['par_ratio'], $narrowed['totals']['par_ratio'], 0.01);
        $this->assertCount(1, $narrowed['products']);

        // And a branch with no portfolio reports zeros, not a division by zero.
        $empty = $this->getJson('/api/reports/portfolio-by-product?branch_id=99999')->assertOk()->json('data');
        $this->assertSame([], $empty['products']);
        $this->assertSame(0, $empty['totals']['loan_count']);
        $this->assertEqualsWithDelta(0, $empty['totals']['total_released'], 0.01);
        $this->assertEqualsWithDelta(0.0, $empty['totals']['par_ratio'], 0.01);
    }

    // ── Loan Loss Provisioning ───────────────────────────────────────────

    public function test_provisioning_buckets_are_byte_identical_to_the_aging_report(): void
    {
        // One loan late in TWO buckets, one late in a third.
        $spread = $this->releaseLoan();
        $this->moveScheduleDaysAgo($spread, periodNumber: 1, daysAgo: 20);
        $this->moveScheduleDaysAgo($spread, periodNumber: 2, daysAgo: 70);

        $deep = $this->releaseLoan();
        $this->moveScheduleDaysAgo($deep, periodNumber: 1, daysAgo: 100);

        $aging = $this->getJson('/api/reports/aging')->assertOk()->json('data');
        $provisioning = $this->getJson('/api/reports/provisioning')->assertOk()->json('data');

        $this->assertSame($aging['as_of_date'], $provisioning['as_of_date']);

        foreach (['1_30', '31_60', '61_90', 'over_90'] as $bucket) {
            $this->assertSame(
                $aging['buckets'][$bucket]['amount'],
                $provisioning['buckets'][$bucket]['amount'],
                "Provisioning must take {$bucket} straight from agingReport(), not re-derive the boundary.",
            );
            $this->assertSame($aging['buckets'][$bucket]['count'], $provisioning['buckets'][$bucket]['count']);
        }

        // Hand-computed against the fixture: three late instalments of 11,800.
        $this->assertEqualsWithDelta(11800, $provisioning['buckets']['1_30']['amount'], 0.01);
        $this->assertEqualsWithDelta(0, $provisioning['buckets']['31_60']['amount'], 0.01);
        $this->assertEqualsWithDelta(11800, $provisioning['buckets']['61_90']['amount'], 0.01);
        $this->assertEqualsWithDelta(11800, $provisioning['buckets']['over_90']['amount'], 0.01);

        // 5 / 15 / 25 / 50 % of each bucket.
        $this->assertEqualsWithDelta(590, $provisioning['buckets']['1_30']['required_allowance'], 0.01);
        $this->assertEqualsWithDelta(0, $provisioning['buckets']['31_60']['required_allowance'], 0.01);
        $this->assertEqualsWithDelta(2950, $provisioning['buckets']['61_90']['required_allowance'], 0.01);
        $this->assertEqualsWithDelta(5900, $provisioning['buckets']['over_90']['required_allowance'], 0.01);
        $this->assertEqualsWithDelta(5.0, $provisioning['buckets']['1_30']['rate_percent'], 0.01);
        $this->assertEqualsWithDelta(50.0, $provisioning['buckets']['over_90']['rate_percent'], 0.01);
        $this->assertSame(
            ['1_30' => 0.05, '31_60' => 0.15, '61_90' => 0.25, 'over_90' => 0.5],
            $provisioning['rates'],
        );

        // Amounts sum from the buckets.
        $this->assertEqualsWithDelta(35400, $provisioning['totals']['amount'], 0.01);
        $this->assertEqualsWithDelta(
            array_sum(array_column($provisioning['buckets'], 'amount')),
            $provisioning['totals']['amount'],
            0.01,
        );
        $this->assertEqualsWithDelta(9440, $provisioning['totals']['required_allowance'], 0.01);
        $this->assertEqualsWithDelta(
            array_sum(array_column($provisioning['buckets'], 'required_allowance')),
            $provisioning['totals']['required_allowance'],
            0.01,
        );
        $this->assertEqualsWithDelta(26.67, $provisioning['totals']['effective_rate'], 0.01, '9,440 over 35,400.');

        // A different as_of_date re-ages everything, and the two reports must
        // still land on exactly the same buckets.
        $asOf = Carbon::today()->subDays(40)->toDateString();
        $agingBack = $this->getJson("/api/reports/aging?as_of_date={$asOf}")->assertOk()->json('data');
        $provisioningBack = $this->getJson("/api/reports/provisioning?as_of_date={$asOf}")->assertOk()->json('data');

        $this->assertSame($asOf, $provisioningBack['as_of_date']);
        foreach (['1_30', '31_60', '61_90', 'over_90'] as $bucket) {
            $this->assertSame($agingBack['buckets'][$bucket]['amount'], $provisioningBack['buckets'][$bucket]['amount']);
            $this->assertSame($agingBack['buckets'][$bucket]['count'], $provisioningBack['buckets'][$bucket]['count']);
        }

        // 40 days ago the 20-day-late instalment was not yet due at all, the
        // 70-day one was 30 days late, and the 100-day one was 60 days late.
        $this->assertEqualsWithDelta(11800, $provisioningBack['buckets']['1_30']['amount'], 0.01);
        $this->assertEqualsWithDelta(11800, $provisioningBack['buckets']['31_60']['amount'], 0.01);
        $this->assertEqualsWithDelta(0, $provisioningBack['buckets']['61_90']['amount'], 0.01);
        $this->assertEqualsWithDelta(0, $provisioningBack['buckets']['over_90']['amount'], 0.01);
        $this->assertEqualsWithDelta(23600, $provisioningBack['totals']['amount'], 0.01);
        $this->assertEqualsWithDelta(2360, $provisioningBack['totals']['required_allowance'], 0.01, '590 + 1,770.');
        $this->assertEqualsWithDelta(10.0, $provisioningBack['totals']['effective_rate'], 0.01);
    }

    public function test_provisioning_total_count_is_distinct_loans_not_the_sum_of_the_bucket_counts(): void
    {
        // One loan, two buckets. It is ONE delinquent loan, however many of its
        // instalments are late and however far apart they are.
        $spread = $this->releaseLoan();
        $this->moveScheduleDaysAgo($spread, periodNumber: 1, daysAgo: 20);
        $this->moveScheduleDaysAgo($spread, periodNumber: 2, daysAgo: 70);

        $deep = $this->releaseLoan();
        $this->moveScheduleDaysAgo($deep, periodNumber: 1, daysAgo: 100);

        $data = $this->getJson('/api/reports/provisioning')->assertOk()->json('data');

        $this->assertSame(1, $data['buckets']['1_30']['count'], 'Counted once in 1-30...');
        $this->assertSame(0, $data['buckets']['31_60']['count']);
        $this->assertSame(1, $data['buckets']['61_90']['count'], '...and once again in 61-90.');
        $this->assertSame(1, $data['buckets']['over_90']['count']);

        $bucketCountSum = array_sum(array_column($data['buckets'], 'count'));
        $this->assertSame(3, $bucketCountSum);
        $this->assertSame(
            2,
            $data['totals']['count'],
            'Two delinquent loans. totals.count is DISTINCT loans and deliberately does not sum the buckets.',
        );
        $this->assertNotSame(
            $bucketCountSum,
            $data['totals']['count'],
            'If totals.count ever equals the sum of the bucket counts, a loan late in two buckets is being double counted.',
        );

        // The amounts, unlike the counts, do sum — an overdue peso sits in
        // exactly one bucket.
        $this->assertEqualsWithDelta(
            array_sum(array_column($data['buckets'], 'amount')),
            $data['totals']['amount'],
            0.01,
        );
    }

    // ── Share Capital ────────────────────────────────────────────────────

    public function test_share_capital_closing_balance_reconciles_across_every_breakdown(): void
    {
        [$periodStart, $members] = $this->seedShareCapitalPeriod();

        $from = $periodStart->toDateString();
        $to = Carbon::today()->toDateString();

        $data = $this->getJson("/api/reports/share-capital?date_from={$from}&date_to={$to}")
            ->assertOk()->json('data');

        $this->assertSame($from, $data['date_from']);
        $this->assertSame($to, $data['date_to']);
        $this->assertSame('organisation', $data['branch_scope']);

        // Hand-computed: 5,000 + 2,000 carried in; 1,000 + 500 + 3,000 credited
        // and 2,000 debited during the period.
        $this->assertEqualsWithDelta(7000, $data['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(4500, $data['credits'], 0.01);
        $this->assertEqualsWithDelta(2000, $data['debits'], 0.01);
        $this->assertEqualsWithDelta(2500, $data['net_movement'], 0.01);
        $this->assertEqualsWithDelta(9500, $data['closing_balance'], 0.01);
        $this->assertSame(4, $data['entry_count']);

        $this->assertEqualsWithDelta(
            $data['opening_balance'] + $data['credits'] - $data['debits'],
            $data['closing_balance'],
            0.01,
            'closing_balance = opening_balance + credits - debits, exactly.',
        );

        // ── by_member reconciles to the headline closing balance.
        $byMember = collect($data['by_member'])->keyBy('borrower_id');
        $this->assertCount(3, $byMember);

        $this->assertEqualsWithDelta(5000, $byMember[$members['saver']->id]['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(1500, $byMember[$members['saver']->id]['credits'], 0.01);
        $this->assertEqualsWithDelta(0, $byMember[$members['saver']->id]['debits'], 0.01);
        $this->assertEqualsWithDelta(6500, $byMember[$members['saver']->id]['closing_balance'], 0.01);

        $this->assertEqualsWithDelta(2000, $byMember[$members['withdrawer']->id]['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(2000, $byMember[$members['withdrawer']->id]['debits'], 0.01);
        $this->assertEqualsWithDelta(0, $byMember[$members['withdrawer']->id]['closing_balance'], 0.01);

        $this->assertEqualsWithDelta(0, $byMember[$members['joiner']->id]['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(3000, $byMember[$members['joiner']->id]['closing_balance'], 0.01);

        $this->assertEqualsWithDelta(
            $data['closing_balance'],
            array_sum(array_column($data['by_member'], 'closing_balance')),
            0.01,
            'Every peso of share capital belongs to exactly one member.',
        );

        // ── by_month carries a running balance seeded from the opening one, so
        // the last bucket lands on the report's closing balance.
        $this->assertCount(2, $data['by_month']);
        $this->assertSame($periodStart->format('Y-m'), $data['by_month'][0]['period']);
        $this->assertEqualsWithDelta(1000, $data['by_month'][0]['credits'], 0.01);
        $this->assertEqualsWithDelta(2000, $data['by_month'][0]['debits'], 0.01);
        $this->assertEqualsWithDelta(-1000, $data['by_month'][0]['net_movement'], 0.01);
        $this->assertEqualsWithDelta(6000, $data['by_month'][0]['closing_balance'], 0.01, '7,000 opening less 1,000 net.');

        $this->assertSame(Carbon::today()->format('Y-m'), $data['by_month'][1]['period']);
        $this->assertEqualsWithDelta(3500, $data['by_month'][1]['credits'], 0.01);
        $this->assertEqualsWithDelta(0, $data['by_month'][1]['debits'], 0.01);
        $this->assertEqualsWithDelta(9500, $data['by_month'][1]['closing_balance'], 0.01);

        $lastMonth = end($data['by_month']);
        $this->assertEqualsWithDelta(
            $data['closing_balance'],
            $lastMonth['closing_balance'],
            0.01,
            "The last month's running balance IS the report's closing balance.",
        );
        $this->assertEqualsWithDelta(
            $data['credits'] - $data['debits'],
            array_sum(array_column($data['by_month'], 'net_movement')),
            0.01,
        );

        // Subscribed-vs-paid: the pledge is a PER-SCHEDULE commitment and is not
        // netted against paid-in capital.
        $this->assertSame(1, $data['subscription']['pledged_member_count']);
        $this->assertEqualsWithDelta(500, $data['subscription']['total_subscribed_per_period'], 0.01);
        $this->assertEqualsWithDelta($data['closing_balance'], $data['subscription']['total_paid_in'], 0.01);
        $this->assertCount(1, $data['subscription']['by_schedule']);
        $this->assertSame('15/30', $data['subscription']['by_schedule'][0]['schedule']);
        $this->assertSame(1, $data['subscription']['by_schedule'][0]['member_count']);
        $this->assertEqualsWithDelta(500, $data['subscription']['by_schedule'][0]['amount'], 0.01);

        // Omitting date_from runs from inception: opening is zero and closing is
        // the whole book.
        $sinceInception = $this->getJson("/api/reports/share-capital?date_to={$to}")->assertOk()->json('data');
        $this->assertNull($sinceInception['date_from']);
        $this->assertEqualsWithDelta(0, $sinceInception['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(11500, $sinceInception['credits'], 0.01, 'All six credits: 5,000 + 2,000 + 1,000 + 500 + 3,000.');
        $this->assertEqualsWithDelta(2000, $sinceInception['debits'], 0.01);
        $this->assertEqualsWithDelta(9500, $sinceInception['closing_balance'], 0.01);
        $this->assertEqualsWithDelta(
            $sinceInception['closing_balance'],
            array_sum(array_column($sinceInception['by_member'], 'closing_balance')),
            0.01,
        );
    }

    public function test_share_capital_member_count_and_members_with_activity_are_different_questions(): void
    {
        [$periodStart, $members] = $this->seedShareCapitalPeriod();

        $from = $periodStart->toDateString();
        $to = Carbon::today()->toDateString();

        $data = $this->getJson("/api/reports/share-capital?date_from={$from}&date_to={$to}")
            ->assertOk()->json('data');

        $this->assertSame(
            3,
            $data['members_with_activity'],
            'All three members had a ledger entry inside the period.',
        );
        $this->assertSame(
            2,
            $data['member_count'],
            'Only two still hold capital at date_to — the withdrawer moved to a zero balance.',
        );
        $this->assertNotSame(
            $data['members_with_activity'],
            $data['member_count'],
            'These answer different questions and must not be collapsed into one figure.',
        );

        // The member who zeroed out is still listed with his movement, he just
        // is not counted as a shareholder any more.
        $withdrawer = collect($data['by_member'])->firstWhere('borrower_id', $members['withdrawer']->id);
        $this->assertNotNull($withdrawer);
        $this->assertEqualsWithDelta(0, $withdrawer['closing_balance'], 0.01);
        $this->assertEqualsWithDelta(2000, $withdrawer['debits'], 0.01);
        $this->assertEqualsWithDelta(-2000, $withdrawer['net_movement'], 0.01);

        $this->assertSame(
            2,
            count(array_filter($data['by_member'], fn ($m) => $m['closing_balance'] != 0.0)),
            'member_count must equal the number of by_member rows with a non-zero closing balance.',
        );

        // Narrowing the window to today alone re-answers only the activity
        // question: the withdrawer's zero balance still keeps him out of
        // member_count, and his earlier withdrawal now sits in the opening
        // balance rather than in the period's debits.
        $todayOnly = $this->getJson("/api/reports/share-capital?date_from={$to}&date_to={$to}")
            ->assertOk()->json('data');
        $this->assertSame(2, $todayOnly['members_with_activity'], 'Two members moved today.');
        $this->assertSame(2, $todayOnly['member_count']);
        $this->assertEqualsWithDelta(6000, $todayOnly['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(3500, $todayOnly['credits'], 0.01);
        $this->assertEqualsWithDelta(9500, $todayOnly['closing_balance'], 0.01);
    }

    // ── Officer / Branch Performance ─────────────────────────────────────

    public function test_performance_reports_unassigned_loans_so_officer_and_branch_rows_reconcile(): void
    {
        $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
        $officer = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Reyes']);
        $product = $this->product();

        $this->releaseLoan(['product' => $product, 'principal' => 60000, 'officer' => $officer]);
        $this->releaseLoan(['product' => $product, 'principal' => 20000, 'officer' => $officer, 'branch' => $satellite]);
        // No account officer at all — the row that used to be dropped.
        $this->releaseLoan(['product' => $product, 'principal' => 30000, 'branch' => $satellite]);

        $data = $this->getJson('/api/reports/performance')->assertOk()->json('data');

        $this->assertNull($data['date_from']);
        $this->assertNull($data['date_to']);
        $this->assertSame(Carbon::today()->toDateString(), $data['as_of_date']);
        $this->assertSame(30, $data['par_threshold_days']);

        $this->assertCount(2, $data['by_officer']);
        $named = collect($data['by_officer'])->firstWhere('account_officer_id', $officer->id);
        $unassigned = collect($data['by_officer'])->firstWhere('account_officer_name', 'Unassigned');

        $this->assertNotNull($unassigned, 'A loan with no account officer is still portfolio and must be reported.');
        $this->assertNull($unassigned['account_officer_id'], 'The Unassigned row carries a null officer id, not a zero.');
        $this->assertSame(1, $unassigned['loan_count']);
        $this->assertEqualsWithDelta(30000, $unassigned['released_amount'], 0.01);
        $this->assertEqualsWithDelta(30000, $unassigned['outstanding'], 0.01);
        $this->assertSame(1, $unassigned['active_borrowers']);

        $this->assertSame('Grace Reyes', $named['account_officer_name']);
        $this->assertSame(2, $named['loan_count']);
        $this->assertSame(2, $named['released_count']);
        $this->assertEqualsWithDelta(80000, $named['released_amount'], 0.01);
        $this->assertEqualsWithDelta(80000, $named['outstanding'], 0.01);
        $this->assertSame(2, $named['active_borrowers']);

        $this->assertCount(2, $data['by_branch']);
        $branches = collect($data['by_branch'])->keyBy('branch_id');
        $this->assertEqualsWithDelta(60000, $branches[$this->branch->id]['outstanding'], 0.01);
        $this->assertEqualsWithDelta(50000, $branches[$satellite->id]['outstanding'], 0.01, '20,000 + 30,000.');

        $officerOutstanding = array_sum(array_column($data['by_officer'], 'outstanding'));
        $branchOutstanding = array_sum(array_column($data['by_branch'], 'outstanding'));

        $this->assertEqualsWithDelta(110000, $officerOutstanding, 0.01);
        $this->assertEqualsWithDelta(
            $branchOutstanding,
            $officerOutstanding,
            0.01,
            'The two groupings cover the same book. Dropping the Unassigned row would leave the officer side '
            .'30,000 short of the branch side.',
        );
        $this->assertEqualsWithDelta(
            array_sum(array_column($data['by_branch'], 'released_amount')),
            array_sum(array_column($data['by_officer'], 'released_amount')),
            0.01,
        );

        // A branch filter narrows both mirrors identically.
        $filtered = $this->getJson("/api/reports/performance?branch_id={$satellite->id}")->assertOk()->json('data');
        $this->assertCount(1, $filtered['by_branch']);
        $this->assertEqualsWithDelta(50000, $filtered['by_branch'][0]['outstanding'], 0.01);
        $this->assertEqualsWithDelta(
            50000,
            array_sum(array_column($filtered['by_officer'], 'outstanding')),
            0.01,
        );
    }

    public function test_performance_released_amount_is_period_scoped_while_outstanding_is_not(): void
    {
        $officer = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Reyes']);
        $product = $this->product();

        // Released ninety days ago, still on the officer's book today.
        $this->releaseLoan([
            'product' => $product,
            'principal' => 60000,
            'officer' => $officer,
            'released_at' => Carbon::today()->subDays(90),
        ]);

        // Released today: 24,000 over six periods = 4,000 principal + 720
        // interest = 4,720 per instalment.
        $fresh = $this->releaseLoan(['product' => $product, 'principal' => 24000, 'officer' => $officer]);
        // 5,000 clears the first instalment (720 + 4,000) and 280 of the second
        // period's interest, so exactly 4,000 of principal comes off the book.
        $this->postRepayment($fresh, 5000);

        $today = Carbon::today()->toDateString();
        $windows = [
            'today' => "date_from={$today}&date_to={$today}",
            'all_time' => '',
            'before_both' => 'date_from='.Carbon::today()->subDays(100)->toDateString()
                .'&date_to='.Carbon::today()->subDays(95)->toDateString(),
        ];

        $rows = [];
        foreach ($windows as $label => $query) {
            $row = collect(
                $this->getJson('/api/reports/performance'.($query === '' ? '' : "?{$query}"))
                    ->assertOk()->json('data.by_officer')
            )->firstWhere('account_officer_id', $officer->id);

            $this->assertNotNull($row, "Expected the officer row for the {$label} window.");
            $rows[$label] = $row;
        }

        // Clock one — production. Moves with the window.
        $this->assertSame(1, $rows['today']['released_count']);
        $this->assertEqualsWithDelta(24000, $rows['today']['released_amount'], 0.01);
        $this->assertEqualsWithDelta(5000, $rows['today']['collected'], 0.01);

        $this->assertSame(2, $rows['all_time']['released_count'], 'No period given means every release counts.');
        $this->assertEqualsWithDelta(84000, $rows['all_time']['released_amount'], 0.01);
        $this->assertEqualsWithDelta(5000, $rows['all_time']['collected'], 0.01);

        $this->assertSame(0, $rows['before_both']['released_count']);
        $this->assertEqualsWithDelta(0, $rows['before_both']['released_amount'], 0.01);
        $this->assertEqualsWithDelta(0, $rows['before_both']['collected'], 0.01);

        // Clock two — the book. Identical in all three windows.
        foreach ($rows as $label => $row) {
            $this->assertEqualsWithDelta(
                80000,
                $row['outstanding'],
                0.01,
                "outstanding is a point-in-time figure over the officer's WHOLE book and must not move with "
                ."date_from/date_to (window: {$label}).",
            );
            $this->assertSame(2, $row['loan_count'], "loan_count is the whole book too (window: {$label}).");
            $this->assertSame(2, $row['active_borrowers'], "active_borrowers is the whole book too (window: {$label}).");
        }

        $this->assertNotEqualsWithDelta(
            $rows['all_time']['released_amount'],
            $rows['today']['released_amount'],
            0.01,
            'If released_amount does not move with the window, the period filter is being ignored.',
        );
    }

    // ── Share Capital Statement (printable) ──────────────────────────────

    public function test_share_capital_statement_returns_every_entry_past_the_pagination_cap(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $periodStart = Carbon::today()->subDays(130);

        // 1,000 carried in before the period.
        $this->ledgerEntry($member, Carbon::today()->subDays(140)->toDateString(), credit: 1000);
        // 119 credits of 100 and one debit of 400 = 120 entries in the period.
        $this->seedManyLedgerEntries($member, $periodStart, credits: 119, creditAmount: 100, finalDebit: 400);

        $from = $periodStart->toDateString();
        $to = Carbon::today()->toDateString();

        // per_page is deliberately sent and deliberately ignored: a certificate
        // that stops at the hundredth entry is a wrong certificate.
        $data = $this->getJson("/api/reports/share-capital-statement/{$member->id}?date_from={$from}&date_to={$to}&per_page=10")
            ->assertOk()->json('data');

        $this->assertCount(120, $data['entries'], 'All 120 in-period entries must come back, unpaginated.');
        $this->assertSame(120, $data['totals']['entry_count']);
        $this->assertArrayNotHasKey('meta', $data);
        $this->assertArrayNotHasKey('links', $data);

        // The paginated ledger endpoint is exactly what this report exists to
        // work around — it caps at 100 however large per_page is.
        $paginated = $this->getJson("/api/share-capital/ledger?borrower_id={$member->id}&per_page=1000")->assertOk();
        $this->assertCount(100, $paginated->json('data'), 'GET /share-capital/ledger caps per_page at 100.');

        $this->assertSame($member->id, $data['borrower']['id']);
        $this->assertSame($member->borrower_code, $data['borrower']['borrower_code']);
        $this->assertSame($this->branch->name, $data['borrower']['branch_name']);
    }

    public function test_share_capital_statement_running_balance_ends_at_the_closing_balance(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $periodStart = Carbon::today()->subDays(130);

        $this->ledgerEntry($member, Carbon::today()->subDays(140)->toDateString(), credit: 1000);
        $this->seedManyLedgerEntries($member, $periodStart, credits: 119, creditAmount: 100, finalDebit: 400);

        $from = $periodStart->toDateString();
        $to = Carbon::today()->toDateString();

        $data = $this->getJson("/api/reports/share-capital-statement/{$member->id}?date_from={$from}&date_to={$to}")
            ->assertOk()->json('data');

        // Hand-computed: 1,000 brought forward, 119 x 100 credited, 400 drawn.
        $this->assertEqualsWithDelta(1000, $data['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(11900, $data['totals']['credits'], 0.01);
        $this->assertEqualsWithDelta(400, $data['totals']['debits'], 0.01);
        $this->assertEqualsWithDelta(11500, $data['totals']['net_movement'], 0.01);
        $this->assertEqualsWithDelta(12500, $data['closing_balance'], 0.01);
        $this->assertEqualsWithDelta(
            $data['opening_balance'] + $data['totals']['credits'] - $data['totals']['debits'],
            $data['closing_balance'],
            0.01,
            'closing_balance = opening_balance + credits - debits, exactly.',
        );

        $entries = $data['entries'];
        $this->assertEqualsWithDelta(1100, $entries[0]['running_balance'], 0.01, '1,000 brought forward plus the first 100.');
        $this->assertEqualsWithDelta(12900, $entries[118]['running_balance'], 0.01, 'Before the 400 is drawn.');

        $last = end($entries);
        $this->assertEqualsWithDelta(400, $last['debit'], 0.01);
        $this->assertEqualsWithDelta(
            $data['closing_balance'],
            $last['running_balance'],
            0.01,
            "The last entry's running balance IS the closing balance — that is what makes the certificate printable.",
        );

        // And every step in between is the previous balance plus that row's
        // movement, so the column is a real running balance and not a repeat of
        // the closing figure.
        $running = $data['opening_balance'];
        foreach ($entries as $index => $entry) {
            $running = round($running + $entry['credit'] - $entry['debit'], 2);
            $this->assertEqualsWithDelta(
                $running,
                $entry['running_balance'],
                0.01,
                "Entry {$index} broke the running balance.",
            );
        }

        // Entries are ordered oldest first, which is what a statement needs.
        $dates = array_column($entries, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    // ── Access control ───────────────────────────────────────────────────

    public function test_every_financial_report_requires_the_reports_view_permission(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $role = Role::create([
            'name' => 'front_desk_no_reports',
            'guard_name' => 'web',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->syncPermissions(['dashboard:view']);

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertFalse($user->can('reports:view'));

        foreach ($this->financialReportEndpoints($member) as $name => $url) {
            $this->getJson($url)->assertForbidden("{$name} must refuse a caller without reports:view.");
        }
    }

    public function test_every_financial_report_refuses_an_unauthenticated_caller(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $endpoints = $this->financialReportEndpoints($member);

        auth()->forgetGuards();

        foreach ($endpoints as $name => $url) {
            $this->getJson($url)->assertUnauthorized("{$name} must refuse an anonymous caller.");
        }
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_every_financial_report_returns_422_not_500_for_a_malformed_filter(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        foreach ($this->financialReportEndpoints($member) as $name => $url) {
            $this->getJson("{$url}?date_from=not-a-date")
                ->assertStatus(422, "{$name} must reject a malformed date_from before it reaches Carbon::parse().")
                ->assertJsonValidationErrors('date_from');

            $this->getJson("{$url}?date_to=13/45/2026")
                ->assertStatus(422, "{$name} must reject a malformed date_to.")
                ->assertJsonValidationErrors('date_to');

            $this->getJson("{$url}?branch_id=not-a-number")
                ->assertStatus(422, "{$name} must reject a non-integer branch_id.")
                ->assertJsonValidationErrors('branch_id');
        }
    }

    public function test_every_financial_report_rejects_an_inverted_date_range(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        foreach ($this->financialReportEndpoints($member) as $name => $url) {
            $this->getJson("{$url}?date_from=2026-05-01&date_to=2026-04-01")
                ->assertStatus(422, "{$name} must treat date_to < date_from as a client mistake, not an empty report.")
                ->assertJsonValidationErrors('date_to');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * The seven endpoints this suite covers, keyed by a readable name so a
     * looped assertion says which one failed.
     *
     * @return array<string, string>
     */
    private function financialReportEndpoints(Borrower $member): array
    {
        return [
            'cash-flow' => '/api/reports/cash-flow',
            'collection-efficiency' => '/api/reports/collection-efficiency',
            'portfolio-by-product' => '/api/reports/portfolio-by-product',
            'share-capital' => '/api/reports/share-capital',
            'performance' => '/api/reports/performance',
            'provisioning' => '/api/reports/provisioning',
            'share-capital-statement' => "/api/reports/share-capital-statement/{$member->id}",
        ];
    }

    /**
     * The loan product every figure in this file is computed from: straight
     * interest at 3% a period over six monthly periods, with 2% + 1% withheld
     * at release.
     */
    private function product(array $overrides = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
        ], $overrides));
    }

    /**
     * Drive a loan all the way to released, with control over the branch, the
     * account officer, the deductions and the release date — the four things
     * the financial reports slice by.
     *
     * @param  array{product?: LoanProduct, branch?: Branch, officer?: User, principal?: float,
     *              start_date?: string, released_at?: Carbon, deductions?: array<int, array<string, mixed>>}  $options
     */
    private function releaseLoan(array $options = []): Loan
    {
        $product = $options['product'] ?? $this->product();
        $branch = $options['branch'] ?? $this->branch;
        $borrower = Borrower::factory()->create(['branch_id' => $branch->id]);

        $loanService = app(LoanService::class);

        $loan = $loanService->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $options['principal'] ?? 60000,
            'start_date' => $options['start_date'] ?? Carbon::today()->toDateString(),
            'account_officer_id' => isset($options['officer']) ? $options['officer']->id : null,
            'deductions' => $options['deductions'] ?? [],
        ], $this->admin);

        $loanService->submitForReview($loan);
        $loanService->approve($loan, $this->admin, 'Approved for testing');
        $loanService->release($loan, $this->admin);

        // release() always stamps `now()`; the reports slice on this column, so
        // a backdated release has to be written directly.
        if (isset($options['released_at'])) {
            $loan->forceFill(['released_at' => $options['released_at']])->save();
        }

        return $loan->fresh('amortizationSchedules');
    }

    /**
     * Three members whose share capital tells three different stories: one who
     * keeps adding, one who withdraws down to exactly zero, and one who joins
     * inside the period.
     *
     * @return array{0: Carbon, 1: array{saver: Borrower, withdrawer: Borrower, joiner: Borrower}}
     */
    private function seedShareCapitalPeriod(): array
    {
        $periodStart = Carbon::today()->startOfMonth()->subMonth();
        $beforeStart = $periodStart->copy()->subDays(5)->toDateString();
        $insidePeriod = $periodStart->copy()->addDays(9)->toDateString();
        $today = Carbon::today()->toDateString();

        $saver = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $withdrawer = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $joiner = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        // Carried into the period — these make the opening balance non-zero.
        $this->ledgerEntry($saver, $beforeStart, credit: 5000);
        $this->ledgerEntry($withdrawer, $beforeStart, credit: 2000);

        // Movement inside the period.
        $this->ledgerEntry($saver, $insidePeriod, credit: 1000);
        $this->ledgerEntry($withdrawer, $insidePeriod, debit: 2000);
        $this->ledgerEntry($saver, $today, credit: 500);
        $this->ledgerEntry($joiner, $today, credit: 3000);

        // Borrower::booted() already created a zero pledge for each member; a
        // real per-schedule commitment on one of them makes the subscribed-vs-
        // paid block non-trivial.
        $saver->shareCapitalPledge()->update(['amount' => 500, 'schedule' => '15/30']);

        return [$periodStart, ['saver' => $saver, 'withdrawer' => $withdrawer, 'joiner' => $joiner]];
    }

    private function ledgerEntry(Borrower $borrower, string $date, float $credit = 0, float $debit = 0): ShareCapitalLedger
    {
        return ShareCapitalLedger::factory()->create([
            'borrower_id' => $borrower->id,
            'date' => $date,
            'credit' => $credit,
            'debit' => $debit,
            'created_by' => $this->admin->id,
            'description' => $debit > 0 ? 'Share capital withdrawal' : 'Share capital contribution',
        ]);
    }

    /**
     * Bulk-insert enough ledger entries to sail past the paginated ledger
     * endpoint's 100-row cap. Written straight to the table (references
     * included) because the model's reference generator would otherwise run a
     * lookup query per row.
     */
    private function seedManyLedgerEntries(
        Borrower $borrower,
        Carbon $start,
        int $credits,
        float $creditAmount,
        float $finalDebit,
    ): void {
        $timestamp = now();
        $rows = [];

        for ($i = 0; $i < $credits; $i++) {
            $rows[] = [
                'borrower_id' => $borrower->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'description' => 'Share capital contribution '.($i + 1),
                'reference' => sprintf('SC-BULK-%06d', $i + 1),
                'debit' => 0,
                'credit' => $creditAmount,
                'created_by' => $this->admin->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $rows[] = [
            'borrower_id' => $borrower->id,
            'date' => $start->copy()->addDays($credits)->toDateString(),
            'description' => 'Share capital withdrawal',
            'reference' => sprintf('SC-BULK-%06d', $credits + 1),
            'debit' => $finalDebit,
            'credit' => 0,
            'created_by' => $this->admin->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        ShareCapitalLedger::insert($rows);
    }

    /**
     * Re-date one instalment relative to today so a report scenario can be set
     * up exactly, without waiting for real time to pass.
     */
    private function moveScheduleDaysAgo(Loan $loan, int $periodNumber, int $daysAgo): void
    {
        $this->setScheduleDueDate($loan, $periodNumber, Carbon::today()->subDays($daysAgo));
    }

    private function setScheduleDueDate(Loan $loan, int $periodNumber, Carbon $dueDate): void
    {
        $loan->amortizationSchedules()
            ->where('period_number', $periodNumber)
            ->firstOrFail()
            ->update(['due_date' => $dueDate->toDateString()]);
    }

    /**
     * Post a repayment through the real endpoint and return its id.
     */
    private function postRepayment(Loan $loan, float $amount, ?string $paymentDate = null): int
    {
        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => $paymentDate ?? Carbon::today()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }
}
