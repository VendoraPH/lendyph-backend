<?php

namespace Tests\Feature\Phase6\ExecutiveBooks;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\RepaymentService;
use App\Services\ReportService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D2: the executive dashboard's monthly trends. Each month's
 * figures are the Cash Flow report's and the Member Report's own for that
 * month (principal released and principal collected, Chart B; cash in and
 * out, Chart I; new members, Chart L), never re-added here.
 */
class ExecutiveTrendsTest extends TestCase
{
    use SetupLendyPH;

    private Loan $october;

    private Loan $november;

    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->collector = User::factory()->inBranches($this->branch)->create();

        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
        $this->october = $this->createReleasedLoan(['principal_amount' => 30000]);

        $this->travelTo(Carbon::parse('2026-11-05 09:00:00'));
        $this->november = $this->createReleasedLoan();
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
        app(RepaymentService::class)->processRepayment($this->october->fresh(), 5900, '2026-11-10', $this->collector);
        ShareCapitalLedger::create([
            'borrower_id' => $this->november->borrower_id,
            'date' => '2026-11-12',
            'description' => 'Share capital paid in',
            'debit' => 0,
            'credit' => 1000,
            'created_by' => $this->admin->id,
        ]);

        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    }

    /** @return array<string, mixed> */
    private function trends(string $query = 'preset=this_month'): array
    {
        return $this->getJson('/api/dashboard/executive/trends?'.$query)->assertOk()->json('data');
    }

    public function test_a_short_period_reads_the_twelve_months_ending_with_it(): void
    {
        $months = $this->trends()['months'];

        $this->assertCount(12, $months);
        $this->assertSame(['month' => '2025-12', 'from' => '2025-12-01', 'to' => '2025-12-31'], Arr::only($months[0], ['month', 'from', 'to']));
        $this->assertSame(['month' => '2026-11', 'from' => '2026-11-01', 'to' => '2026-11-19'], Arr::only($months[11], ['month', 'from', 'to']));
    }

    public function test_each_month_is_the_reports_own_figures(): void
    {
        $reports = app(ReportService::class);

        foreach ($this->trends()['months'] as $month) {
            $filters = ['date_from' => $month['from'], 'date_to' => $month['to'], 'branch_ids' => null];
            $cashFlow = $reports->cashFlow($filters);

            $this->assertEquals($cashFlow['non_cash']['principal_released'], $month['principal_released'], $month['month']);
            $this->assertEquals($cashFlow['inflows']['repayments']['principal'], $month['principal_collected'], $month['month']);
            $this->assertEquals($cashFlow['inflows']['total'], $month['cash_in'], $month['month']);
            $this->assertEquals($cashFlow['outflows']['total'], $month['cash_out'], $month['month']);
            $this->assertEquals($cashFlow['net_movement'], $month['net_cash'], $month['month']);
            $this->assertSame($reports->memberCounts($filters)['new'], $month['new_members'], $month['month']);
        }

        $months = collect($this->trends()['months'])->keyBy('month');
        $this->assertEquals(30000, $months['2026-10']['principal_released']);
        $this->assertEquals(60000, $months['2026-11']['principal_released']);
        $this->assertEquals(5000, $months['2026-11']['principal_collected']);
        $this->assertEquals(0, $months['2026-10']['principal_collected']);
        $this->assertGreaterThanOrEqual(1, $months['2026-11']['new_members']);
    }

    public function test_a_longer_period_reads_each_of_its_months(): void
    {
        $months = $this->trends('preset=custom&from=2025-06-15&to=2026-11-19')['months'];

        $this->assertCount(18, $months);
        $this->assertSame(['month' => '2025-06', 'from' => '2025-06-15', 'to' => '2025-06-30'], Arr::only($months[0], ['month', 'from', 'to']));
    }

    public function test_more_than_three_years_of_months_is_refused_in_words(): void
    {
        $this->getJson('/api/dashboard/executive/trends?preset=custom&from=2023-01-01&to=2026-11-19')
            ->assertUnprocessable()
            ->assertJsonPath('errors.preset.0', 'Trends cover at most 36 months. Pick a shorter period.');
    }

    public function test_the_filters_reach_the_reports_and_say_which_figures_follow_them(): void
    {
        $byCollector = $this->trends("preset=this_month&collector_id={$this->collector->id}");
        $november = collect($byCollector['months'])->firstWhere('month', '2026-11');

        $this->assertSame([], $byCollector['filters_applied']);
        $this->assertSame(['collector_id' => ['principal_collected', 'cash_in', 'net_cash']], $byCollector['filters_applied_to']);
        $this->assertEquals(5000, $november['principal_collected']);
        $this->assertEquals(5900 + 1000, $november['cash_in']);

        $byProduct = $this->trends("preset=this_month&product_id={$this->october->loan_product_id}");
        $november = collect($byProduct['months'])->firstWhere('month', '2026-11');
        $this->assertSame(
            ['product_id' => ['principal_released', 'principal_collected', 'cash_in', 'cash_out', 'net_cash']],
            $byProduct['filters_applied_to'],
        );
        $this->assertEquals(0, $november['principal_released'], 'The November loan is on another product.');
        $this->assertSame(
            collect($this->trends()['months'])->firstWhere('month', '2026-11')['new_members'],
            $november['new_members'],
            'New members follow the branch only.',
        );
    }

    public function test_a_branch_user_sees_only_their_own_branches(): void
    {
        $other = Branch::factory()->create();
        $role = Role::create(['name' => 'ebd2_trends', 'guard_name' => 'web']);
        $role->givePermissionTo(['dashboard:analytics', 'reports:view']);
        $user = User::factory()->inBranches($other)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $november = collect($this->trends()['months'])->firstWhere('month', '2026-11');

        $this->assertEquals(0, $november['principal_released']);
        $this->assertEquals(0, $november['cash_in']);
        $this->assertSame(0, $november['new_members']);
        $this->getJson("/api/dashboard/executive/trends?preset=this_month&branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_it_needs_the_dashboard_and_reports(): void
    {
        foreach ([['reports:view'], ['dashboard:analytics']] as $index => $permissions) {
            $role = Role::create(['name' => "ebd2_trends_{$index}", 'guard_name' => 'web']);
            $role->givePermissionTo($permissions);
            $user = User::factory()->inBranches($this->branch)->create();
            $user->assignRole($role);
            $this->actingAs($user);

            $this->getJson('/api/dashboard/executive/trends?preset=this_month')->assertForbidden();
        }
    }
}
