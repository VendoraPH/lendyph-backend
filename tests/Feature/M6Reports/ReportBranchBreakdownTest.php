<?php

namespace Tests\Feature\M6Reports;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use Carbon\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Branch breakdowns (decision 5c, 2026-11-02): every active branch appears in
 * each report's `by_branch`, at ₱0.00 when it had no activity; Performance
 * takes whole Manila days of `released_at` like the other reports; and Cash
 * Flow's `by_branch` carries share capital by the member's branch.
 */
class ReportBranchBreakdownTest extends TestCase
{
    use SetupLendyPH;

    private const PERIOD = 'date_from=2026-03-10&date_to=2026-03-20';

    private const REPORTS = ['loan-balance-summary', 'collection-efficiency', 'cash-flow', 'performance'];

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->product = LoanProduct::factory()->create();
    }

    public function test_a_new_branch_with_no_loans_shows_a_zero_row_in_every_breakdown(): void
    {
        $empty = Branch::factory()->create(['is_active' => true]);
        $busy = Branch::factory()->create(['is_active' => true]);
        $this->releasedLoan($busy, '2026-03-15 10:00:00', 5000);

        foreach (self::REPORTS as $report) {
            $rows = collect($this->getJson("/api/reports/{$report}?".self::PERIOD)->assertOk()->json('data.by_branch'));
            $row = $rows->firstWhere('branch_id', $empty->id);

            $this->assertNotNull($row, "{$report}: the branch with no loans is listed.");
            $this->assertSame($empty->name, $row['branch_name']);

            foreach ($this->moneyColumns($report) as $column) {
                $this->assertSame(0, $row[$column] <=> 0, "{$report}: {$column} reads ₱0.00.");
                $this->assertNotNull($row[$column], "{$report}: {$column} is a real ₱0.00, not missing.");
            }

            $this->assertNotNull($rows->firstWhere('branch_id', $busy->id), "{$report}: the busy branch is still listed.");
            $this->assertSame(
                Branch::query()->where('is_active', true)->count(),
                $rows->whereNotNull('branch_id')->count(),
                "{$report}: one row per active branch.",
            );
        }
    }

    public function test_an_inactive_branch_appears_only_with_activity_and_a_branch_filter_lists_only_that_branch(): void
    {
        $inactive = Branch::factory()->create(['is_active' => false]);
        $closedWithLoans = Branch::factory()->create(['is_active' => false]);
        $loan = $this->releasedLoan($closedWithLoans, '2026-03-15 10:00:00', 5000);
        // A period due in the range, so Collection Efficiency has activity there too.
        AmortizationSchedule::factory()->create(['loan_id' => $loan->id, 'due_date' => '2026-03-16']);
        $empty = Branch::factory()->create(['is_active' => true]);

        foreach (self::REPORTS as $report) {
            $ids = collect($this->getJson("/api/reports/{$report}?".self::PERIOD)->assertOk()->json('data.by_branch'))->pluck('branch_id');

            $this->assertNotContains($inactive->id, $ids, "{$report}: an inactive branch with nothing is left out.");
            $this->assertContains($closedWithLoans->id, $ids, "{$report}: an inactive branch with loans stays, so the rows add up.");

            $filtered = $this->getJson("/api/reports/{$report}?".self::PERIOD."&branch_id={$empty->id}")->assertOk()->json('data.by_branch');
            $this->assertSame([$empty->id], array_column($filtered, 'branch_id'), "{$report}: the filter lists only its branch.");
        }
    }

    public function test_every_breakdown_s_totals_equal_the_report_s_headline(): void
    {
        $north = Branch::factory()->create(['is_active' => true]);
        Branch::factory()->create(['is_active' => true]);

        $main = $this->createReleasedLoan();
        $remote = $this->createReleasedLoan(['principal_amount' => 30000]);
        $remote->forceFill(['branch_id' => $north->id])->save();
        $remote->borrower->forceFill(['branch_id' => $north->id])->save();

        foreach ([$main, $remote] as $loan) {
            $loan->amortizationSchedules()->where('period_number', 1)->update(['due_date' => Carbon::today()->subDays(10)->toDateString()]);
        }
        $this->postJson("/api/loans/{$main->id}/repayments", [
            'payment_date' => Carbon::today()->toDateString(),
            'amount_paid' => 4000,
            'method' => 'cash',
        ])->assertCreated();
        $this->ledger(Borrower::factory()->create(['branch_id' => $north->id]), Carbon::today()->toDateString(), credit: 1200);

        $period = 'date_from='.Carbon::today()->subDays(40)->toDateString().'&date_to='.Carbon::today()->toDateString();

        $summary = $this->getJson("/api/reports/loan-balance-summary?{$period}")->assertOk()->json('data');
        $this->assertEquals([
            'loan_count' => $summary['portfolio']['loan_count'],
            'total_released' => $summary['portfolio']['total_released'],
            'total_outstanding' => $summary['total_outstanding'],
            'outstanding_principal' => $summary['outstanding_principal'],
            'outstanding_balance' => $summary['outstanding_balance'],
        ], $summary['by_branch_totals']);
        $this->assertSame(2, $summary['by_branch_totals']['loan_count']);

        $efficiency = $this->getJson("/api/reports/collection-efficiency?{$period}")->assertOk()->json('data');
        $this->assertEquals([
            'total_due' => $efficiency['total_due'],
            'total_collected' => $efficiency['total_collected'],
            'uncollected' => $efficiency['uncollected'],
        ], $efficiency['by_branch_totals']);
        $this->assertGreaterThan(0, $efficiency['by_branch_totals']['total_collected']);

        $performance = $this->getJson("/api/reports/performance?{$period}")->assertOk()->json('data');
        $this->assertEquals($performance['totals'], $performance['by_branch_totals']);
        $this->assertSame(2, $performance['by_branch_totals']['released_count']);

        $cashFlow = $this->getJson("/api/reports/cash-flow?{$period}")->assertOk()->json('data');
        $this->assertEquals($cashFlow['inflows']['total'], $cashFlow['by_branch_totals']['inflow_total']);
        $this->assertEquals($cashFlow['outflows']['total'], $cashFlow['by_branch_totals']['outflow_total']);
        $this->assertEquals($cashFlow['net_movement'], $cashFlow['by_branch_totals']['net_movement']);
        $this->assertEquals(1200, $cashFlow['by_branch_totals']['inflow_share_capital']);
    }

    public function test_performance_takes_whole_manila_days_of_released_at(): void
    {
        $branch = Branch::factory()->create();
        $this->releasedLoan($branch, '2026-03-09 23:59:59', 1000);
        $this->releasedLoan($branch, '2026-03-10 00:00:00', 2000);
        $this->releasedLoan($branch, '2026-03-20 23:59:59', 4000);
        $this->releasedLoan($branch, '2026-03-21 00:00:00', 8000);

        $data = $this->getJson('/api/reports/performance?'.self::PERIOD."&branch_id={$branch->id}")->assertOk()->json('data');
        $row = collect($data['by_branch'])->firstWhere('branch_id', $branch->id);

        $this->assertSame(2, $row['released_count']);
        $this->assertEquals(6000, $row['released_amount']);
        $this->assertSame(2, $data['totals']['released_count']);
        $this->assertEquals(6000, $data['totals']['released_amount']);
    }

    public function test_cash_flow_rows_carry_share_capital_by_the_member_s_branch(): void
    {
        $north = Branch::factory()->create();
        $south = Branch::factory()->create();
        $northMember = Borrower::factory()->create(['branch_id' => $north->id]);
        $southMember = Borrower::factory()->create(['branch_id' => $south->id]);
        $noBranch = Borrower::factory()->create(['branch_id' => null]);

        $this->ledger($northMember, '2026-03-12', credit: 1000);
        $this->ledger($northMember, '2026-03-18', debit: 200);
        $this->ledger($southMember, '2026-03-15', credit: 500);
        $this->ledger($noBranch, '2026-03-15', credit: 50);
        // Outside the period: in no row.
        $this->ledger($southMember, '2026-03-21', credit: 9000);

        // Withheld at release and a payment's build-up: never cash on their
        // own, so in no share capital column.
        $loan = $this->releasedLoan($north, '2026-03-15 10:00:00', 3000);
        $this->ledger($northMember, '2026-03-15', credit: 300, loanId: $loan->id);

        $data = $this->getJson('/api/reports/cash-flow?'.self::PERIOD)->assertOk()->json('data');
        $rows = collect($data['by_branch']);

        $northRow = $rows->firstWhere('branch_id', $north->id);
        $this->assertEquals(1000, $northRow['inflow_share_capital']);
        $this->assertEquals(200, $northRow['outflow_share_capital']);
        $this->assertSame(2, $northRow['share_capital_count']);
        $this->assertEquals(1000, $northRow['inflow_total'], 'No repayments, so cash in is the share capital paid in.');
        $this->assertEquals(3000 + 200, $northRow['outflow_total'], 'The release\'s net proceeds plus the share capital paid out.');
        $this->assertEquals(1000 - 3200, $northRow['net_movement']);

        $southRow = $rows->firstWhere('branch_id', $south->id);
        $this->assertEquals(500, $southRow['inflow_share_capital']);
        $this->assertEquals(0, $southRow['outflow_share_capital']);

        $unassigned = $rows->firstWhere('branch_name', 'Unassigned');
        $this->assertNotNull($unassigned, 'A member with no branch lands in an Unassigned row.');
        $this->assertNull($unassigned['branch_id']);
        $this->assertEquals(50, $unassigned['inflow_share_capital']);

        // The rows add up to the statement.
        $this->assertEquals($data['inflows']['total'], $data['by_branch_totals']['inflow_total']);
        $this->assertEquals($data['outflows']['total'], $data['by_branch_totals']['outflow_total']);
        $this->assertEquals($data['net_movement'], $data['by_branch_totals']['net_movement']);
        $this->assertEquals($data['inflows']['share_capital_credit'], $data['by_branch_totals']['inflow_share_capital']);
        $this->assertEquals($data['outflows']['share_capital_debit'], $data['by_branch_totals']['outflow_share_capital']);
        $this->assertEquals($data['inflows']['repayments']['total'], $data['by_branch_totals']['inflow_repayments']);
    }

    public function test_cash_flow_branch_filter_takes_the_member_s_branch(): void
    {
        $north = Branch::factory()->create();
        $south = Branch::factory()->create();
        $this->ledger(Borrower::factory()->create(['branch_id' => $north->id]), '2026-03-12', credit: 1000);
        $this->ledger(Borrower::factory()->create(['branch_id' => $south->id]), '2026-03-12', credit: 700);

        $data = $this->getJson('/api/reports/cash-flow?'.self::PERIOD."&branch_id={$north->id}")->assertOk()->json('data');

        $this->assertCount(1, $data['by_branch']);
        $this->assertEquals(1000, $data['by_branch'][0]['inflow_share_capital']);
        $this->assertEquals($data['inflows']['total'], $data['by_branch_totals']['inflow_total']);
    }

    /**
     * @return array<int, string>
     */
    private function moneyColumns(string $report): array
    {
        return match ($report) {
            'loan-balance-summary' => ['loan_count', 'total_released', 'outstanding_balance'],
            'collection-efficiency' => ['total_due', 'total_collected', 'collection_rate', 'uncollected'],
            'cash-flow' => ['inflow_total', 'inflow_share_capital', 'outflow_total', 'outflow_share_capital', 'net_movement'],
            'performance' => ['released_count', 'released_amount', 'collected', 'outstanding', 'overdue_amount', 'at_risk_amount', 'par_ratio'],
        };
    }

    private function ledger(Borrower $member, string $date, float $credit = 0, float $debit = 0, ?int $loanId = null): void
    {
        ShareCapitalLedger::factory()->create([
            'borrower_id' => $member->id,
            'loan_id' => $loanId,
            'date' => $date,
            'credit' => $credit,
            'debit' => $debit,
        ]);
    }

    private function releasedLoan(Branch $branch, string $releasedAt, float $principal): Loan
    {
        return Loan::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id])->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $branch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'net_proceeds' => $principal,
            'total_deductions' => 0,
            'deductions' => null,
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }
}
