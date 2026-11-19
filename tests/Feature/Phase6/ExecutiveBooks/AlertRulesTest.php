<?php

namespace Tests\Feature\Phase6\ExecutiveBooks;

use App\Models\AccountingOpeningBalance;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\DashboardSetting;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PortfolioDailySnapshot;
use App\Models\PortfolioSnapshotLine;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\LoanService;
use App\Services\RepaymentService;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D2: the executive dashboard's alerts (`/attention`), each rule
 * evaluated against EB-C's thresholds (DashboardSetting) on the figures of
 * the report it comes from. PAR 30's rise is "at least"; every other rule is
 * strict. A rule whose source the caller can't read is `no_access`; one with
 * nothing to measure is `no_data` or `no_snapshot`; book controls wait for
 * the books (`requires_configuration`). Wording stays neutral.
 */
class AlertRulesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const PERIOD = 'preset=custom&from=2026-11-01&to=2026-11-19';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    }

    /** @return array<string, mixed> */
    private function attention(string $query = self::PERIOD): array
    {
        return $this->getJson('/api/dashboard/executive/attention?'.$query)->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function rule(string $key, string $query = self::PERIOD): array
    {
        $rule = collect($this->attention($query)['rules'])->firstWhere('key', $key);
        $this->assertNotNull($rule, $key);

        return $rule;
    }

    private function threshold(string $field, string|int $value): void
    {
        DashboardSetting::current()->forceFill([$field => $value])->save();
    }

    /** A released loan whose first period fell due on `$due` and is unpaid. */
    private function lateLoan(string $due, ?Branch $branch = null): Loan
    {
        $loan = $this->createReleasedLoan();
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)->update(['due_date' => $due]);
        if ($branch !== null) {
            DB::table('loans')->where('id', $loan->id)->update(['branch_id' => $branch->id]);
        }

        return $loan->fresh();
    }

    private function waitingLoan(string $submitted, bool $approve = false, ?Branch $branch = null): Loan
    {
        $service = app(LoanService::class);
        $this->travelTo(Carbon::parse($submitted));
        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => ($branch ?? $this->branch)->id])->id,
            'loan_product_id' => LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly'])->id,
            'principal_amount' => 10000,
            'start_date' => '2026-11-20',
        ], $this->admin);
        $service->submitForReview($loan);
        if ($approve) {
            $service->approve($loan, $this->admin, 'Approved');
        }
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));

        return $loan->fresh();
    }

    private function userWith(array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'ebd2_alerts_'.count(Role::all()), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($branch ?? $this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_the_rules_come_in_the_settings_order_and_alerts_lists_only_those_needing_attention(): void
    {
        $data = $this->attention();

        $this->assertSame(
            ['par30_rise', 'collection_efficiency', 'overdue_share', 'approval_wait', 'release_wait', 'control_difference'],
            array_column($data['rules'], 'key'),
        );
        $this->assertSame(DashboardSetting::current()->thresholds(), $data['thresholds']);
        $this->assertSame(
            array_values(array_filter($data['rules'], fn (array $rule) => $rule['state'] === 'needs_attention')),
            $data['alerts'],
        );
        $this->assertSame('2026-11-19', $data['as_of']);
    }

    public function test_par30_rise_is_at_least_its_threshold(): void
    {
        $this->lateLoan('2026-10-01');
        $current = app(ReportService::class)->loanBalanceSummary(['branch_ids' => null])['par_30']['ratio'];
        PortfolioDailySnapshot::query()->create([
            ...array_fill_keys(PortfolioSnapshotLine::FIGURES, 0),
            'snapshot_date' => '2026-10-20',
            'outstanding_principal' => 100,
            'par_30_amount' => 60,
            'total_outstanding' => 100,
            'overdue_amount' => 0,
        ]);
        $rise = round($current - 60, 2);
        $this->assertGreaterThan(1, $rise, 'The fixture needs a rise to test.');

        $this->threshold('par30_rise_points', number_format($rise, 2, '.', ''));
        $rule = $this->rule('par30_rise');
        $this->assertSame('needs_attention', $rule['state'], 'At the threshold.');
        $this->assertEquals($rise, $rule['value']);
        $this->assertSame('2026-10-20', $rule['compared_with']['snapshot_date']);
        $this->assertEquals(60, $rule['compared_with']['ratio']);

        $this->threshold('par30_rise_points', number_format($rise + 0.01, 2, '.', ''));
        $this->assertSame('clear', $this->rule('par30_rise')['state'], 'Below the threshold.');

        $this->threshold('par30_rise_points', number_format($rise - 0.01, 2, '.', ''));
        $this->assertSame('needs_attention', $this->rule('par30_rise')['state'], 'Above the threshold.');
    }

    public function test_par30_rise_without_an_earlier_snapshot_is_not_measured(): void
    {
        $this->lateLoan('2026-10-01');

        $rule = $this->rule('par30_rise');

        $this->assertSame('no_snapshot', $rule['state']);
        $this->assertNull($rule['value']);
    }

    public function test_collection_efficiency_is_strictly_below_its_threshold(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)->update(['due_date' => '2026-11-18']);
        app(RepaymentService::class)->processRepayment($loan->fresh(), 10620, '2026-11-19', $this->admin);
        $rate = app(ReportService::class)->collectionEfficiency(['date_from' => '2026-11-01', 'date_to' => '2026-11-19', 'branch_ids' => null])['collection_rate'];
        $this->assertEquals(90, $rate);

        $this->threshold('collection_efficiency_below', '90.00');
        $rule = $this->rule('collection_efficiency');
        $this->assertSame('clear', $rule['state'], 'At the threshold.');
        $this->assertEquals(90, $rule['value']);

        $this->threshold('collection_efficiency_below', '90.01');
        $this->assertSame('needs_attention', $this->rule('collection_efficiency')['state'], 'Below the threshold.');

        $this->threshold('collection_efficiency_below', '89.99');
        $this->assertSame('clear', $this->rule('collection_efficiency')['state'], 'Above the threshold.');
    }

    public function test_collection_efficiency_with_nothing_due_has_no_data(): void
    {
        $this->assertSame('no_data', $this->rule('collection_efficiency', 'preset=custom&from=2026-01-01&to=2026-01-31')['state']);
    }

    public function test_overdue_share_is_strictly_above_its_threshold(): void
    {
        $this->lateLoan('2026-10-01');
        $summary = app(ReportService::class)->loanBalanceSummary(['branch_ids' => null]);
        $share = round($summary['past_due_amount'] / $summary['total_outstanding'] * 100, 2);

        $this->threshold('overdue_share_above', number_format($share, 2, '.', ''));
        $rule = $this->rule('overdue_share');
        $this->assertSame('clear', $rule['state'], 'At the threshold.');
        $this->assertEquals($share, $rule['value']);

        $this->threshold('overdue_share_above', number_format($share - 0.01, 2, '.', ''));
        $this->assertSame('needs_attention', $this->rule('overdue_share')['state'], 'Above the threshold.');

        $this->threshold('overdue_share_above', number_format($share + 0.01, 2, '.', ''));
        $this->assertSame('clear', $this->rule('overdue_share')['state'], 'Below the threshold.');
    }

    public function test_approval_wait_is_strictly_more_than_its_days(): void
    {
        $this->waitingLoan('2026-11-16 09:00:00');

        $this->threshold('approval_wait_days', 3);
        $rule = $this->rule('approval_wait');
        $this->assertSame('clear', $rule['state'], 'Waited exactly 3 days.');
        $this->assertSame(0, $rule['value']);

        $this->threshold('approval_wait_days', 2);
        $rule = $this->rule('approval_wait');
        $this->assertSame('needs_attention', $rule['state'], 'More than 2 days.');
        $this->assertSame(1, $rule['value']);
        $this->assertSame(3, $rule['oldest_days']);

        $this->threshold('approval_wait_days', 4);
        $this->assertSame('clear', $this->rule('approval_wait')['state'], 'Not yet 4 days.');
    }

    public function test_release_wait_is_strictly_more_than_its_days(): void
    {
        $this->waitingLoan('2026-11-17 09:00:00', approve: true);

        $this->threshold('release_wait_days', 2);
        $this->assertSame('clear', $this->rule('release_wait')['state'], 'Waited exactly 2 days.');

        $this->threshold('release_wait_days', 1);
        $rule = $this->rule('release_wait');
        $this->assertSame('needs_attention', $rule['state']);
        $this->assertSame(1, $rule['value']);

        $this->threshold('release_wait_days', 3);
        $this->assertSame('clear', $this->rule('release_wait')['state']);
    }

    public function test_a_control_difference_is_strictly_above_its_tolerance(): void
    {
        $this->seedChartOfAccounts();
        AccountingOpeningBalance::query()->create(['as_of' => '2026-08-31', 'created_by' => $this->admin->id])
            ->forceFill(['status' => AccountingOpeningBalance::STATUS_FINALISED, 'finalised_at' => now()])->save();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        ShareCapitalLedger::create(['borrower_id' => $member->id, 'date' => '2026-11-02', 'description' => 'Share capital', 'credit' => 1000, 'debit' => 0, 'created_by' => $this->admin->id]);
        $this->postSimpleJournal('1010', '3060', 100050, ['date' => '2026-11-02']);

        $this->threshold('control_difference_above', '0.50');
        $rule = $this->rule('control_difference');
        $this->assertSame('clear', $rule['state'], 'At the tolerance.');
        $this->assertSame('0.50', $rule['value']);
        $this->assertSame('0.50', collect($rule['controls'])->firstWhere('key', 'share_capital')['difference']);

        $this->threshold('control_difference_above', '0.49');
        $rule = $this->rule('control_difference');
        $this->assertSame('needs_attention', $rule['state'], 'Above the tolerance.');
        $this->assertSame(['share_capital'], array_column(array_filter($rule['controls'], fn ($c) => $c['state'] === 'needs_attention'), 'key'));

        $this->threshold('control_difference_above', '0.00');
        $this->assertSame('needs_attention', $this->rule('control_difference')['state'], 'Any difference.');

        $this->threshold('control_difference_above', '0.51');
        $this->assertSame('clear', $this->rule('control_difference')['state'], 'Below the tolerance.');
    }

    public function test_book_controls_wait_for_the_books(): void
    {
        $this->assertSame('requires_configuration', $this->rule('control_difference')['state'], 'No chart.');

        $this->seedChartOfAccounts();
        $rule = $this->rule('control_difference');
        $this->assertSame('requires_configuration', $rule['state'], 'Opening balances not finalised.');
        $this->assertSame('The opening balances have not been finalised yet.', $rule['detail']);
    }

    public function test_a_rule_whose_source_the_caller_cannot_read_is_no_access(): void
    {
        $this->actingAs($this->userWith(['dashboard:analytics']));
        $this->assertSame(array_fill(0, 6, 'no_access'), array_column($this->attention()['rules'], 'state'));
        $this->assertSame([], $this->attention()['alerts']);

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view']));
        $states = collect($this->attention()['rules'])->pluck('state', 'key');
        $this->assertNotSame('no_access', $states['par30_rise']);
        $this->assertNotSame('no_access', $states['collection_efficiency']);
        $this->assertNotSame('no_access', $states['overdue_share']);
        $this->assertSame('no_access', $states['approval_wait']);
        $this->assertSame('no_access', $states['release_wait']);
        $this->assertSame('no_access', $states['control_difference']);

        $rule = collect($this->attention()['rules'])->firstWhere('key', 'approval_wait');
        $this->assertNull($rule['value'], 'No figure leaks through a rule the caller can\'t read.');
    }

    public function test_a_branch_user_s_rules_read_only_their_branches(): void
    {
        $other = Branch::factory()->create();
        $this->waitingLoan('2026-11-10 09:00:00', branch: $other);
        $this->lateLoan('2026-10-01', $other);

        $this->assertSame(1, $this->rule('approval_wait')['value']);

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'loans:view'], $this->branch));
        $this->assertSame(0, $this->rule('approval_wait')['value']);
        $this->assertSame('no_data', $this->rule('overdue_share')['state'], 'Nothing owed in the main branch.');
        $this->getJson('/api/dashboard/executive/attention?'.self::PERIOD."&branch_id={$other->id}")->assertForbidden();
    }

    public function test_the_wording_is_neutral(): void
    {
        $this->lateLoan('2026-10-01');
        $this->waitingLoan('2026-11-10 09:00:00');

        $text = strtolower(json_encode($this->attention()));

        foreach (['fraud', 'suspicious', 'theft', 'stolen'] as $word) {
            $this->assertStringNotContainsString($word, $text);
        }
    }

    public function test_it_needs_the_executive_dashboard(): void
    {
        $this->actingAs($this->userWith(['reports:view', 'loans:view', 'accounting:view']));

        $this->getJson('/api/dashboard/executive/attention?'.self::PERIOD)->assertForbidden();
    }
}
