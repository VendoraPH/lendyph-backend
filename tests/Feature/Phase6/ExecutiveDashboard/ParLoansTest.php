<?php

namespace Tests\Feature\Phase6\ExecutiveDashboard;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D1: GET /api/dashboard/executive/par-loans, the loans behind the
 * PAR card. They are the Portfolio Summary's own PAR loans (PortfolioRisk),
 * so their count and principal equal its `par_n`. Under the round fz
 * personal-data rule each row names the member by id, name and code only.
 */
class ParLoansTest extends TestCase
{
    use SetupLendyPH;

    /** @var array<int, Loan> days late => loan */
    private array $loans = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));

        foreach ([0, 10, 31, 61, 95] as $days) {
            $loan = $this->createReleasedLoan();
            DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)
                ->update(['due_date' => Carbon::today()->subDays($days)->toDateString()]);
            $this->loans[$days] = $loan;
        }
    }

    public function test_the_loans_behind_each_par_figure(): void
    {
        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');

        foreach (['1' => [95, 61, 31, 10], '30' => [95, 61, 31], '60' => [95, 61], '90' => [95]] as $par => $days) {
            $page = $this->getJson("/api/dashboard/executive/par-loans?par={$par}")->assertOk();

            $this->assertSame(
                array_map(fn (int $d) => $this->loans[$d]->id, $days),
                array_column($page->json('data'), 'loan_id'),
                "PAR {$par}, most days late first.",
            );
            $this->assertSame($summary["par_{$par}"]['loans'], $page->json('meta.totals.loans'));
            $this->assertEquals($summary["par_{$par}"]['amount'], $page->json('meta.totals.outstanding_principal'));
        }
    }

    public function test_a_row_s_figures(): void
    {
        $row = collect($this->getJson('/api/dashboard/executive/par-loans?par=30')->assertOk()->json('data'))
            ->firstWhere('loan_id', $this->loans[31]->id);

        $this->assertSame(31, $row['days_past_due']);
        $this->assertSame('2026-10-19', $row['oldest_unpaid_due_date']);
        $this->assertEquals(60000, $row['outstanding_principal']);
        $this->assertEquals(11800, $row['past_due_amount']);
        $this->assertSame(['id' => $this->branch->id, 'name' => $this->branch->name], $row['branch']);
        $this->assertSame($this->loans[31]->loan_product_id, $row['product']['id']);
        $this->assertSame($this->loans[31]->loan_account_number, $row['account_number']);
    }

    public function test_a_member_is_named_by_id_name_and_code_only(): void
    {
        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'loans:view']));

        $response = $this->getJson('/api/dashboard/executive/par-loans?par=30')->assertOk();
        $borrower = $this->loans[31]->borrower;
        $row = collect($response->json('data'))->firstWhere('loan_id', $this->loans[31]->id);

        $this->assertSame(['id' => $borrower->id, 'name' => $borrower->full_name, 'code' => $borrower->borrower_code], $row['member']);
        $body = $response->getContent();
        foreach (array_filter([$borrower->contact_number, $borrower->email, $borrower->street_address]) as $personal) {
            $this->assertStringNotContainsString((string) $personal, $body);
        }
    }

    public function test_a_branch_user_sees_only_their_branch_s_loans(): void
    {
        $other = Branch::factory()->create();
        DB::table('loans')->where('id', $this->loans[61]->id)->update(['branch_id' => $other->id]);

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'loans:view'], $other));

        $this->assertSame([$this->loans[61]->id], array_column($this->getJson('/api/dashboard/executive/par-loans?par=30')->assertOk()->json('data'), 'loan_id'));
        $this->getJson("/api/dashboard/executive/par-loans?branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_it_needs_the_dashboard_the_reports_and_the_loans(): void
    {
        foreach ([['reports:view', 'loans:view'], ['dashboard:analytics', 'loans:view'], ['dashboard:analytics', 'reports:view']] as $permissions) {
            $this->actingAs($this->userWith($permissions));
            $this->getJson('/api/dashboard/executive/par-loans')->assertForbidden();
        }
    }

    public function test_only_the_four_par_measures_are_read(): void
    {
        $this->getJson('/api/dashboard/executive/par-loans?par=45')->assertUnprocessable()->assertJsonValidationErrors('par');
        $this->assertCount(3, $this->getJson('/api/dashboard/executive/par-loans')->assertOk()->json('data'), 'PAR 30 by default.');
    }

    public function test_it_pages_on_the_server(): void
    {
        $second = $this->getJson('/api/dashboard/executive/par-loans?par=1&per_page=2&page=2')->assertOk();

        $this->assertSame([$this->loans[31]->id, $this->loans[10]->id], array_column($second->json('data'), 'loan_id'));
        $this->assertSame(4, $second->json('meta.total'));
        $this->assertSame(2, $second->json('meta.last_page'));
        $this->assertSame(4, $second->json('meta.totals.loans'), 'Totals cover every page.');

        $this->getJson('/api/dashboard/executive/par-loans?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_totals_follow_the_branch_and_the_filters(): void
    {
        $other = Branch::factory()->create();
        DB::table('loans')->where('id', $this->loans[61]->id)->update(['branch_id' => $other->id]);

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'loans:view'], $other));
        $summary = $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');
        $page = $this->getJson('/api/dashboard/executive/par-loans?par=30')->assertOk();
        $this->assertSame($summary['par_30']['loans'], $page->json('meta.totals.loans'));
        $this->assertEquals($summary['par_30']['amount'], $page->json('meta.totals.outstanding_principal'));

        $this->actingAs($this->admin);
        $product = $this->loans[95]->loan_product_id;
        $summary = $this->getJson("/api/reports/loan-balance-summary?product_id={$product}")->assertOk()->json('data');
        $page = $this->getJson("/api/dashboard/executive/par-loans?par=30&product_id={$product}")->assertOk();
        $this->assertSame(1, $page->json('meta.totals.loans'));
        $this->assertEquals($summary['par_30']['amount'], $page->json('meta.totals.outstanding_principal'));
    }

    public function test_the_product_and_officer_filters_narrow_it(): void
    {
        $page = $this->getJson("/api/dashboard/executive/par-loans?par=30&product_id={$this->loans[61]->loan_product_id}")->assertOk();

        $this->assertSame([$this->loans[61]->id], array_column($page->json('data'), 'loan_id'));
        $this->assertSame(['product_id'], $page->json('meta.filters_applied'));
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'ebd1_par_'.md5(implode(',', $permissions).($branch?->id ?? '')), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($branch ?? $this->branch)->create();
        $user->assignRole($role);

        return $user;
    }
}
