<?php

namespace Tests\Feature\Phase6\ExecutiveFigures;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Services\PortfolioRisk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * One PAR basis (Arvin, 2026-11-19, "proceed dont wait on me"): PAR and the
 * Portfolio Summary's outstanding breakdown cover the collectible loans
 * (released, ongoing, defaulted), as the daily snapshots, the aging and CDA
 * do. A completed or restructured loan that still has a period marked unpaid
 * is in none of them, so the executive dashboard's components add up to its
 * total and every screen reads the same PAR.
 *
 * Each loan is ₱60,000.00 over 6 periods of ₱10,000.00 principal and
 * ₱1,800.00 interest.
 */
class ParBasisTest extends TestCase
{
    use SetupLendyPH;

    /** @var array<string, Loan> */
    private array $loans = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));

        // Collectible: 40 days late with ₱500.00 penalty and ₱400.00 premium
        // owed, a current loan, and a defaulted loan 100 days late.
        $this->loans['late'] = $this->loan(daysLate: 40);
        DB::table('amortization_schedules')->where('loan_id', $this->loans['late']->id)->where('period_number', 1)->update(['penalty_amount' => 500]);
        DB::table('loans')->where('id', $this->loans['late']->id)->update(['insurance_remaining_balance' => 400]);
        $this->loans['current'] = $this->loan(daysLate: 0);
        $this->loans['defaulted'] = $this->loan(daysLate: 100, status: 'defaulted');

        // Not collectible, each with a period still marked unpaid 45 days ago.
        $this->loans['completed'] = $this->loan(daysLate: 45, status: 'completed');
        DB::table('loans')->where('id', $this->loans['completed']->id)->update(['insurance_remaining_balance' => 300]);
        $this->loans['restructured'] = $this->loan(daysLate: 45, status: 'restructured');
    }

    public function test_par_covers_the_collectible_loans_over_their_own_unpaid_principal(): void
    {
        $summary = $this->report('loan-balance-summary');

        $this->assertEquals(180000, $summary['par_portfolio_principal']);
        $this->assertEquals(['amount' => 120000, 'ratio' => 66.67, 'loans' => 2], $summary['par_1']);
        $this->assertEquals(['amount' => 120000, 'ratio' => 66.67, 'loans' => 2], $summary['par_30']);
        $this->assertEquals(['amount' => 60000, 'ratio' => 33.33, 'loans' => 1], $summary['par_90']);
        $this->assertEquals(120000, $summary['at_risk_amount']);
        $this->assertEquals(66.67, $summary['par_ratio']);
    }

    public function test_the_outstanding_breakdown_adds_up_to_total_outstanding(): void
    {
        $summary = $this->report('loan-balance-summary');
        $outstanding = $summary['outstanding'];

        $this->assertEquals(180000, $outstanding['principal']);
        $this->assertEquals(32400, $outstanding['interest'], '3 loans × 6 × ₱1,800.00.');
        $this->assertEquals(500, $outstanding['penalty']);
        $this->assertEquals(400, $outstanding['insurance'], 'The completed loan\'s premium is not in it.');
        $this->assertEquals(212900, $outstanding['total']);
        $this->assertEquals(180400, $outstanding['balance']);
        $this->assertEquals(180400, $summary['outstanding_balance']);

        $this->assertEquals($summary['outstanding_principal'], $outstanding['principal']);
        $this->assertEquals($summary['outstanding_principal'], $summary['par_portfolio_principal']);
        $this->assertEquals(
            $summary['total_outstanding'],
            round($outstanding['principal'] + $outstanding['interest'] + $outstanding['penalty'] + $outstanding['insurance'], 2),
        );
    }

    public function test_the_branch_rows_deprecated_alias_adds_up_to_the_summary_s(): void
    {
        $summary = $this->report('loan-balance-summary');
        $main = collect($summary['by_branch'])->firstWhere('branch_id', $this->branch->id);

        $this->assertEquals(180400, $main['outstanding_balance']);
        $this->assertEquals($summary['outstanding_balance'], $summary['by_branch_totals']['outstanding_balance']);
    }

    public function test_the_summary_s_par_equals_the_snapshot_s_and_the_executive_dashboard_s(): void
    {
        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $summary = $this->report('loan-balance-summary');
        $snapshot = PortfolioDailySnapshot::query()->sole();
        $overview = $this->getJson('/api/dashboard/executive/overview')->assertOk()->json('data.cards.par_30');
        $branches = collect($this->getJson('/api/dashboard/executive/branches')->assertOk()->json('data.rows'))->keyBy('branch_id');

        foreach (['par_1', 'par_30', 'par_60', 'par_90'] as $par) {
            $this->assertEquals($summary[$par]['amount'], (float) $snapshot->{"{$par}_amount"}, $par);
            $this->assertSame($summary[$par]['loans'], $snapshot->{"{$par}_loans"}, $par);
            $this->assertEquals($summary[$par], $overview['details'][$par], $par);
        }
        $this->assertEquals($summary['par_portfolio_principal'], (float) $snapshot->outstanding_principal);
        $this->assertEquals($summary['par_portfolio_principal'], $overview['details']['portfolio_principal']);
        $this->assertEquals($summary['par_30']['ratio'], $overview['value']);
        $this->assertEquals($summary['par_30'], $branches[$this->branch->id]['par_30'], 'The branch row\'s PAR.');
    }

    public function test_the_par_loans_list_names_only_collectible_loans(): void
    {
        $page = $this->getJson('/api/dashboard/executive/par-loans?par=1')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->loans['late']->id, $this->loans['defaulted']->id],
            array_column($page->json('data'), 'loan_id'),
        );
        $this->assertSame(2, $page->json('meta.totals.loans'));
        $this->assertEquals(120000, $page->json('meta.totals.outstanding_principal'));
    }

    public function test_portfolio_by_product_and_performance_read_par_on_the_same_basis(): void
    {
        $summary = $this->report('loan-balance-summary');
        $products = $this->report('portfolio-by-product');
        $performance = $this->report('performance');
        $mainRow = collect($performance['by_branch'])->firstWhere('branch_id', $this->branch->id);

        foreach ([$products['totals'], $mainRow] as $figures) {
            $this->assertEquals(120000, $figures['at_risk_amount']);
            $this->assertEquals(66.67, $figures['par_ratio']);
            $this->assertEquals(300700, $figures['outstanding'], 'The deprecated alias keeps every ever-released loan (round fz).');
            $this->assertEquals($summary['at_risk_amount'], $figures['at_risk_amount']);
            $this->assertEquals($summary['par_ratio'], $figures['par_ratio']);
        }
    }

    public function test_overdue_covers_the_collectible_loans_from_the_bare_due_date(): void
    {
        $summary = $this->report('loan-balance-summary');
        $overdue = $summary['overdue'];

        // The late and defaulted loans' first periods: ₱10,000.00 principal and
        // ₱1,800.00 interest each, plus the late one's ₱500.00 penalty.
        $this->assertEquals(20000, $overdue['principal']);
        $this->assertEquals(3600, $overdue['interest']);
        $this->assertEquals(500, $overdue['penalty']);
        $this->assertSame(2, $overdue['loan_count']);
        $this->assertEquals(24100, $overdue['total']);
        $this->assertLessThanOrEqual($summary['outstanding']['principal'], $overdue['principal']);
    }

    public function test_portfolio_by_product_and_performance_read_overdue_on_the_same_basis(): void
    {
        $products = $this->report('portfolio-by-product');
        $performance = $this->report('performance');
        $mainRow = collect($performance['by_branch'])->firstWhere('branch_id', $this->branch->id);

        $this->assertEquals(24100, $products['totals']['overdue_amount']);
        $this->assertEquals(0, collect($products['products'])->firstWhere('product_id', $this->loans['completed']->loan_product_id)['overdue_amount']);
        $this->assertEquals(24100, $mainRow['overdue_amount']);
        $this->assertEquals(24100, $performance['by_branch_totals']['overdue_amount']);
    }

    public function test_the_branch_product_and_release_date_filters_keep_one_basis(): void
    {
        // The late loan's product holds the completed loan too; the defaulted
        // loan moves to another branch in the same product.
        $product = $this->loans['late']->loan_product_id;
        $other = Branch::factory()->create();
        DB::table('loans')->where('id', $this->loans['completed']->id)->update(['loan_product_id' => $product]);
        DB::table('loans')->where('id', $this->loans['defaulted']->id)->update(['loan_product_id' => $product, 'branch_id' => $other->id]);

        $summary = $this->getJson("/api/reports/loan-balance-summary?branch_id={$this->branch->id}&product_id={$product}&date_from=2026-11-19&date_to=2026-11-19")
            ->assertOk()->json('data');

        $this->assertEquals(['amount' => 60000, 'ratio' => 100.0, 'loans' => 1], $summary['par_1']);
        $this->assertEquals(60000, $summary['par_portfolio_principal']);
        $this->assertEquals($summary['outstanding_principal'], $summary['par_portfolio_principal']);
        $this->assertEquals($summary['outstanding_principal'], $summary['outstanding']['principal']);
        $this->assertEquals(400, $summary['outstanding']['insurance']);
        $this->assertEquals(
            $summary['total_outstanding'],
            round($summary['outstanding']['principal'] + $summary['outstanding']['interest'] + $summary['outstanding']['penalty'] + $summary['outstanding']['insurance'], 2),
        );
        $this->assertEquals(['principal' => 10000, 'interest' => 1800, 'penalty' => 500, 'loan_count' => 1, 'total' => 12300], $summary['overdue']);

        $none = $this->getJson("/api/reports/loan-balance-summary?branch_id={$this->branch->id}&product_id={$product}&date_from=2026-11-20&date_to=2026-11-20")
            ->assertOk()->json('data');
        $this->assertEquals(['amount' => 0, 'ratio' => 0, 'loans' => 0], $none['par_1']);
        $this->assertEquals(0, $none['outstanding']['total']);
        $this->assertSame(0, $none['overdue']['loan_count']);
    }

    public function test_portfolio_risk_measures_only_collectible_loans_whatever_it_is_given(): void
    {
        $risk = app(PortfolioRisk::class);
        $everyLoan = Loan::query();

        $par = $risk->par($everyLoan);
        $this->assertEquals(180000, $par['portfolio_principal']);
        $this->assertSame(2, $par['par_1']['loans']);

        $this->assertEqualsCanonicalizing(
            [$this->loans['late']->id, $this->loans['defaulted']->id],
            $risk->parLoans($everyLoan, 'par_1')->get()->modelKeys(),
        );
        $this->assertSame(2, $risk->parBy($everyLoan, ['loans.branch_id'])[(string) $this->branch->id]['par_1']['loans']);
    }

    private function loan(int $daysLate, string $status = 'released'): Loan
    {
        $loan = $this->createReleasedLoan();

        DB::table('amortization_schedules')
            ->where('loan_id', $loan->id)
            ->where('period_number', 1)
            ->update(['due_date' => Carbon::today()->subDays($daysLate)->toDateString()]);
        DB::table('loans')->where('id', $loan->id)->update(['status' => $status]);

        return $loan;
    }

    /** @return array<string, mixed> */
    private function report(string $report): array
    {
        return $this->getJson("/api/reports/{$report}")->assertOk()->json('data');
    }
}
