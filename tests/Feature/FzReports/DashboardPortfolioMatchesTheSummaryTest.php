<?php

namespace Tests\Feature\FzReports;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Models\Role;
use App\Models\User;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Dashboard's Total Portfolio is the standard gross loan portfolio: the
 * unpaid principal of collectible loans, equal to the Portfolio Summary's
 * `outstanding_principal` for the same branches (round fz, audit finding 11). It
 * used to add up the principal of released, ongoing and completed loans, so it
 * counted paid-off loans and left out defaulted ones.
 *
 * The Portfolio Summary and the other reports that send the deprecated
 * principal-plus-premium figure also send `total_outstanding` and
 * `outstanding_principal`, by LoanListTotals' rule, agreeing with the loan pages
 * to the centavo.
 *
 * Each loan is ₱60,000.00 over 6 periods of ₱10,000.00 principal and ₱1,800.00
 * interest.
 */
class DashboardPortfolioMatchesTheSummaryTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_paid_off_loan_adds_nothing_and_a_defaulted_loan_counts(): void
    {
        $paidOff = $this->createReleasedLoan();
        $this->pay($paidOff, 70800);
        $this->assertSame('completed', $paidOff->fresh()->status);

        $defaulted = $this->createReleasedLoan();
        DB::table('loans')->where('id', $defaulted->id)->update(['status' => 'defaulted']);

        $this->assertEquals(60000, $this->card());
        $this->assertEquals(60000, $this->summary()['outstanding_principal']);
    }

    public function test_a_closed_loan_with_a_stray_balance_stays_out_of_both(): void
    {
        $this->createReleasedLoan();
        $closed = $this->createReleasedLoan();
        DB::table('loans')->where('id', $closed->id)->update(['status' => 'completed']);

        $summary = $this->summary();

        $this->assertEquals(60000, $this->card());
        $this->assertEquals(60000, $summary['outstanding_principal']);
        $this->assertEquals(70800, $summary['total_outstanding']);
    }

    public function test_the_card_is_principal_only_and_the_summary_says_which_figure_is_which(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan, 11800);
        $this->createReleasedLoan()->update(['insurance_remaining_balance' => 400]);
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 2)
            ->update(['penalty_amount' => 121.85]);

        $summary = $this->summary();

        $this->assertEquals(110000, $this->card(), 'No interest, penalty or premium in it.');
        $this->assertEquals(110000, $summary['outstanding_principal']);
        // Principal ₱110,000.00, interest ₱19,800.00, penalty ₱121.85, premium ₱400.00.
        $this->assertEquals(130321.85, $summary['total_outstanding']);
        $this->assertEquals(110400, $summary['outstanding_balance'], 'The deprecated alias keeps its meaning.');
    }

    public function test_the_summary_agrees_with_the_loan_pages_to_the_centavo(): void
    {
        $loan = $this->createReleasedLoan(['principal_amount' => 33333.33]);
        $this->pay($loan, 1234.56);
        $this->createReleasedLoan()->update(['insurance_remaining_balance' => 400.10]);
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 2)
            ->update(['penalty_amount' => 121.85]);

        $summary = $this->summary();
        $totals = $this->getJson('/api/loans?per_page=1')->assertOk()->json('meta.totals');
        $rows = $this->getJson('/api/loans?per_page=100')->assertOk()->json('data');

        $this->assertEquals($totals['total_outstanding'], $summary['total_outstanding']);
        $this->assertEquals(round(array_sum(array_column($rows, 'total_outstanding')), 2), $summary['total_outstanding']);
        $this->assertEquals(round(array_sum(array_column($rows, 'outstanding_principal')), 2), $summary['outstanding_principal']);
    }

    public function test_the_summary_s_branch_rows_add_up_to_it(): void
    {
        $this->createReleasedLoan()->update(['insurance_remaining_balance' => 400]);
        $there = $this->createReleasedLoan();
        $other = Branch::factory()->create();
        DB::table('loans')->where('id', $there->id)->update(['branch_id' => $other->id]);
        $this->pay($there, 11800);

        $summary = $this->summary();
        $row = collect($summary['by_branch'])->firstWhere('branch_id', $other->id);

        $this->assertEquals(50000, $row['outstanding_principal']);
        $this->assertEquals(59000, $row['total_outstanding']);
        $this->assertEquals(50000, $row['outstanding_balance']);
        $this->assertEquals($summary['total_outstanding'], $summary['by_branch_totals']['total_outstanding']);
        $this->assertEquals($summary['outstanding_principal'], $summary['by_branch_totals']['outstanding_principal']);
        $this->assertEquals($summary['outstanding_balance'], $summary['by_branch_totals']['outstanding_balance']);
    }

    public function test_releases_product_and_performance_reports_send_both_figures(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['insurance_remaining_balance' => 400]);
        $this->pay($loan, 11800);

        // Premium ₱400.00 is taken first: ₱11,400.00 on period 1 leaves principal
        // ₱50,400.00 and interest ₱9,000.00 still owed, and no premium.
        $totals = $this->getJson('/api/loans?per_page=1')->assertOk()->json('meta.totals');
        $this->assertEquals(50400, $this->getJson('/api/loans?per_page=1')->json('data.0.outstanding_principal'));
        $this->assertEquals(59400, $totals['total_outstanding']);

        $releases = $this->getJson('/api/reports/releases')->assertOk()->json('totals');
        $this->assertEquals(59400, $releases['total_outstanding']);
        $this->assertEquals(50400, $releases['outstanding_principal']);
        $this->assertEquals(50400, $releases['total_outstanding_balance'], 'The deprecated alias keeps its meaning.');

        $product = $this->getJson('/api/reports/portfolio-by-product')->assertOk()->json('data');
        $this->assertEquals(59400, $product['products'][0]['total_outstanding']);
        $this->assertEquals(50400, $product['products'][0]['outstanding_principal']);
        $this->assertEquals(50400, $product['products'][0]['outstanding']);
        $this->assertEquals(59400, $product['totals']['total_outstanding']);
        $this->assertEquals(50400, $product['totals']['outstanding_principal']);

        $performance = $this->getJson('/api/reports/performance')->assertOk()->json('data');
        $branchRow = collect($performance['by_branch'])->firstWhere('branch_id', $loan->branch_id);
        $this->assertEquals(59400, $branchRow['total_outstanding']);
        $this->assertEquals(50400, $branchRow['outstanding_principal']);
        $this->assertEquals(50400, $branchRow['outstanding']);
        $this->assertEquals(59400, $performance['by_officer_totals']['total_outstanding'] ?? $performance['totals']['total_outstanding']);
    }

    public function test_a_branch_user_s_card_equals_the_summary_for_their_branches(): void
    {
        $this->createReleasedLoan();

        $other = Branch::factory()->create();
        $there = $this->createReleasedLoan();
        DB::table('loans')->where('id', $there->id)->update(['branch_id' => $other->id]);
        DB::table('borrowers')->where('id', $there->borrower_id)->update(['branch_id' => $other->id]);
        $this->pay($there, 11800);

        $this->assertEquals(110000, $this->card(), 'Every branch.');

        $role = Role::create(['name' => 'fz_branch_reporter', 'guard_name' => 'web']);
        $role->givePermissionTo(['reports:view', 'dashboard:view']);
        $user = User::factory()->inBranches($other)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertEquals(50000, $this->card());
        $this->assertEquals(50000, $this->summary()['outstanding_principal']);
    }

    public function test_the_sparkline_s_last_point_is_the_card_and_past_points_are_snapshots(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00'));
        $this->createReleasedLoan();
        $paidOff = $this->createReleasedLoan();
        $this->pay($paidOff, 70800);

        // Last week ended on Sunday, Nov 1: its last snapshot is its point.
        PortfolioDailySnapshot::create($this->snapshot('2026-10-31', 90000));
        PortfolioDailySnapshot::create($this->snapshot('2026-11-01', 80000));
        PortfolioDailySnapshot::create($this->snapshot('2026-10-25', 70000));

        $points = array_column($this->stats()['sparklines']['portfolio'], 'v');

        $this->assertCount(7, $points);
        $this->assertEquals(60, $points[6], 'This week is the card, in thousands.');
        $this->assertEquals(80, $points[5]);
        $this->assertEquals(70, $points[4]);
        $this->assertNull($points[3], 'No snapshot, no point.');
    }

    private function card(): float
    {
        return (float) $this->stats()['total_portfolio'];
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        return $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');
    }

    private function pay(Loan $loan, float $amount): void
    {
        app(RepaymentService::class)->processRepayment($loan->fresh(), $amount, now()->toDateString(), $this->admin);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $date, float $principal): array
    {
        return [
            'snapshot_date' => $date,
            'overdue_loans_count' => 0,
            'overdue_amount' => 0,
            'total_outstanding' => $principal,
            'outstanding_principal' => $principal,
            'par_1_amount' => 0,
            'par_30_amount' => 0,
            'par_90_amount' => 0,
        ];
    }
}
