<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\DashboardService;
use App\Services\ReportService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Decision B2 (2026-11-02, least privilege): everything a branch user reads
 * through the reports, the dashboard, the payment history, the accounting
 * reports and the GCash reports is limited to their branches, totals
 * included; `branches:view_all` sees everything. A `branch_id` outside the
 * caller's branches answers 403. The audit trail needs `branches:view_all`.
 */
class BranchScopedReportsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    /**
     * Every report that reads `branch_id`, without a path parameter.
     */
    private const REPORTS = [
        '/api/reports/releases',
        '/api/reports/repayments',
        '/api/reports/due-past-due',
        '/api/reports/loan-balance-summary',
        '/api/reports/daily-collection',
        '/api/reports/income',
        '/api/reports/income/by-loan',
        '/api/reports/aging',
        '/api/reports/borrowers',
        '/api/reports/borrowers/released',
        '/api/reports/disbursements',
        '/api/reports/cash-flow',
        '/api/reports/collection-efficiency',
        '/api/reports/portfolio-by-product',
        '/api/reports/share-capital',
        '/api/reports/performance',
        '/api/reports/provisioning',
    ];

    private Branch $far;

    private Loan $here;

    private Loan $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        $start = now()->subMonths(2)->toDateString();
        $this->here = $this->createReleasedLoan(['start_date' => $start, 'principal_amount' => 60000]);
        $this->there = $this->moveToFarBranch($this->createReleasedLoan(['start_date' => $start, 'principal_amount' => 90000]));

        $this->pay($this->here, 1500);
        $this->pay($this->there, 2500);
    }

    // -----------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------

    public function test_every_report_answers_a_branch_user_with_their_branch_only_totals_included(): void
    {
        $query = 'date_from='.now()->subMonths(3)->toDateString().'&date_to='.now()->addMonths(6)->toDateString();
        $user = $this->branchUser();

        foreach (self::REPORTS as $report) {
            // The admin's view of Main Branch through the filter: what the
            // branch user must now get by default.
            $this->actingAs($this->admin);
            $expected = $this->withoutGeneratedAt($this->getJson("{$report}?{$query}&branch_id={$this->branch->id}&per_page=100")->assertOk());

            $this->actingAs($user);
            $actual = $this->withoutGeneratedAt($this->getJson("{$report}?{$query}&per_page=100")->assertOk());

            $this->assertEquals($expected, $actual, "{$report} differs from the admin's Main Branch view");
        }

        // The scope narrows something real: the whole book is larger.
        $this->actingAs($this->admin);
        $this->assertSame(2, $this->getJson("/api/reports/releases?{$query}")->json('totals.count'));
        $this->actingAs($user);
        $releases = $this->getJson("/api/reports/releases?{$query}")->assertOk();
        $this->assertSame(1, $releases->json('totals.count'));
        $this->assertSame([$this->here->id], array_column($releases->json('data'), 'id'));
        $this->assertEquals(1500, $this->getJson("/api/reports/repayments?{$query}")->json('totals.total_amount_paid'));
        $this->assertEquals([$this->branch->id], array_column($this->getJson("/api/reports/cash-flow?{$query}")->json('data.by_branch'), 'branch_id'));
    }

    public function test_a_report_branch_filter_outside_the_callers_branches_answers_403_and_inside_answers_200(): void
    {
        $this->actingAs($this->branchUser());

        foreach ([...self::REPORTS, '/api/reports/releases/export', '/api/reports/repayments/export', '/api/reports/due-past-due/export'] as $report) {
            $this->getJson("{$report}?branch_id={$this->far->id}")->assertForbidden();
            $this->get("{$report}?branch_id={$this->branch->id}")->assertOk();
        }
    }

    public function test_report_exports_carry_only_the_callers_branch(): void
    {
        $this->actingAs($this->branchUser());

        foreach (['/api/reports/releases/export', '/api/reports/repayments/export', '/api/reports/due-past-due/export'] as $export) {
            $csv = $this->get($export)->assertOk()->streamedContent();

            $this->assertStringContainsString($this->here->loan_account_number, $csv, $export);
            $this->assertStringNotContainsString($this->there->loan_account_number, $csv, $export);
        }
    }

    public function test_view_all_and_a_multi_branch_user_see_every_branch_they_hold(): void
    {
        $both = User::factory()->inBranches($this->branch, $this->far)->create();
        $both->assignRole($this->reporterRole());

        $this->actingAs($both);
        $this->assertSame(2, $this->getJson('/api/reports/releases')->json('totals.count'));
        $this->assertSame(2, $this->getJson('/api/reports/loan-balance-summary')->json('data.portfolio.loan_count'));

        $manager = User::factory()->inBranches($this->branch)->create();
        $manager->assignRole($this->reporterRole());
        $manager->givePermissionTo(BranchAccess::VIEW_ALL);

        $this->actingAs($manager);
        $this->assertSame(2, $this->getJson('/api/reports/releases')->json('totals.count'));
        $this->getJson("/api/reports/releases?branch_id={$this->far->id}")->assertOk()->assertJsonPath('totals.count', 1);
    }

    public function test_a_user_with_no_branch_reports_on_nothing(): void
    {
        $nobody = User::factory()->create(['branch_id' => null]);
        $nobody->assignRole($this->reporterRole());

        $this->actingAs($nobody);

        $this->assertSame([], $this->getJson('/api/reports/releases')->assertOk()->json('data'));
        $this->assertSame(0, $this->getJson('/api/reports/loan-balance-summary')->json('data.portfolio.loan_count'));
        $this->assertSame([], $this->getJson('/api/reports/cash-flow')->json('data.by_branch'));
        $this->assertEquals(0, $this->getJson('/api/reports/repayments')->json('totals.total_amount_paid'));
    }

    public function test_the_subsidiary_ledger_leaves_out_a_members_loan_booked_in_another_branch(): void
    {
        // The far loan's member moves to Main Branch; the loan stays far.
        $this->there->borrower->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchUser());

        $ledger = $this->getJson("/api/reports/subsidiary-ledger/{$this->there->borrower_id}")->assertOk();
        $this->assertSame([], $ledger->json('data.loans'));
        $this->assertSame(0, $ledger->json('data.totals.total_loans'));
    }

    public function test_statement_of_account_rows_carry_the_payment_method_and_particulars(): void
    {
        $receipt = $this->here->repayments()->firstOrFail()->receipt_number;

        $row = $this->getJson("/api/reports/statement-of-account/{$this->here->id}")
            ->assertOk()
            ->json('data.transactions.0');

        $this->assertSame('Cash', $row['payment_method']);
        $this->assertSame("Payment {$receipt}", $row['particulars']);
    }

    // -----------------------------------------------------------------
    // Payment history
    // -----------------------------------------------------------------

    public function test_the_payment_history_lists_the_callers_branch_with_stats_and_totals_scoped(): void
    {
        $this->actingAs($this->branchUser());

        $response = $this->getJson('/api/repayments')->assertOk();

        $this->assertSame([$this->here->id], array_values(array_unique(array_column($response->json('data'), 'loan_id'))));
        $this->assertSame(1, $response->json('meta.stats.posted'));
        $this->assertEquals(1500, $response->json('meta.totals.collected'));

        $this->actingAs($this->admin);
        $this->assertSame(2, $this->getJson('/api/repayments')->json('meta.stats.posted'));
    }

    // -----------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------

    public function test_the_dashboard_counts_only_the_callers_branch(): void
    {
        $this->actingAs($this->branchUser());

        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
        $this->assertSame(1, $stats['active_loans']);
        $this->assertEquals(60000, $stats['total_portfolio']);
        $this->assertEquals(1500, $stats['total_collected']);

        $trend = $this->getJson('/api/dashboard/collections-trend?period=year')->assertOk()->json('data');
        $this->assertEquals(1500, array_sum(array_column($trend, 'value')));

        $dueDate = $this->here->amortizationSchedules->first()->due_date->toDateString();
        $dues = $this->getJson("/api/dashboard/daily-dues?date={$dueDate}")->assertOk()->json('data');
        $this->assertSame([$this->here->id], array_column($dues, 'loan_id'));

        $recent = $this->getJson('/api/dashboard/recent-transactions?limit=50')->assertOk()->json('data');
        $names = array_unique(array_column($recent, 'borrower_name'));
        $this->assertSame([$this->here->borrower->full_name], array_values($names));

        $this->actingAs($this->admin);
        $this->assertSame(2, $this->getJson('/api/dashboard/stats')->json('data.active_loans'));
    }

    public function test_the_overdue_sparkline_hides_organisation_wide_snapshots_from_a_branch_user(): void
    {
        PortfolioDailySnapshot::create([
            'snapshot_date' => Carbon::yesterday()->toDateString(),
            'overdue_loans_count' => 5,
            'overdue_amount' => 0,
            'total_outstanding' => 0,
            'outstanding_principal' => 0,
            'par_1_amount' => 0,
            'par_30_amount' => 0,
            'par_90_amount' => 0,
        ]);

        $this->actingAs($this->admin);
        $whole = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
        $this->assertSame(5, $whole['sparklines']['overdue'][5]['v']);

        $this->actingAs($this->branchUser());
        $scoped = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');
        $overdue = $scoped['sparklines']['overdue'];

        $this->assertCount(7, $overdue);
        $this->assertSame(array_fill(0, 6, null), array_column(array_slice($overdue, 0, 6), 'v'));
        $this->assertSame($scoped['overdue_count'], $overdue[6]['v']);
    }

    public function test_the_active_loans_sparkline_counts_released_and_ongoing_like_the_card(): void
    {
        $this->here->update(['status' => 'ongoing']);
        $this->there->update(['status' => 'released']);

        $stats = $this->getJson('/api/dashboard/stats')->assertOk()->json('data');

        $this->assertSame(2, $stats['active_loans']);
        $this->assertSame($stats['active_loans'], last($stats['sparklines']['active_loans'])['v']);
    }

    // -----------------------------------------------------------------
    // Accounting
    // -----------------------------------------------------------------

    public function test_accounting_reports_are_scoped_by_journal_branch_and_unbranched_rows_need_view_all(): void
    {
        $this->seedChartOfAccounts();
        $lines = fn (int $centavos) => [
            ['account_id' => $this->account('5030'), 'debit' => $centavos, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => $centavos],
        ];
        $this->postJournal($lines(10000), ['branch_id' => $this->branch->id]);
        $this->postJournal($lines(20000), ['branch_id' => $this->far->id]);
        $this->postJournal($lines(40000), ['branch_id' => null]);

        $from = now()->subYear()->toDateString();
        $to = now()->toDateString();
        $endpoints = [
            "/api/accounting/trial-balance?as_of={$to}",
            "/api/accounting/dashboard?as_of={$to}",
            "/api/accounting/loans/aging?as_of={$to}",
            "/api/accounting/general-ledger?account_id={$this->account('5030')}&from={$from}&to={$to}",
            "/api/accounting/books/general-journal?from={$from}&to={$to}",
            "/api/accounting/books/general-ledger?from={$from}&to={$to}",
            "/api/accounting/books/cash-receipts?from={$from}&to={$to}",
            "/api/accounting/books/cash-disbursements?from={$from}&to={$to}",
            "/api/accounting/statements/cash-flow?from={$from}&to={$to}",
            "/api/accounting/statements/equity-changes?from={$from}&to={$to}",
        ];

        $user = $this->branchUser();

        foreach ($endpoints as $endpoint) {
            $this->actingAs($this->admin);
            $expected = $this->withoutPageLinks($this->getJson("{$endpoint}&branch_id={$this->branch->id}")->assertOk());

            $this->actingAs($user);
            $this->assertEquals($expected, $this->withoutPageLinks($this->getJson($endpoint)->assertOk()), "{$endpoint} differs from the admin's Main Branch view");
            $this->getJson("{$endpoint}&branch_id={$this->far->id}")->assertForbidden();
        }

        $expense = fn (TestResponse $tb) => collect($tb->json('data.rows'))->firstWhere('account_code', '5030')['debit'] ?? 0;

        $this->actingAs($user);
        $this->assertSame(10000, $expense($this->getJson("/api/accounting/trial-balance?as_of={$to}")));

        $this->actingAs($this->admin);
        $this->assertSame(70000, $expense($this->getJson("/api/accounting/trial-balance?as_of={$to}")));
    }

    public function test_chart_of_accounts_and_cash_account_balances_follow_the_callers_branches(): void
    {
        $this->seedChartOfAccounts();
        $lines = fn (int $centavos) => [
            ['account_id' => $this->account('5030'), 'debit' => $centavos, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => $centavos],
        ];
        $this->postJournal($lines(10000), ['branch_id' => $this->branch->id]);
        $this->postJournal($lines(20000), ['branch_id' => $this->far->id]);
        $this->postJournal($lines(40000), ['branch_id' => null]);

        $cash = $this->account('1010');
        $expense = $this->account('5030');
        $balanceIn = fn (TestResponse $page, int $id) => collect($page->json('data'))->firstWhere('id', $id)['balance'];

        $this->actingAs($this->branchUser());

        $this->assertSame(10000, $balanceIn($this->getJson('/api/accounting/accounts?per_page=100')->assertOk(), $expense));
        $this->assertSame(10000, $this->getJson("/api/accounting/accounts/{$expense}")->assertOk()->json('data.balance'));
        $branchCash = $balanceIn($this->getJson('/api/accounting/cash-accounts?per_page=100')->assertOk(), $cash);
        $this->assertSame(
            $this->getJson("/api/accounting/accounts/{$cash}")->json('data.balance'),
            $branchCash,
        );

        foreach (['/api/accounting/accounts', '/api/accounting/cash-accounts', "/api/accounting/accounts/{$expense}"] as $endpoint) {
            $this->getJson("{$endpoint}?branch_id={$this->far->id}")->assertForbidden();
            $this->getJson("{$endpoint}?branch_id={$this->branch->id}")->assertOk();
        }

        // view_all: every branch, unbranched journals included; a branch
        // filter narrows to that branch alone.
        $this->actingAs($this->admin);
        $this->assertSame(70000, $balanceIn($this->getJson('/api/accounting/accounts?per_page=100')->assertOk(), $expense));
        $this->assertSame(20000, $this->getJson("/api/accounting/accounts/{$expense}?branch_id={$this->far->id}")->json('data.balance'));
        $this->assertSame(
            $branchCash,
            $balanceIn($this->getJson("/api/accounting/cash-accounts?per_page=100&branch_id={$this->branch->id}")->assertOk(), $cash),
        );
    }

    public function test_report_filters_without_a_branch_scope_fail_closed(): void
    {
        $this->expectException(LogicException::class);

        app(ReportService::class)->listOfReleasesTotals([]);
    }

    public function test_dashboard_figures_need_an_explicit_branch_scope(): void
    {
        foreach (['stats', 'collectionsTrend', 'dailyDues', 'recentTransactions'] as $method) {
            $parameter = (new ReflectionMethod(DashboardService::class, $method))->getParameters()[0];

            $this->assertSame('branchIds', $parameter->getName(), "{$method}() takes the scope first");
            $this->assertFalse($parameter->isOptional(), "{$method}() must not default the scope to the whole organisation");
        }
    }

    // -----------------------------------------------------------------
    // Audit logs
    // -----------------------------------------------------------------

    public function test_audit_logs_need_view_all(): void
    {
        $log = AuditLog::query()->firstOrFail();

        $this->actingAs($this->branchUser());

        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->getJson("/api/audit-logs/{$log->id}")->assertForbidden();
        $this->get('/api/audit-logs/export')->assertForbidden();

        $this->actingAs($this->admin);

        $this->getJson('/api/audit-logs')->assertOk();
        $this->getJson("/api/audit-logs/{$log->id}")->assertOk();
        $this->get('/api/audit-logs/export')->assertOk();
    }

    // -----------------------------------------------------------------
    // GCash reports
    // -----------------------------------------------------------------

    public function test_gcash_reports_show_the_callers_members_and_every_walk_in(): void
    {
        $mine = $this->gcash(['borrower_id' => $this->here->borrower_id, 'charge_amount' => 20]);
        $theirs = $this->gcash(['borrower_id' => $this->there->borrower_id, 'charge_amount' => 30]);
        $walkIn = $this->gcash(['borrower_id' => null, 'gcash_non_member_id' => GCashNonMember::factory(), 'charge_amount' => 15.5]);
        $pendingMine = $this->gcash(['borrower_id' => $this->here->borrower_id, 'status' => 'pending']);
        $pendingTheirs = $this->gcash(['borrower_id' => $this->there->borrower_id, 'status' => 'pending']);

        $range = 'start_date='.now()->subDay()->toDateString().'&end_date='.now()->addDay()->toDateString();

        $this->actingAs($this->branchUser());

        $income = $this->getJson("/api/gcash/reports/income?{$range}")->assertOk();
        $ids = array_column($income->json('data.transactions'), 'id');
        $this->assertEqualsCanonicalizing([$mine->id, $walkIn->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertEquals(35.5, $income->json('data.total_income'));

        $pending = array_column($this->getJson('/api/gcash/reports/pending')->assertOk()->json('data'), 'id');
        $this->assertContains($pendingMine->id, $pending);
        $this->assertNotContains($pendingTheirs->id, $pending);

        $this->actingAs($this->admin);
        $this->assertEquals(65.5, $this->getJson("/api/gcash/reports/income?{$range}")->json('data.total_income'));
    }

    // -----------------------------------------------------------------
    // Branches list
    // -----------------------------------------------------------------

    public function test_the_branches_list_shows_a_branch_user_their_own_branches(): void
    {
        $this->actingAs($this->branchUser());
        $this->assertSame([$this->branch->id], array_column($this->getJson('/api/branches')->assertOk()->json('data'), 'id'));

        $nobody = User::factory()->create(['branch_id' => null]);
        $nobody->assignRole($this->reporterRole());
        $this->actingAs($nobody);
        $this->assertSame([], $this->getJson('/api/branches')->assertOk()->json('data'));

        $this->actingAs($this->admin);
        $all = array_column($this->getJson('/api/branches')->assertOk()->json('data'), 'id');
        $this->assertContains($this->branch->id, $all);
        $this->assertContains($this->far->id, $all);
    }

    public function test_another_branch_answers_404_on_show(): void
    {
        $this->actingAs($this->branchUser());

        $this->getJson("/api/branches/{$this->far->id}")->assertNotFound();
        $this->getJson("/api/branches/{$this->branch->id}")->assertOk()->assertJsonPath('data.id', $this->branch->id);

        $this->actingAs($this->admin);
        $this->getJson("/api/branches/{$this->far->id}")->assertOk()->assertJsonPath('data.id', $this->far->id);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Holds every read this file exercises, but not `branches:view_all`.
     */
    private function reporterRole(): Role
    {
        $role = Role::firstOrCreate(['name' => 'branch_reporter', 'guard_name' => 'web']);
        $role->syncPermissions([
            'reports:view', 'reports:export', 'dashboard:view', 'payments:view',
            'accounting:view', 'audit_logs:view', 'audit_logs:export', 'gcash:view',
            'loans:view', 'borrowers:view', 'chart_of_accounts:view', 'cash_accounts:view',
        ]);

        return $role;
    }

    private function branchUser(): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($this->reporterRole());

        return $user;
    }

    private function moveToFarBranch(Loan $loan): Loan
    {
        $loan->borrower->update(['branch_id' => $this->far->id]);
        $loan->update(['branch_id' => $this->far->id]);

        return $loan->fresh('amortizationSchedules', 'borrower', 'loanProduct');
    }

    private function pay(Loan $loan, float $amount): void
    {
        $this->actingAs($this->admin);
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function gcash(array $attributes): GCashTransaction
    {
        return GCashTransaction::factory()->create(array_merge([
            'transactor_user_id' => $this->admin->id,
            'transaction_date' => now(),
        ], $attributes));
    }

    /**
     * A JSON body without the paginator's links, which echo the query string.
     *
     * @return array<mixed>
     */
    private function withoutPageLinks(TestResponse $response): array
    {
        $json = $response->json();
        unset($json['links'], $json['meta']['links'], $json['meta']['path']);

        return $json;
    }

    /**
     * A report's JSON without its `generated_at` stamps, which differ by the
     * second between two requests.
     *
     * @return array<mixed>
     */
    private function withoutGeneratedAt(TestResponse $response): array
    {
        $strip = function (mixed $value) use (&$strip): mixed {
            if (! is_array($value)) {
                return $value;
            }
            unset($value['generated_at']);

            return array_map($strip, $value);
        };

        return $strip($response->json());
    }
}
