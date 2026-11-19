<?php

namespace Tests\Feature\Phase6\ExecutiveFigures;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\RepaymentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-A: the executive dashboard's product, officer and collector
 * filters on the reports it reads, through one LoanScope beside the branch
 * scope. Every response lists the filters it applied (`filters_applied`), and
 * a filter that narrows only some of its figures names them
 * (`filters_applied_to`), so a card never quietly ignores a filter.
 *
 * The collector is the user who recorded the payment
 * (`repayments.received_by`) and narrows receipts only.
 *
 * Each loan is ₱60,000.00 over 6 periods of ₱10,000.00 principal and
 * ₱1,800.00 interest, each on its own product.
 */
class ReportFiltersTest extends TestCase
{
    use SetupLendyPH;

    private const PERIOD = 'date_from=2026-11-01&date_to=2026-11-30';

    /** The reports whose every figure follows the product and officer filters. */
    private const LOAN_REPORTS = [
        'loan-balance-summary',
        'portfolio-by-product',
        'performance',
        'collection-efficiency',
        'income',
        'disbursements',
    ];

    private Loan $first;

    private Loan $second;

    private User $officer;

    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));

        $this->officer = User::factory()->inBranches($this->branch)->create();
        $this->collector = User::factory()->inBranches($this->branch)->create();

        $this->first = $this->createReleasedLoan();
        $this->second = $this->createReleasedLoan(['principal_amount' => 30000]);
        DB::table('loans')->where('id', $this->first->id)->update(['account_officer_id' => $this->officer->id]);
        DB::table('amortization_schedules')->where('loan_id', $this->first->id)->where('period_number', 1)
            ->update(['due_date' => '2026-11-19']);
        DB::table('amortization_schedules')->where('loan_id', $this->second->id)->where('period_number', 1)
            ->update(['due_date' => '2026-11-19']);

        $this->pay($this->first, 11800, $this->collector);
        $this->pay($this->second, 5900, $this->admin);

        // Share capital paid in on its own, which no loan filter can narrow.
        ShareCapitalLedger::create([
            'borrower_id' => $this->second->borrower_id,
            'date' => '2026-11-19',
            'description' => 'Share capital paid in',
            'debit' => 0,
            'credit' => 1000,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_every_figure_is_unchanged_when_the_filters_match_every_loan(): void
    {
        DB::table('loans')->whereIn('id', [$this->first->id, $this->second->id])->update(['account_officer_id' => $this->officer->id]);
        DB::table('repayments')->whereIn('loan_id', [$this->first->id, $this->second->id])->update(['received_by' => $this->collector->id]);

        foreach ([...self::LOAN_REPORTS, 'cash-flow'] as $report) {
            $plain = $this->report($report);
            $filtered = $this->report($report, "officer_id={$this->officer->id}&collector_id={$this->collector->id}");

            $this->assertSame([], $plain['filters_applied'], $report);
            $this->assertEquals($this->figures($plain), $this->figures($filtered), $report);
        }

        foreach (['releases', 'repayments'] as $list) {
            $plain = $this->getJson("/api/reports/{$list}?".self::PERIOD)->assertOk()->json();
            $filtered = $this->getJson("/api/reports/{$list}?".self::PERIOD."&officer_id={$this->officer->id}&collector_id={$this->collector->id}")->assertOk()->json();

            $this->assertEquals($plain['totals'], $filtered['totals'], $list);
            $this->assertEquals(Arr::pluck($plain['data'], 'id'), Arr::pluck($filtered['data'], 'id'), $list);
        }
    }

    public function test_the_product_filter_narrows_the_balance_summary_and_says_so(): void
    {
        $summary = $this->report('loan-balance-summary', "product_id={$this->second->loan_product_id}");

        // The ₱30,000.00 loan paid its first period: ₱5,000.00 principal and ₱900.00 interest.
        $this->assertSame(['product_id'], $summary['filters_applied']);
        $this->assertSame([], $summary['filters_applied_to']);
        $this->assertSame(1, $summary['portfolio']['loan_count']);
        $this->assertEquals(30000, $summary['portfolio']['total_released']);
        $this->assertEquals(25000, $summary['outstanding_principal']);
        $this->assertEquals(25000, $summary['by_branch_totals']['outstanding_principal']);
        $this->assertEquals(25000, $summary['aging_by_loan']['totals']['principal']);
        $this->assertEquals(25000, $summary['par_1']['amount'] + $this->currentPrincipal($summary));
    }

    public function test_the_officer_filter_narrows_each_loan_report(): void
    {
        foreach (self::LOAN_REPORTS as $report) {
            $this->assertSame(['officer_id'], $this->report($report, "officer_id={$this->officer->id}")['filters_applied'], $report);
        }

        $this->assertEquals(50000, $this->report('loan-balance-summary', "officer_id={$this->officer->id}")['outstanding_principal']);

        $products = $this->report('portfolio-by-product', "officer_id={$this->officer->id}");
        $this->assertSame([$this->first->loan_product_id], array_column($products['products'], 'product_id'));

        $performance = $this->report('performance', "officer_id={$this->officer->id}");
        $this->assertCount(1, $performance['by_officer']);
        $this->assertEquals(11800, $performance['totals']['collected']);

        $efficiency = $this->report('collection-efficiency', "officer_id={$this->officer->id}");
        $this->assertEquals(11800, $efficiency['total_due']);
        $this->assertEquals(11800, $efficiency['total_received']);

        $this->assertEquals(1800, $this->report('income', "officer_id={$this->officer->id}")['interest_income']);
        $this->assertSame(1, $this->report('disbursements', "officer_id={$this->officer->id}")['loans_released']);
    }

    public function test_the_collector_filter_narrows_receipts_only(): void
    {
        $cashFlow = $this->report('cash-flow', "collector_id={$this->collector->id}");

        $this->assertSame([], $cashFlow['filters_applied']);
        $this->assertContains('inflows.total', $cashFlow['filters_applied_to']['collector_id']);
        $this->assertContains('net_movement', $cashFlow['filters_applied_to']['collector_id']);
        $this->assertContains('by_branch_totals.inflow_total', $cashFlow['filters_applied_to']['collector_id']);
        $this->assertNotContains('outflows.total', $cashFlow['filters_applied_to']['collector_id']);
        $this->assertEquals(11800, $cashFlow['inflows']['repayments']['total']);
        // A total built from a filtered figure is filtered too: the collector's
        // ₱11,800.00 plus the ₱1,000.00 of share capital, which stays whole.
        $this->assertEquals(12800, $cashFlow['inflows']['total']);
        $this->assertEquals(1000, $cashFlow['inflows']['share_capital_credit']);
        $this->assertEquals(12800, $cashFlow['by_branch_totals']['inflow_total']);
        $this->assertSame(1, $cashFlow['inflows']['repayments']['count']);
        $this->assertSame(2, $cashFlow['outflows']['releases']['count'], 'Releases are not receipts.');

        $efficiency = $this->report('collection-efficiency', "collector_id={$this->collector->id}");
        $this->assertSame(['collector_id' => ['total_received', 'penalty_collected', 'payment_count']], $efficiency['filters_applied_to']);
        $this->assertEquals(11800, $efficiency['total_received']);
        $this->assertEquals(17700, $efficiency['total_due'], 'What fell due is not a receipt.');

        $income = $this->report('income', "collector_id={$this->collector->id}");
        $this->assertSame(['collector_id' => ['interest_income', 'penalty_income', 'total']], $income['filters_applied_to']);
        $this->assertEquals(1800, $income['interest_income']);

        $repayments = $this->getJson('/api/reports/repayments?'.self::PERIOD."&collector_id={$this->collector->id}")->assertOk();
        $this->assertSame(['collector_id'], $repayments->json('filters_applied'));
        $this->assertSame(1, $repayments->json('totals.count'));
        $this->assertEquals(11800, $repayments->json('totals.total_amount_paid'));
    }

    public function test_cash_flow_narrows_its_loan_cash_by_product_and_keeps_share_capital_whole(): void
    {
        $cashFlow = $this->report('cash-flow', "product_id={$this->first->loan_product_id}");

        $this->assertSame([], $cashFlow['filters_applied']);
        foreach (['inflows.repayments', 'inflows.total', 'outflows.releases', 'outflows.total', 'net_movement', 'by_branch.*.inflow_repayments', 'by_branch.*.outflow_net_proceeds', 'by_branch_totals.net_movement'] as $figure) {
            $this->assertContains($figure, $cashFlow['filters_applied_to']['product_id'], $figure);
        }
        $this->assertNotContains('share_capital', $cashFlow['filters_applied_to']['product_id']);
        $this->assertEquals(11800, $cashFlow['inflows']['repayments']['total']);
        $this->assertEquals(12800, $cashFlow['inflows']['total']);
        $this->assertEquals(round(12800 - $cashFlow['outflows']['total'], 2), $cashFlow['net_movement']);
        $this->assertEquals(60000, $cashFlow['non_cash']['principal_released']);
        $this->assertSame(1, $cashFlow['outflows']['releases']['count']);
        $this->assertEquals(11800, $cashFlow['by_branch_totals']['inflow_repayments']);
    }

    /**
     * Every figure a filter changes is named: a figure the filter moves is
     * either in `filters_applied` (the whole report) or covered by a path in
     * `filters_applied_to` (the path itself, or a block that holds it), and
     * every named path is a real figure in the response.
     */
    public function test_filters_applied_to_names_every_figure_a_filter_changes(): void
    {
        $cases = [
            ['cash-flow', "product_id={$this->first->loan_product_id}", 'product_id'],
            ['cash-flow', "officer_id={$this->officer->id}", 'officer_id'],
            ['cash-flow', "collector_id={$this->collector->id}", 'collector_id'],
            ['collection-efficiency', "collector_id={$this->collector->id}", 'collector_id'],
            ['income', "collector_id={$this->collector->id}", 'collector_id'],
            ['income/by-loan', "collector_id={$this->collector->id}", 'collector_id'],
        ];

        foreach ($cases as [$report, $query, $filter]) {
            $plain = $this->whole($report, '');
            $filtered = $this->whole($report, $query);
            $named = $filtered['filters_applied_to'][$filter] ?? [];

            $this->assertNotSame([], $named, "{$report} {$filter}");
            foreach ($named as $path) {
                $this->assertTrue($this->hasPath($filtered, $path), "{$report}: {$path} is not a figure in the response.");
            }

            $changed = $this->changedPaths($plain, $filtered);
            $this->assertNotSame([], $changed, "{$report} {$filter}: the filter changed nothing to check.");
            foreach ($changed as $path) {
                $covered = collect($named)->contains(fn (string $name) => $path === $name || str_starts_with($path, "{$name}."));
                $this->assertTrue($covered, "{$report} {$filter}: {$path} changed but is not named.");
            }
        }
    }

    public function test_daily_collection_says_it_applies_no_dashboard_filter(): void
    {
        $data = $this->getJson('/api/reports/daily-collection?'.self::PERIOD."&product_id={$this->first->loan_product_id}")->assertOk()->json('data');

        $this->assertSame([], $data['filters_applied']);
        $this->assertSame([], $data['filters_applied_to']);
        $this->assertEquals(17700, $data['total_due'], 'Both loans: daily collection is not narrowed by product.');
    }

    public function test_a_filtered_export_says_how_it_was_filtered(): void
    {
        DB::table('loan_products')->where('id', $this->first->loan_product_id)->update(['name' => 'Salary Loan']);
        DB::table('users')->where('id', $this->collector->id)->update(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $releases = $this->get('/api/reports/releases/export?'.self::PERIOD."&product_id={$this->first->loan_product_id}")->assertOk();
        $lines = explode("\n", trim($releases->streamedContent()));
        $this->assertSame('"Filtered by: loan product Salary Loan"', $lines[0]);
        $this->assertStringStartsWith('"Loan #"', $lines[1]);
        $this->assertCount(3, $lines, 'The note, the column names and the one loan.');
        $this->assertSame('product_id', $releases->headers->get('X-Filters-Applied'));

        $repayments = $this->get('/api/reports/repayments/export?'.self::PERIOD."&collector_id={$this->collector->id}")->assertOk();
        $this->assertSame('"Filtered by: collected by Ana Reyes"', explode("\n", $repayments->streamedContent())[0]);
        $this->assertSame('collector_id', $repayments->headers->get('X-Filters-Applied'));

        $nobody = $this->get('/api/reports/repayments/export?'.self::PERIOD."&collector_id={$this->officer->id}")->assertOk();
        $this->assertSame(
            "\"Filtered by: collected by user #{$this->officer->id}\"",
            explode("\n", $nobody->streamedContent())[0],
            'No rows, so no name: an id never looks up a name the caller can\'t see.',
        );

        $plain = $this->get('/api/reports/releases/export?'.self::PERIOD)->assertOk();
        $this->assertStringStartsWith('"Loan #"', $plain->streamedContent(), 'An unfiltered export is unchanged.');
        $this->assertNull($plain->headers->get('X-Filters-Applied'));
    }

    public function test_reports_that_cannot_take_a_filter_say_they_did_not(): void
    {
        foreach (['aging', 'provisioning', 'share-capital', 'borrowers'] as $report) {
            $data = $this->report($report, "product_id={$this->first->loan_product_id}&branch_id={$this->branch->id}");

            $this->assertSame([], $data['filters_applied'], $report);
            $this->assertSame([], $data['filters_applied_to'], $report);
        }

        $this->assertSame(
            [],
            $this->report('loan-balance-summary', "branch_id={$this->branch->id}&collector_id={$this->collector->id}")['filters_applied'],
            'The branch is always applied, so never listed; the collector is not a balance filter.',
        );
    }

    public function test_a_branch_user_s_filters_never_reach_another_branch(): void
    {
        $other = Branch::factory()->create();
        $role = Role::create(['name' => 'eba_branch_reporter', 'guard_name' => 'web']);
        $role->givePermissionTo(['reports:view']);
        $user = User::factory()->inBranches($other)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $summary = $this->report('loan-balance-summary', "product_id={$this->first->loan_product_id}");
        $this->assertSame(0, $summary['portfolio']['loan_count']);
        $this->assertEquals(0, $summary['outstanding_principal']);
        $this->assertEquals(0, $summary['par_1']['amount']);
        $this->assertSame(0, $summary['aging_by_loan']['totals']['loans']);

        $this->assertEquals(0, $this->report('cash-flow', "collector_id={$this->collector->id}")['inflows']['repayments']['total']);
        $this->assertSame([], $this->report('portfolio-by-product', "officer_id={$this->officer->id}")['products']);

        $this->getJson("/api/reports/loan-balance-summary?branch_id={$this->branch->id}&product_id={$this->first->loan_product_id}")
            ->assertForbidden();
    }

    public function test_a_filter_id_must_be_a_positive_whole_number(): void
    {
        foreach (['product_id', 'officer_id', 'collector_id'] as $filter) {
            $this->getJson("/api/reports/loan-balance-summary?{$filter}=0")->assertUnprocessable()->assertJsonValidationErrors($filter);
            $this->getJson("/api/reports/loan-balance-summary?{$filter}=abc")->assertUnprocessable()->assertJsonValidationErrors($filter);
        }
    }

    /** @return array<string, mixed> */
    private function report(string $report, string $query = ''): array
    {
        return $this->getJson("/api/reports/{$report}?".self::PERIOD.($query === '' ? '' : "&{$query}"))->assertOk()->json('data');
    }

    /**
     * The figures, without the filter lists and the time the report ran.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function figures(array $data): array
    {
        return Arr::except($data, ['filters_applied', 'filters_applied_to', 'generated_at']);
    }

    /** @return array<string, mixed> the whole JSON body of a report or list */
    private function whole(string $report, string $query): array
    {
        $body = $this->getJson("/api/reports/{$report}?".self::PERIOD.($query === '' ? '' : "&{$query}"))->assertOk()->json();

        return array_key_exists('data', $body) && ! array_is_list($body['data']) ? $body['data'] : $body;
    }

    /**
     * The leaf figures whose values differ, as paths with `*` for list
     * positions, without the filter lists and the time the report ran.
     *
     * @param  array<string, mixed>  $plain
     * @param  array<string, mixed>  $filtered
     * @return list<string>
     */
    private function changedPaths(array $plain, array $filtered): array
    {
        $ignored = ['filters_applied', 'filters_applied_to', 'generated_at', 'links'];
        $before = Arr::dot(Arr::except($plain, $ignored));
        $after = Arr::dot(Arr::except($filtered, $ignored));

        return collect(array_unique([...array_keys($before), ...array_keys($after)]))
            ->filter(fn (string $key) => ($before[$key] ?? null) != ($after[$key] ?? null))
            ->map(fn (string $key) => preg_replace('/\.\d+(?=\.|$)/', '.*', $key))
            ->unique()
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    private function hasPath(array $data, string $path): bool
    {
        $marker = new \stdClass;
        $found = data_get($data, $path, $marker);

        return $found !== $marker && $found !== [];
    }

    /** @param  array<string, mixed>  $summary */
    private function currentPrincipal(array $summary): float
    {
        return (float) collect($summary['aging_by_loan']['buckets'])->firstWhere('bucket', 'current')['principal'];
    }

    private function pay(Loan $loan, float $amount, User $by): void
    {
        app(RepaymentService::class)->processRepayment($loan->fresh(), $amount, '2026-11-19', $by);
    }
}
