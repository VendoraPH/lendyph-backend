<?php

namespace Tests\Feature\Phase6\ExecutiveDashboard;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\IncomeStatementBuilder;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D1: GET /api/dashboard/executive/branches, one row per branch
 * from the same services as the cards (loans by the loan's branch), with
 * revenue, expenses and net by journal branch and a "No branch" row for the
 * books' entries with no branch (`accounting:view`, and every branch). The
 * rows add up to the cards.
 */
class ExecutiveBranchesTest extends TestCase
{
    use PostsJournals;
    use SetupLendyPH;

    private const PERIOD = 'date_from=2026-11-01&date_to=2026-11-19';

    private Branch $other;

    private Loan $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
        $this->seedChartOfAccounts();

        $this->other = Branch::factory()->create();

        $here = $this->createReleasedLoan();
        app(RepaymentService::class)->processRepayment($here->fresh(), 11800, '2026-11-19', $this->admin);

        $this->there = $this->createReleasedLoan(['principal_amount' => 30000]);
        DB::table('loans')->where('id', $this->there->id)->update(['branch_id' => $this->other->id]);
        DB::table('borrowers')->where('id', $this->there->borrower_id)->update(['branch_id' => $this->other->id]);
        DB::table('amortization_schedules')->where('loan_id', $this->there->id)->where('period_number', 1)->update(['due_date' => '2026-10-10']);

        // A head office expense with no branch: ₱2,500.00.
        $this->postSimpleJournal('5010', '1010', 250000, ['date' => '2026-11-05', 'branch_id' => null]);
    }

    public function test_each_row_equals_the_reports_for_its_branch(): void
    {
        $rows = collect($this->branches()['rows'])->keyBy('branch_id');

        foreach ([$this->branch->id, $this->other->id] as $branchId) {
            $summary = $this->report('loan-balance-summary', "branch_id={$branchId}");
            $cashFlow = $this->report('cash-flow', self::PERIOD."&branch_id={$branchId}");
            $efficiency = $this->report('collection-efficiency', self::PERIOD."&branch_id={$branchId}");
            $row = $rows[$branchId];

            $this->assertEquals($summary['outstanding_principal'], $row['gross_loan_portfolio'], "{$branchId}");
            $this->assertEquals($summary['total_outstanding'], $row['total_outstanding']);
            $this->assertSame($summary['active_loans'], $row['active_loans']);
            $this->assertEquals($summary['past_due_amount'], $row['past_due_amount']);
            $this->assertEquals($summary['par_30'], $row['par_30']);
            $this->assertEquals($cashFlow['non_cash']['principal_released'], $row['loans_released']);
            $repayments = $cashFlow['inflows']['repayments'];
            $this->assertEquals(round($repayments['total'] - $repayments['insurance'], 2), $row['collections']);
            $this->assertEquals($efficiency['collection_rate'], $row['collection_efficiency']);

            $statement = app(IncomeStatementBuilder::class)->build('2026-11-01', '2026-11-19', [$branchId]);
            $this->assertEquals($statement['total_income'] / 100, $row['revenue']);
            $this->assertEquals($statement['total_expenses'] / 100, $row['expenses']);
            $this->assertEquals($statement['net_income'] / 100, $row['net']);
        }
    }

    public function test_the_rows_add_up_to_the_cards_and_the_books(): void
    {
        $data = $this->branches();
        $cards = $this->getJson('/api/dashboard/executive/overview')->assertOk()->json('data.cards');
        $rows = collect($data['rows']);

        foreach (['gross_loan_portfolio', 'total_outstanding', 'past_due_amount', 'loans_released', 'collections'] as $figure) {
            $this->assertEquals($cards[$figure]['value'], round($rows->sum($figure), 2), $figure);
            $this->assertEquals($cards[$figure]['value'], $data['totals'][$figure], $figure);
        }
        $this->assertSame($cards['active_loans']['value'], $rows->sum('active_loans'));

        $noBranch = $rows->firstWhere('branch_id', null);
        $this->assertSame('No branch', $noBranch['branch_name']);
        $this->assertEquals(2500, $noBranch['expenses']);
        $this->assertNull($noBranch['gross_loan_portfolio'], 'Loans always have a branch.');
        $this->assertEquals($cards['net_income']['value'], round($rows->sum('net'), 2));
        $this->assertEquals($cards['net_income']['value'], $data['totals']['net']);
    }

    public function test_the_books_need_accounting_access(): void
    {
        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'branches:view_all']));

        $data = $this->branches();

        $this->assertSame('no_access', $data['books_state']);
        $this->assertNull(collect($data['rows'])->firstWhere('branch_id', null), 'No "No branch" row.');
        foreach ($data['rows'] as $row) {
            $this->assertNull($row['revenue']);
            $this->assertNull($row['net']);
        }
        $this->assertNull($data['totals']['net']);
        $this->assertNotNull($data['rows'][0]['gross_loan_portfolio']);
    }

    public function test_a_branch_user_sees_only_their_branch_and_never_the_no_branch_row(): void
    {
        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'accounting:view'], $this->other));

        $data = $this->branches();

        $this->assertSame([$this->other->id], array_column($data['rows'], 'branch_id'));
        $this->assertEquals(30000, $data['rows'][0]['gross_loan_portfolio']);

        $this->getJson("/api/dashboard/executive/branches?branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_each_column_says_which_filters_it_followed(): void
    {
        $data = $this->branches("product_id={$this->there->loan_product_id}&collector_id={$this->admin->id}");
        $columns = $data['filters_applied'];

        $this->assertSame(['product_id'], $columns['gross_loan_portfolio']);
        $this->assertSame(['product_id'], $columns['loans_released']);
        $this->assertSame(['product_id', 'collector_id'], $columns['collections']);
        $this->assertSame(['product_id', 'collector_id'], $columns['collected_insurance']);
        $this->assertSame(['product_id'], $columns['collection_efficiency']);
        foreach (['revenue', 'expenses', 'net'] as $books) {
            $this->assertSame([], $columns[$books], "{$books}: the books follow the branch only.");
        }

        $rows = collect($data['rows'])->keyBy('branch_id');
        $this->assertEquals(30000, $rows[$this->other->id]['gross_loan_portfolio']);
        $this->assertEquals(0, $rows[$this->branch->id]['gross_loan_portfolio'], 'The product is the other branch\'s.');
    }

    public function test_a_less_privileged_caller_never_reads_an_admin_s_cached_books(): void
    {
        $this->branches();

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'branches:view_all']));
        $data = $this->branches();

        $this->assertSame('no_access', $data['books_state']);
        $this->assertNull(collect($data['rows'])->firstWhere('branch_id', null));
        $this->assertSame([null], array_values(array_unique(array_column($data['rows'], 'net'))));
        $this->assertNull($data['totals']['revenue']);
    }

    public function test_a_branch_filter_leaves_out_the_no_branch_row(): void
    {
        $data = $this->branches("branch_id={$this->branch->id}");

        $this->assertSame([$this->branch->id], array_column($data['rows'], 'branch_id'));
    }

    public function test_a_branch_the_books_hold_but_the_loans_don_t_gets_a_row(): void
    {
        $closed = Branch::factory()->create(['is_active' => false]);
        $this->postSimpleJournal('1010', '4010', 75000, ['date' => '2026-11-06', 'branch_id' => $closed->id]);

        $data = $this->branches();
        $row = collect($data['rows'])->firstWhere('branch_id', $closed->id);

        $this->assertNotNull($row, 'So the rows add up to the books.');
        $this->assertEquals(750, $row['revenue']);
        $this->assertEquals(0, $row['gross_loan_portfolio']);
        $this->assertEquals($data['totals']['net'], round(collect($data['rows'])->sum('net'), 2));
    }

    public function test_releases_are_cash_flow_s_own_principal_per_branch(): void
    {
        $cashFlow = $this->report('cash-flow', self::PERIOD);
        $rows = collect($this->branches()['rows'])->keyBy('branch_id');

        foreach ($cashFlow['by_branch'] as $flowRow) {
            if ($flowRow['branch_id'] !== null) {
                $this->assertEquals($flowRow['principal_released'], $rows[$flowRow['branch_id']]['loans_released']);
            }
        }
        $this->assertEquals($cashFlow['non_cash']['principal_released'], $cashFlow['by_branch_totals']['principal_released']);
    }

    public function test_the_table_says_when_it_was_worked_out(): void
    {
        $this->assertSame(now()->toDateTimeString(), $this->branches()['generated_at']);
    }

    public function test_it_needs_the_executive_dashboard_and_reports(): void
    {
        $this->actingAs($this->userWith(['dashboard:analytics']));
        $this->getJson('/api/dashboard/executive/branches')->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function branches(string $query = ''): array
    {
        return $this->getJson('/api/dashboard/executive/branches'.($query === '' ? '' : "?{$query}"))->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function report(string $report, string $query): array
    {
        return $this->getJson("/api/reports/{$report}?{$query}")->assertOk()->json('data');
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'ebd1_rows_'.md5(implode(',', $permissions).($branch?->id ?? '')), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($branch ?? $this->branch)->create();
        $user->assignRole($role);

        return $user;
    }
}
