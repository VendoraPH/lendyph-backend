<?php

namespace Tests\Feature\Phase6\ExecutiveHistory;

use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\PortfolioDailySnapshot;
use App\Models\PortfolioSnapshotLine;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-B: the Dashboard's sparklines read past points from the
 * snapshots. A branch user's past points come from their own branches'
 * snapshot lines, never another branch's, instead of null. The Active Loans
 * sparkline's past points are the snapshots' active loan counts (released and
 * ongoing as they stood that day), not today's loans counted backwards. Today's
 * point is always the live card, and a day with no snapshot has no point.
 */
class SparklineLinesTest extends TestCase
{
    use SetupLendyPH;

    private Branch $other;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Cache::flush();
        // A Thursday.
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));

        $this->other = Branch::factory()->create();
        $this->product = LoanProduct::factory()->create();
    }

    public function test_a_branch_user_s_past_points_come_from_their_branches_lines(): void
    {
        // Yesterday and three days ago have lines; two days ago is a day from
        // before the lines (organisation row only).
        $this->day('2026-11-18', ['overdue' => [3, 7], 'principal' => [20000, 90000], 'active' => [2, 9]]);
        $this->day('2026-11-16', ['overdue' => [1, 4], 'principal' => [10000, 50000], 'active' => [1, 5]]);
        $this->organisationOnly('2026-11-17');
        // Last week's Sunday, the last snapshot of that week.
        $this->day('2026-11-15', ['overdue' => [1, 1], 'principal' => [15000, 40000], 'active' => [3, 4]]);

        $this->actingAs($this->branchUser($this->other));
        $sparklines = $this->getJson('/api/dashboard/stats')->assertOk()->json('data.sparklines');

        $this->assertSame([null, null, 1, 1, null, 3, 0], array_column($sparklines['overdue'], 'v'), 'Their branch\'s lines; a day without lines has no point.');
        $this->assertSame([null, null, null, null, null, 15, 0], array_column($sparklines['portfolio'], 'v'), 'Last week ended Sunday the 15th: ₱15,000.00 in thousands.');
        $this->assertSame([null, null, null, null, null, 3, 0], array_column($sparklines['active_loans'], 'v'));
    }

    public function test_the_whole_organisation_keeps_its_own_rows_and_now_reads_active_loans_from_them(): void
    {
        $this->day('2026-11-18', ['overdue' => [3, 7], 'principal' => [20000, 90000], 'active' => [2, 9]]);
        $this->day('2026-11-15', ['overdue' => [1, 1], 'principal' => [15000, 40000], 'active' => [3, 4]]);
        $loan = $this->createReleasedLoan();

        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');

        $this->assertSame(10, $stats['sparklines']['overdue'][5]['v']);
        $this->assertSame(55, $stats['sparklines']['portfolio'][5]['v']);
        $this->assertSame([null, null, null, null, null, 7, 1], array_column($stats['sparklines']['active_loans'], 'v'), 'Past weeks as recorded; this week is the card.');
        $this->assertSame($stats['active_loans'], last($stats['sparklines']['active_loans'])['v']);
        $this->assertNotNull($loan);
    }

    /**
     * One day with lines in the main branch and the other branch:
     * `[main, other]` for each figure, and the organisation row their sum.
     *
     * @param  array{overdue: array{0: int, 1: int}, principal: array{0: float, 1: float}, active: array{0: int, 1: int}}  $figures
     */
    private function day(string $date, array $figures): void
    {
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => $date,
            'overdue_loans_count' => array_sum($figures['overdue']),
            'overdue_amount' => 0,
            'total_outstanding' => array_sum($figures['principal']),
            'outstanding_principal' => array_sum($figures['principal']),
            'par_1_amount' => 0,
            'par_30_amount' => 0,
            'par_90_amount' => 0,
            'active_loan_count' => array_sum($figures['active']),
            'line_count' => 2,
        ]);

        foreach ([$this->other->id => 0, $this->branch->id => 1] as $branchId => $index) {
            PortfolioSnapshotLine::query()->create([
                'snapshot_date' => $date,
                'branch_id' => $branchId,
                'loan_product_id' => $this->product->id,
                'loan_count' => $figures['active'][$index],
                'active_loan_count' => $figures['active'][$index],
                'overdue_loans_count' => $figures['overdue'][$index],
                'overdue_amount' => 0,
                'total_outstanding' => $figures['principal'][$index],
                'outstanding_principal' => $figures['principal'][$index],
                'par_1_amount' => 0,
                'par_1_loans' => 0,
                'par_30_amount' => 0,
                'par_30_loans' => 0,
                'par_60_amount' => 0,
                'par_60_loans' => 0,
                'par_90_amount' => 0,
                'par_90_loans' => 0,
            ]);
        }
    }

    private function organisationOnly(string $date): void
    {
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => $date,
            'overdue_loans_count' => 99,
            'overdue_amount' => 0,
            'total_outstanding' => 0,
            'outstanding_principal' => 0,
            'par_1_amount' => 0,
            'par_30_amount' => 0,
            'par_90_amount' => 0,
        ]);
    }

    private function branchUser(Branch $branch): User
    {
        $role = Role::create(['name' => 'ebb_branch_dashboard', 'guard_name' => 'web']);
        $role->givePermissionTo(['dashboard:view']);
        $user = User::factory()->inBranches($branch)->create();
        $user->assignRole($role);

        return $user;
    }
}
