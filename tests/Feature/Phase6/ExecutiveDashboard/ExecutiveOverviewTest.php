<?php

namespace Tests\Feature\Phase6\ExecutiveDashboard;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\AllowanceCalculator;
use App\Services\Accounting\IncomeStatementBuilder;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Dashboard\ExecutiveCacheKey;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D1: GET /api/dashboard/executive/overview, the executive
 * dashboard's cards. Each card calls its report's own service, so it equals
 * that report for the same period, branches and filters. Each has a value, the
 * previous value, a change (null when it can't be worked out), an as-of date
 * and a state: ready, no_snapshot, requires_configuration or no_access.
 *
 * Today is Thursday, Nov 19, 2026; "This Month" is Nov 1–19 and compares
 * with Oct 13–31.
 */
class ExecutiveOverviewTest extends TestCase
{
    use PostsJournals;
    use SetupLendyPH;

    private const CARDS = [
        'gross_loan_portfolio', 'total_outstanding', 'active_loans', 'loans_released', 'collections',
        'past_due_amount', 'par_30', 'active_members', 'collection_efficiency', 'interest_income',
        'net_income', 'share_capital', 'collected_insurance', 'required_allowance',
    ];

    private const PERIOD = 'date_from=2026-11-01&date_to=2026-11-19';

    private const PREVIOUS = 'date_from=2026-10-13&date_to=2026-10-31';

    private Loan $current;

    private Loan $late;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    }

    public function test_every_card_has_its_shape(): void
    {
        $data = $this->overview();

        $this->assertSame('this_month', $data['period']['preset']);
        $this->assertSame('2026-10-13', $data['period']['previous']['from']);
        $this->assertSame(self::CARDS, array_keys($data['cards']));

        foreach ($data['cards'] as $key => $card) {
            $this->assertSame(
                ['key', 'label', 'unit', 'value', 'previous_value', 'change', 'change_unit', 'as_of', 'previous_as_of', 'previous_state', 'state', 'filters_applied', 'details'],
                array_keys($card),
                $key,
            );
            $this->assertContains($card['state'], ['ready', 'no_snapshot', 'requires_configuration', 'no_access'], $key);
        }
    }

    public function test_each_card_equals_its_report(): void
    {
        $this->book();

        $cards = $this->overview()['cards'];
        $summary = $this->report('loan-balance-summary', '');
        $cashFlow = $this->report('cash-flow', self::PERIOD);
        $efficiency = $this->report('collection-efficiency', self::PERIOD);

        $this->assertEquals($summary['outstanding_principal'], $cards['gross_loan_portfolio']['value']);
        $this->assertEquals($summary['total_outstanding'], $cards['total_outstanding']['value']);
        $this->assertSame($summary['active_loans'], $cards['active_loans']['value']);
        $this->assertEquals($summary['past_due_amount'], $cards['past_due_amount']['value']);
        $this->assertSame($summary['past_due_loan_count'], $cards['past_due_amount']['details']['loans']);
        $this->assertEquals($summary['par_30']['ratio'], $cards['par_30']['value']);
        foreach (['par_1', 'par_30', 'par_60', 'par_90'] as $par) {
            $this->assertEquals($summary[$par], $cards['par_30']['details'][$par], $par);
        }

        $this->assertEquals($cashFlow['non_cash']['principal_released'], $cards['loans_released']['value'], 'Releases as principal.');
        $this->assertSame($cashFlow['outflows']['releases']['count'], $cards['loans_released']['details']['count']);
        $repayments = $cashFlow['inflows']['repayments'];
        $this->assertEquals(round($repayments['total'] - $repayments['insurance'], 2), $cards['collections']['value'], 'Collections without the premium.');
        $this->assertEquals($repayments['principal'], $cards['collections']['details']['principal']);
        $this->assertEquals($repayments['interest'], $cards['collections']['details']['interest']);
        $this->assertEquals($repayments['penalty'], $cards['collections']['details']['penalty']);
        $this->assertEquals($repayments['overpayment'], $cards['collections']['details']['build_up_and_overpayment']);
        $this->assertEquals($repayments['insurance'], $cards['collected_insurance']['value']);
        $this->assertEquals($efficiency['collection_rate'], $cards['collection_efficiency']['value']);

        $this->assertSame($this->report('borrowers', self::PERIOD)['total_active_borrowers'], $cards['active_members']['value']);
        $this->assertEquals($this->report('provisioning', '')['principal_basis']['totals']['required_allowance'], $cards['required_allowance']['value'], 'The figure the allowance posting books.');
        $this->assertEquals($this->report('share-capital', self::PERIOD)['closing_balance'], $cards['share_capital']['value']);

        $statement = app(IncomeStatementBuilder::class)->build('2026-11-01', '2026-11-19');
        $interest = collect($statement['income'])->firstWhere('account_code', '4010')['amount'] ?? 0;
        $this->assertGreaterThan(0, $interest);
        $this->assertEquals($interest / 100, $cards['interest_income']['value'], 'Book interest, cash basis.');
        $this->assertSame('Interest income (cash basis)', $cards['interest_income']['label']);
        $accounting = $this->getJson('/api/accounting/dashboard?as_of=2026-11-19&from=2026-11-01')->assertOk()->json('data');
        $this->assertEquals($accounting['period']['net_income'] / 100, $cards['net_income']['value']);
        $this->assertSame('Net Income', $cards['net_income']['label'], 'The lending company chart.');

        foreach ($cards as $key => $card) {
            $this->assertSame('ready', $card['state'], $key);
        }
    }

    public function test_required_allowance_is_what_the_allowance_posting_books(): void
    {
        $this->book();

        $card = $this->overview()['cards']['required_allowance'];
        $posting = app(AllowanceCalculator::class)->forDate('2026-11-19')['totals']['required'];
        $report = $this->report('provisioning', '');

        $this->assertGreaterThan(0, $posting);
        $this->assertEquals($posting / 100, $card['value'], 'Provisioning rates on past-due principal, each bucket rounded half up.');
        $this->assertNotEquals($report['totals']['required_allowance'], $card['value'], 'Not the past-due amount basis.');
    }

    public function test_a_branch_user_s_required_allowance_is_their_branch_s_posting(): void
    {
        $this->book();
        $other = Branch::factory()->create();
        $there = $this->createReleasedLoan(['principal_amount' => 40000]);
        DB::table('loans')->where('id', $there->id)->update(['branch_id' => $other->id]);
        DB::table('borrowers')->where('id', $there->borrower_id)->update(['branch_id' => $other->id]);
        DB::table('amortization_schedules')->where('loan_id', $there->id)->where('period_number', 1)->update(['due_date' => '2026-07-01']);

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view'], $other));
        $card = $this->overview()['cards']['required_allowance'];

        $posting = app(AllowanceCalculator::class)->forDate('2026-11-19', [$other->id])['totals']['required'];
        $this->assertGreaterThan(0, $posting);
        $this->assertEquals($posting / 100, $card['value']);
        $whole = app(AllowanceCalculator::class)->forDate('2026-11-19')['totals']['required'];
        $this->assertNotEquals($whole / 100, $card['value'], 'Their branch only, not the whole book.');
    }

    public function test_previous_state_says_why_a_card_has_no_previous_value(): void
    {
        $this->book();

        $cards = $this->overview()['cards'];
        $this->assertSame('no_snapshot', $cards['gross_loan_portfolio']['previous_state'], 'No snapshot on Oct 31.');
        $this->assertSame('ready', $cards['loans_released']['previous_state']);
        $this->assertSame('no_data', $cards['required_allowance']['previous_state'], 'Worked out from today\'s schedules only.');

        $this->snapshot('2026-10-31', 100000);
        Cache::flush();
        $this->assertSame('ready', $this->overview()['cards']['gross_loan_portfolio']['previous_state']);

        $officer = $this->overview("officer_id={$this->admin->id}")['cards']['gross_loan_portfolio'];
        $this->assertNull($officer['previous_value']);
        $this->assertSame('no_data', $officer['previous_state'], 'History doesn\'t follow the officer filter.');

        $this->actingAs($this->userWith(['dashboard:analytics']));
        $this->assertNull($this->overview()['cards']['gross_loan_portfolio']['previous_state']);
    }

    public function test_period_cards_compare_with_the_previous_period(): void
    {
        $this->book();

        $cards = $this->overview()['cards'];
        $previousFlow = $this->report('cash-flow', self::PREVIOUS);

        // Released: ₱90,000.00 this month, ₱60,000.00 in the comparison period.
        $this->assertEquals(90000, $cards['loans_released']['value']);
        $this->assertEquals($previousFlow['non_cash']['principal_released'], $cards['loans_released']['previous_value']);
        $this->assertEquals(50.0, $cards['loans_released']['change']);
        $this->assertSame('percent', $cards['loans_released']['change_unit']);
        $this->assertSame('2026-10-31', $cards['loans_released']['previous_as_of']);

        $this->assertEquals($this->report('collection-efficiency', self::PREVIOUS)['collection_rate'], $cards['collection_efficiency']['previous_value']);
        $this->assertSame('points', $cards['collection_efficiency']['change_unit']);
        $this->assertEquals(
            round($cards['collection_efficiency']['value'] - $cards['collection_efficiency']['previous_value'], 2),
            $cards['collection_efficiency']['change'],
        );

        $this->assertEquals(5000, $cards['collections']['previous_value']);
        $this->assertEquals($this->report('share-capital', self::PERIOD)['opening_balance'], $cards['share_capital']['previous_value'], 'The balance on Oct 31.');
    }

    public function test_balance_cards_compare_with_the_snapshot_at_the_end_of_the_previous_period(): void
    {
        $this->book();
        $this->snapshot('2026-10-31', 100000);

        $cards = $this->overview()['cards'];

        $this->assertEquals(100000, $cards['gross_loan_portfolio']['previous_value']);
        $this->assertSame('2026-10-31', $cards['gross_loan_portfolio']['previous_as_of']);
        $expected = round(($cards['gross_loan_portfolio']['value'] - 100000) / 100000 * 100, 2);
        $this->assertEquals($expected, $cards['gross_loan_portfolio']['change']);
        $this->assertSame('2026-11-19', $cards['gross_loan_portfolio']['as_of']);
    }

    public function test_a_balance_with_no_snapshot_to_compare_has_no_change(): void
    {
        $this->book();

        $card = $this->overview()['cards']['gross_loan_portfolio'];

        $this->assertSame('ready', $card['state']);
        $this->assertNull($card['previous_value']);
        $this->assertNull($card['change']);
    }

    public function test_a_past_period_reads_its_balances_from_the_snapshot(): void
    {
        $this->book();
        $this->snapshot('2026-10-31', 100000);

        $cards = $this->overview('preset=last_month')['cards'];
        $this->assertSame('ready', $cards['gross_loan_portfolio']['state']);
        $this->assertEquals(100000, $cards['gross_loan_portfolio']['value']);
        $this->assertSame('2026-10-31', $cards['gross_loan_portfolio']['as_of']);

        $before = $this->overview('preset=custom&date_from=2026-08-01&date_to=2026-08-31')['cards'];
        $this->assertSame('no_snapshot', $before['gross_loan_portfolio']['state']);
        $this->assertNull($before['gross_loan_portfolio']['value']);
        $this->assertSame('no_snapshot', $before['required_allowance']['state'], 'Worked out from today\'s schedules only.');
    }

    public function test_change_is_null_when_the_previous_value_is_zero(): void
    {
        $this->seedChartOfAccounts();
        $this->createReleasedLoan();

        $card = $this->overview()['cards']['loans_released'];

        $this->assertEquals(0, $card['previous_value']);
        $this->assertNull($card['change'], 'Undefined against zero.');
    }

    public function test_book_cards_require_a_chart_of_accounts(): void
    {
        $cards = $this->overview()['cards'];

        foreach (['interest_income', 'net_income'] as $key) {
            $this->assertSame('requires_configuration', $cards[$key]['state'], $key);
            $this->assertNull($cards[$key]['value'], $key);
        }
    }

    public function test_each_missing_source_permission_reads_no_access(): void
    {
        $this->book();

        $this->actingAs($this->userWith(['dashboard:analytics']));
        foreach ($this->overview()['cards'] as $key => $card) {
            $this->assertSame('no_access', $card['state'], $key);
            $this->assertNull($card['value'], $key);
            $this->assertNull($card['previous_value'], $key);
            $this->assertNull($card['change'], $key);
            $this->assertSame([], $card['details'], $key);
            $this->assertNull($card['previous_as_of'], "{$key}: nothing about what lies behind it.");
            $this->assertNull($card['previous_state'], "{$key}: nor why it has no previous value.");
        }

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view']));
        $cards = $this->overview()['cards'];
        $this->assertSame('ready', $cards['gross_loan_portfolio']['state']);
        $this->assertSame('no_access', $cards['share_capital']['state'], 'Share capital needs its own permission.');
        $this->assertSame('no_access', $cards['interest_income']['state'], 'The books need accounting access.');
        $this->assertSame('no_access', $cards['net_income']['state']);

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view', 'share_capital:view', 'accounting:view']));
        $cards = $this->overview()['cards'];
        $this->assertSame('ready', $cards['share_capital']['state']);
        $this->assertSame('ready', $cards['net_income']['state']);
    }

    public function test_the_overview_says_when_it_was_worked_out(): void
    {
        $this->assertSame(now()->toDateTimeString(), $this->overview()['generated_at']);
    }

    public function test_a_filter_must_name_a_real_record(): void
    {
        foreach (['product_id', 'officer_id', 'collector_id'] as $filter) {
            $this->getJson("/api/dashboard/executive/overview?{$filter}=999999")->assertUnprocessable()->assertJsonValidationErrors($filter);
        }
    }

    public function test_the_cache_key_ignores_the_order_and_form_of_the_branch_ids(): void
    {
        $period = DashboardPeriod::resolve('this_month');
        $filters = ['product_id' => '7', 'officer_id' => null, 'collector_id' => null];

        $this->assertSame(
            ExecutiveCacheKey::for('overview', $period, [5, 3], $filters),
            ExecutiveCacheKey::for('overview', $period, ['3', '+5'], ['product_id' => 7]),
        );
        $this->assertNotSame(
            ExecutiveCacheKey::for('overview', $period, [3, 5], $filters),
            ExecutiveCacheKey::for('overview', $period, [3], $filters),
        );
        $this->assertNotSame(
            ExecutiveCacheKey::for('overview', $period, null, $filters),
            ExecutiveCacheKey::for('branches', $period, null, $filters),
        );
    }

    public function test_it_needs_the_executive_dashboard(): void
    {
        $this->actingAs($this->userWith(['reports:view', 'dashboard:view']));

        $this->getJson('/api/dashboard/executive/overview')->assertForbidden();
    }

    public function test_a_branch_user_sees_only_their_branches(): void
    {
        $this->book();
        $other = Branch::factory()->create();
        $there = $this->createReleasedLoan(['principal_amount' => 40000]);
        DB::table('loans')->where('id', $there->id)->update(['branch_id' => $other->id]);
        DB::table('borrowers')->where('id', $there->borrower_id)->update(['branch_id' => $other->id]);

        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view'], $other));
        $cards = $this->overview()['cards'];

        $this->assertEquals(40000, $cards['gross_loan_portfolio']['value']);
        $this->assertEquals(40000, $cards['loans_released']['value']);
        $this->assertEquals(0, $cards['collections']['value']);

        $this->getJson("/api/dashboard/executive/overview?branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_the_cache_never_serves_one_scope_s_figures_to_another(): void
    {
        $this->book();
        $other = Branch::factory()->create();

        $whole = $this->overview()['cards']['gross_loan_portfolio']['value'];
        $this->actingAs($this->userWith(['dashboard:analytics', 'reports:view'], $other));
        $this->assertEquals(0, $this->overview()['cards']['gross_loan_portfolio']['value'], 'Another scope, its own figures.');

        $this->actingAs($this->admin);
        $product = $this->overview("product_id={$this->current->loan_product_id}")['cards']['gross_loan_portfolio'];
        $this->assertNotEquals($whole, $product['value'], 'Another filter, its own figures.');
        $this->assertSame(['product_id'], $product['filters_applied']);
    }

    public function test_permissions_apply_after_the_cache(): void
    {
        $this->book();
        $this->overview();

        // Same scope (every branch), fewer permissions: nothing from the
        // admin's cached figures leaks through.
        $role = Role::create(['name' => 'ebd1_view_all_reports', 'guard_name' => 'web']);
        $role->givePermissionTo(['dashboard:analytics', 'reports:view', 'branches:view_all']);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $cards = $this->overview()['cards'];
        $this->assertSame('ready', $cards['gross_loan_portfolio']['state']);
        $this->assertSame('no_access', $cards['net_income']['state']);
        $this->assertNull($cards['net_income']['value']);
    }

    public function test_the_filters_each_card_followed_are_listed(): void
    {
        $this->book();

        $cards = $this->overview("product_id={$this->current->loan_product_id}&collector_id={$this->admin->id}")['cards'];

        $this->assertSame(['product_id'], $cards['gross_loan_portfolio']['filters_applied']);
        $this->assertSame(['product_id', 'collector_id'], $cards['collections']['filters_applied']);
        $this->assertSame([], $cards['net_income']['filters_applied'], 'The books follow the branch only.');
        $this->assertSame([], $cards['share_capital']['filters_applied']);
    }

    public function test_a_period_it_can_t_read_is_refused(): void
    {
        $this->getJson('/api/dashboard/executive/overview?preset=next_week')->assertUnprocessable()->assertJsonValidationErrors('preset');
        $this->getJson('/api/dashboard/executive/overview?preset=custom&date_from=2026-11-01&date_to=2026-11-25')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/api/dashboard/executive/overview?product_id=0')->assertUnprocessable()->assertJsonValidationErrors('product_id');
    }

    /**
     * A ₱90,000.00 loan released today and paid ₱11,800.00; a ₱60,000.00 loan
     * released on Oct 15, paid ₱5,000.00 on Oct 20, its first period due
     * Oct 5 (45 days late); ₱1,000.00 of share capital paid in on Oct 20 and
     * ₱500.00 on Nov 10.
     */
    private function book(): void
    {
        $this->seedChartOfAccounts();
        $this->current = $this->createReleasedLoan(['principal_amount' => 90000]);
        $this->pay($this->current, 11800, '2026-11-19');

        $this->late = $this->createReleasedLoan();
        DB::table('loans')->where('id', $this->late->id)->update(['released_at' => '2026-10-15 09:00:00']);
        DB::table('amortization_schedules')->where('loan_id', $this->late->id)->where('period_number', 1)->update(['due_date' => '2026-10-05']);
        $this->pay($this->late, 5000, '2026-10-20');

        foreach (['2026-10-20' => 1000, '2026-11-10' => 500] as $date => $amount) {
            ShareCapitalLedger::create([
                'borrower_id' => $this->current->borrower_id,
                'date' => $date,
                'description' => 'Share capital paid in',
                'debit' => 0,
                'credit' => $amount,
                'created_by' => $this->admin->id,
            ]);
        }
    }

    private function pay(Loan $loan, float $amount, string $date): void
    {
        app(RepaymentService::class)->processRepayment($loan->fresh(), $amount, $date, $this->admin);
    }

    private function snapshot(string $date, float $principal): void
    {
        PortfolioDailySnapshot::query()->create([
            'snapshot_date' => $date,
            'overdue_loans_count' => 0,
            'overdue_amount' => 0,
            'total_outstanding' => $principal,
            'outstanding_principal' => $principal,
            'par_1_amount' => 0,
            'par_30_amount' => 0,
            'par_90_amount' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function overview(string $query = ''): array
    {
        return $this->getJson('/api/dashboard/executive/overview'.($query === '' ? '' : "?{$query}"))->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function report(string $report, string $query): array
    {
        return $this->getJson("/api/reports/{$report}".($query === '' ? '' : "?{$query}"))->assertOk()->json('data');
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'ebd1_'.md5(implode(',', $permissions).($branch?->id ?? '')), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($branch ?? $this->branch)->create();
        $user->assignRole($role);

        return $user;
    }
}
