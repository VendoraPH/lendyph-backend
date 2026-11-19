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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-B: GET /api/dashboard/history, the executive dashboard's past
 * portfolio figures from the daily snapshots. A past date reads the last
 * snapshot on or before it, labelled with that snapshot's date; before the
 * first snapshot it reads "No snapshot for this date". Nothing is rebuilt
 * from today's rows (no backfill).
 *
 * Branch users read their own branches' lines. History honours the branch and
 * product filters only.
 */
class DashboardHistoryTest extends TestCase
{
    use SetupLendyPH;

    private Branch $other;

    private LoanProduct $first;

    private LoanProduct $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));

        $this->other = Branch::factory()->create();
        $this->first = LoanProduct::factory()->create();
        $this->second = LoanProduct::factory()->create();

        // Sep 30 and Oct 15 have lines; Aug 20 is an organisation row from
        // before the lines existed.
        $this->day('2026-08-20', null, ['outstanding_principal' => 50000]);
        $this->day('2026-09-30', [
            [$this->branch->id, $this->first->id, 100000, 6000],
            [$this->other->id, $this->first->id, 40000, 0],
        ]);
        $this->day('2026-10-15', [
            [$this->branch->id, $this->first->id, 90000, 9000],
            [$this->branch->id, $this->second->id, 30000, 3000],
            [$this->other->id, $this->first->id, 20000, 0],
        ]);
    }

    public function test_month_end_points_read_the_last_snapshot_on_or_before_each(): void
    {
        $data = $this->history('date_from=2026-07-01&date_to=2026-11-19');

        $this->assertSame(['2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-19'], array_column($data['points'], 'requested_date'));
        $this->assertSame([null, '2026-08-20', '2026-09-30', '2026-10-15', '2026-10-15'], array_column($data['points'], 'snapshot_date'));
        $this->assertSame(['no_snapshot', 'ready', 'ready', 'ready', 'ready'], array_column($data['points'], 'state'));

        $july = $data['points'][0];
        $this->assertSame('No snapshot for this date', $july['label']);
        $this->assertNull($july['figures']);

        $october = $data['points'][3];
        $this->assertSame('As of Oct 15, 2026', $october['label']);
        $this->assertSame('lines', $october['source']);
        $this->assertEquals(140000, $october['figures']['outstanding_principal']);
        $this->assertEquals(['amount' => 12000, 'ratio' => 8.57, 'loans' => 2], $october['figures']['par_30']);

        $august = $data['points'][1];
        $this->assertSame('organisation', $august['source'], 'Before the lines, the whole organisation reads its own row.');
        $this->assertEquals(50000, $august['figures']['outstanding_principal']);
        $this->assertNull($august['figures']['par_60'], 'Not recorded before the lines.');
        $this->assertSame('2026-08-20', $data['first_snapshot_date']);
    }

    public function test_a_single_date(): void
    {
        $data = $this->history('date=2026-10-01');

        $this->assertCount(1, $data['points']);
        $this->assertSame('2026-09-30', $data['points'][0]['snapshot_date']);
        $this->assertEquals(140000, $data['points'][0]['figures']['outstanding_principal']);
    }

    public function test_a_date_before_the_first_snapshot_reads_no_snapshot(): void
    {
        $point = $this->history('date=2026-08-19')['points'][0];

        $this->assertSame('no_snapshot', $point['state']);
        $this->assertSame('No snapshot for this date', $point['label']);
        $this->assertNull($point['snapshot_date']);
    }

    public function test_the_branch_and_product_filters_read_their_lines_only(): void
    {
        $branch = $this->history("date=2026-10-15&branch_id={$this->other->id}");
        $this->assertEquals(20000, $branch['points'][0]['figures']['outstanding_principal']);

        $product = $this->history("date=2026-10-15&product_id={$this->second->id}&officer_id={$this->admin->id}");
        $this->assertEquals(30000, $product['points'][0]['figures']['outstanding_principal']);
        $this->assertSame(['product_id'], $product['filters_applied'], 'History honours the branch and product only.');

        $before = $this->history("date=2026-08-25&product_id={$this->second->id}");
        $this->assertSame('no_snapshot', $before['points'][0]['state'], 'An organisation row has no product lines.');
    }

    public function test_a_branch_user_reads_only_their_branches(): void
    {
        $this->actingAs($this->branchUser($this->other));

        $data = $this->history('date_from=2026-08-01&date_to=2026-10-31');

        $this->assertSame([null, '2026-09-30', '2026-10-15'], array_column($data['points'], 'snapshot_date'), 'The organisation row is never theirs.');
        $this->assertEquals(40000, $data['points'][1]['figures']['outstanding_principal']);
        $this->assertEquals(20000, $data['points'][2]['figures']['outstanding_principal']);

        $this->getJson("/api/dashboard/history?date=2026-10-15&branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_a_snapshot_day_with_nothing_in_the_branch_reads_zero(): void
    {
        $this->day('2026-11-18', [[$this->branch->id, $this->first->id, 80000, 0]]);

        $point = $this->history("date=2026-11-18&branch_id={$this->other->id}")['points'][0];

        $this->assertSame('ready', $point['state']);
        $this->assertSame('2026-11-18', $point['snapshot_date']);
        $this->assertEquals(0, $point['figures']['outstanding_principal']);
        $this->assertEquals(['amount' => 0, 'ratio' => 0, 'loans' => 0], $point['figures']['par_30']);
    }

    public function test_it_needs_the_executive_dashboard_and_reports(): void
    {
        $role = Role::create(['name' => 'ebb_reports_only', 'guard_name' => 'web']);
        $role->givePermissionTo(['reports:view', 'dashboard:view']);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        $this->actingAs($user);
        $this->getJson('/api/dashboard/history?date=2026-10-15')->assertForbidden();

        $role->givePermissionTo('dashboard:analytics');
        $role->revokePermissionTo('reports:view');
        $this->actingAs($user->fresh());
        $this->getJson('/api/dashboard/history?date=2026-10-15')->assertForbidden();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'no date' => ['', 'date'],
            'a date after today' => ['date=2026-11-20', 'date'],
            'a date and a range' => ['date=2026-10-01&date_from=2026-09-01&date_to=2026-10-31', 'date'],
            'a range that ends before it starts' => ['date_from=2026-10-01&date_to=2026-09-01', 'date_to'],
            'more than ten years' => ['date_from=2016-10-01&date_to=2026-11-19', 'date_from'],
            'a bad product' => ['date=2026-10-01&product_id=0', 'product_id'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_request_it_can_t_read_is_refused(string $query, string $field): void
    {
        $this->getJson("/api/dashboard/history?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_today_s_snapshot_equals_the_portfolio_summary(): void
    {
        PortfolioDailySnapshot::query()->delete();
        PortfolioSnapshotLine::query()->delete();
        $this->createReleasedLoan();
        $this->travelTo(Carbon::parse('2026-11-19 23:55:00'));
        $this->artisan('portfolio:snapshot')->assertSuccessful();

        $figures = $this->history('date=2026-11-19')['points'][0]['figures'];
        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');

        $this->assertEquals($summary['outstanding_principal'], $figures['outstanding_principal']);
        $this->assertEquals($summary['total_outstanding'], $figures['total_outstanding']);
        $this->assertEquals($summary['past_due_amount'], $figures['past_due_amount']);
        $this->assertSame($summary['active_loans'], $figures['active_loans']);
        $this->assertEquals($summary['par_30'], $figures['par_30']);
    }

    /** @return array<string, mixed> */
    private function history(string $query): array
    {
        return $this->getJson("/api/dashboard/history?{$query}")->assertOk()->json('data');
    }

    /**
     * One day's organisation row and, when given, its lines: [branch, product,
     * outstanding principal, PAR 30 amount] each, one PAR 30 loan per line
     * with an amount.
     *
     * @param  list<array{0: int, 1: int, 2: float, 3: float}>|null  $lines
     * @param  array<string, mixed>  $organisation
     */
    private function day(string $date, ?array $lines, array $organisation = []): void
    {
        $sum = fn (int $index) => array_sum(array_column($lines ?? [], $index));

        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => $date,
            'overdue_loans_count' => 0,
            'overdue_amount' => 0,
            'total_outstanding' => $organisation['outstanding_principal'] ?? $sum(2),
            'outstanding_principal' => $organisation['outstanding_principal'] ?? $sum(2),
            'par_1_amount' => $sum(3),
            'par_30_amount' => $sum(3),
            'par_90_amount' => 0,
            'line_count' => $lines === null ? null : count($lines),
        ]);

        foreach ($lines ?? [] as [$branchId, $productId, $principal, $par30]) {
            PortfolioSnapshotLine::query()->create([
                'snapshot_date' => $date,
                'branch_id' => $branchId,
                'loan_product_id' => $productId,
                'loan_count' => 1,
                'active_loan_count' => 1,
                'overdue_loans_count' => 0,
                'overdue_amount' => 0,
                'total_outstanding' => $principal,
                'outstanding_principal' => $principal,
                'par_1_amount' => $par30,
                'par_1_loans' => $par30 > 0 ? 1 : 0,
                'par_30_amount' => $par30,
                'par_30_loans' => $par30 > 0 ? 1 : 0,
                'par_60_amount' => 0,
                'par_60_loans' => 0,
                'par_90_amount' => 0,
                'par_90_loans' => 0,
            ]);
        }
    }

    private function branchUser(Branch $branch): User
    {
        $role = Role::create(['name' => 'ebb_branch_analyst', 'guard_name' => 'web']);
        $role->givePermissionTo(['reports:view', 'dashboard:view', 'dashboard:analytics']);
        $user = User::factory()->inBranches($branch)->create();
        $user->assignRole($role);

        return $user;
    }
}
