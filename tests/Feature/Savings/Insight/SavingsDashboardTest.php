<?php

namespace Tests\Feature\Savings\Insight;

use App\Services\Savings\Dashboard\SavingsDashboard;
use App\Services\Savings\SavingsMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Savings dashboard (gap round S5, the Savings task's TASK 35 and 62):
 * each KPI on InsightHistory's figures to the centavo, the monthly series,
 * the breakdowns adding up, the maturity buckets, the GL control, the branch
 * scope and the product filter.
 */
class SavingsDashboardTest extends TestCase
{
    use InsightHistory, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->buildHistory();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_kpi_reads_the_server_figures_to_the_centavo(): void
    {
        $this->actingAs($this->admin)->getJson('/api/savings/dashboard?from=2026-11-01&to=2026-11-10')->assertOk()
            ->assertJsonPath('data.date_from', '2026-11-01')
            ->assertJsonPath('data.date_to', '2026-11-10')
            ->assertJsonPath('data.kpis.savings_balance', '13058.87')
            ->assertJsonPath('data.kpis.time_deposits', ['count' => 4, 'amount' => '37200.00'])
            ->assertJsonPath('data.kpis.total_deposits', '50258.87')
            ->assertJsonPath('data.kpis.accounts', ['active' => 3, 'dormant' => 1, 'frozen' => 1, 'as_of' => '2026-11-10'])
            ->assertJsonPath('data.kpis.new_accounts', 3)
            // B's ₱2,000.00 and C's ₱9,999.00; A's reversed ₱250.50 counts in no total.
            ->assertJsonPath('data.kpis.deposits', ['count' => 2, 'amount' => '11999.00'])
            // A's ₱300.00; the ₱200.00 moved into a time deposit isn't a withdrawal.
            ->assertJsonPath('data.kpis.withdrawals', ['count' => 1, 'amount' => '300.00'])
            ->assertJsonPath('data.kpis.net_deposit_movement', '11699.00')
            ->assertJsonPath('data.kpis.interest_credited', ['gross' => '12.34', 'tax' => '2.47', 'net' => '9.87'])
            ->assertJsonPath('data.kpis.time_deposit_interest_paid', ['gross' => '80.00', 'tax' => '16.00'])
            ->assertJsonPath('data.kpis.maturing_30_days', ['count' => 1, 'principal' => '10000.00', 'maturity_amount' => '10050.00']);
    }

    public function test_the_period_defaults_to_this_month_to_today_and_refuses_bad_dates(): void
    {
        $this->actingAs($this->admin)->getJson('/api/savings/dashboard')->assertOk()
            ->assertJsonPath('data.date_from', '2026-11-01')
            ->assertJsonPath('data.date_to', '2026-11-10');
        $this->actingAs($this->admin)->getJson('/api/savings/dashboard?to=2026-10-31')->assertOk()
            ->assertJsonPath('data.date_from', '2026-10-01')
            ->assertJsonPath('data.kpis.savings_balance', '1550.00')
            ->assertJsonPath('data.kpis.deposits', ['count' => 3, 'amount' => '1550.00']);

        $this->actingAs($this->admin)->getJson('/api/savings/dashboard?to=2026-11-11')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->actingAs($this->admin)->getJson('/api/savings/dashboard?from=2026-11-05&to=2026-11-04')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->actingAs($this->admin)->getJson('/api/savings/dashboard?product_id=999999')->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_the_monthly_series_starts_at_the_first_record_and_closes_on_the_kpis(): void
    {
        $series = $this->actingAs($this->admin)->getJson('/api/savings/dashboard?from=2026-11-01&to=2026-11-10')->assertOk()->json('data.series');

        $this->assertSame(['2026-09', '2026-10', '2026-11'], array_column($series, 'month'));
        $this->assertSame(['2026-09-30', '2026-10-31', '2026-11-10'], array_column($series, 'as_of'));
        $this->assertSame(['0.00', '1550.00', '13058.87'], array_column($series, 'savings_balance'));
        $this->assertSame(['5000.00', '25000.00', '37200.00'], array_column($series, 'time_deposits'));
        $this->assertSame(['5000.00', '26550.00', '50258.87'], array_column($series, 'total_deposits'));
        $this->assertSame(['5000.00', '21550.00', '23708.87'], array_column($series, 'net_movement'));
        $this->assertSame(['0.00', '1550.00', '11999.00'], array_column($series, 'deposits'));
        $this->assertSame([0, 3, 2], array_column($series, 'deposit_count'));
        $this->assertSame(['0.00', '0.00', '300.00'], array_column($series, 'withdrawals'));
        $this->assertSame(['0.00', '0.00', '12.34'], array_column($series, 'interest_credited'));
        $this->assertSame('Nov 2026', $series[2]['label']);
    }

    public function test_the_series_covers_at_most_twelve_months(): void
    {
        $this->onDay('2027-12-15');

        $series = $this->actingAs($this->admin)->getJson('/api/savings/dashboard')->assertOk()->json('data.series');

        $this->assertCount(SavingsDashboard::SERIES_MONTHS, $series);
        $this->assertSame('2027-01', $series[0]['month']);
        $this->assertSame('2027-12', $series[11]['month']);
    }

    public function test_the_maturity_buckets_count_time_deposits_outstanding_by_days_to_maturity(): void
    {
        $buckets = collect($this->actingAs($this->admin)->getJson('/api/savings/dashboard')->assertOk()->json('data.maturity_buckets'))->keyBy('key');

        $this->assertSame(['0_30', '31_60', '61_90', 'over_90'], $buckets->keys()->all());
        $this->assertSame([1, '10000.00', '10050.00'], [$buckets['0_30']['count'], $buckets['0_30']['principal'], $buckets['0_30']['maturity_amount']]);
        $this->assertSame([1, '20000.00'], [$buckets['31_60']['count'], $buckets['31_60']['principal']]);
        $this->assertSame([0, '0.00'], [$buckets['61_90']['count'], $buckets['61_90']['principal']]);
        $this->assertSame([2, '7200.00', '7251.00'], [$buckets['over_90']['count'], $buckets['over_90']['principal'], $buckets['over_90']['maturity_amount']]);
        $this->assertSame('31–60 days', $buckets['31_60']['label']);
    }

    public function test_the_breakdowns_list_every_branch_and_product_and_add_up_to_their_totals(): void
    {
        $data = $this->actingAs($this->admin)->getJson('/api/savings/dashboard?from=2026-11-01&to=2026-11-10')->assertOk()->json('data');

        foreach (['by_branch', 'by_product'] as $breakdown) {
            $this->assertRowsAddUp($data[$breakdown]['rows'], $data[$breakdown]['totals']);
            $this->assertSame($data['kpis']['savings_balance'], $data[$breakdown]['totals']['closing_balance']);
            $this->assertSame($data['kpis']['total_deposits'], $data[$breakdown]['totals']['total_deposits']);
        }

        $branches = collect($data['by_branch']['rows'])->keyBy('group_name');
        $this->assertSame('3059.87', $branches[$this->branch->name]['closing_balance']);
        $this->assertSame('30200.00', $branches[$this->branch->name]['time_deposits']);
        $this->assertSame('9999.00', $branches['Far Branch']['closing_balance']);
        $this->assertSame('7000.00', $branches['Far Branch']['time_deposits']);

        $products = collect($data['by_product']['rows'])->keyBy('group_name');
        $this->assertSame('10908.87', $products['Regular Savings']['closing_balance']);
        $this->assertSame('2150.00', $products['Junior Savings']['closing_balance']);
        $this->assertSame('37200.00', $products['Time Deposit 90']['time_deposits']);
    }

    public function test_the_gl_control_status_shows_the_warning_when_the_books_differ(): void
    {
        $control = $this->actingAs($this->admin)->getJson('/api/savings/dashboard')->assertOk()->json('data.kpis.gl_control');

        $this->assertFalse($control['is_balanced']);
        $this->assertSame('2026-11-10', $control['as_of']);
        $this->assertSame(SavingsDashboard::GL_WARNING, $control['warning']);
        $this->assertSame('Deposit Subsidiary Ledger does not reconcile with General Ledger.', $control['warning']);
        $this->assertNotSame('0.00', $control['difference']);
        $this->assertFalse($control['product_filter_ignored']);
        $this->assertTrue($this->actingAs($this->admin)->getJson("/api/savings/dashboard?product_id={$this->regular->id}")->json('data.kpis.gl_control.product_filter_ignored'));
    }

    public function test_a_branch_user_sees_only_their_branches_and_another_branch_is_refused(): void
    {
        $viewer = $this->userWith(['savings:view']);

        $this->actingAs($viewer)->getJson('/api/savings/dashboard?from=2026-11-01&to=2026-11-10')->assertOk()
            ->assertJsonPath('data.kpis.savings_balance', '3059.87')
            ->assertJsonPath('data.kpis.time_deposits', ['count' => 3, 'amount' => '30200.00'])
            ->assertJsonPath('data.kpis.deposits', ['count' => 1, 'amount' => '2000.00'])
            ->assertJsonPath('data.kpis.new_accounts', 2)
            ->assertJsonPath('data.kpis.accounts', ['active' => 2, 'dormant' => 1, 'frozen' => 1, 'as_of' => '2026-11-10']);

        $rows = $this->actingAs($viewer)->getJson('/api/savings/dashboard')->json('data.by_branch.rows');
        $this->assertNotContains('Far Branch', array_column($rows, 'group_name'));

        $this->actingAs($viewer)->getJson("/api/savings/dashboard?branch_id={$this->far->id}")->assertForbidden();
        $this->actingAs($this->userWith(['reports:view']))->getJson('/api/savings/dashboard')->assertForbidden();
        $this->actingAs($this->admin)->getJson("/api/savings/dashboard?branch_id={$this->far->id}")->assertOk()
            ->assertJsonPath('data.kpis.savings_balance', '9999.00')
            ->assertJsonPath('data.kpis.time_deposits', ['count' => 1, 'amount' => '7000.00']);
    }

    public function test_the_product_filter_narrows_every_figure(): void
    {
        $this->actingAs($this->admin)->getJson("/api/savings/dashboard?from=2026-11-01&to=2026-11-10&product_id={$this->regular->id}")->assertOk()
            ->assertJsonPath('data.kpis.savings_balance', '10908.87')
            ->assertJsonPath('data.kpis.time_deposits', ['count' => 0, 'amount' => '0.00'])
            ->assertJsonPath('data.kpis.deposits', ['count' => 1, 'amount' => '9999.00'])
            ->assertJsonPath('data.kpis.withdrawals', ['count' => 1, 'amount' => '300.00'])
            ->assertJsonPath('data.kpis.new_accounts', 2)
            ->assertJsonPath('data.kpis.accounts', ['active' => 2, 'dormant' => 0, 'frozen' => 1, 'as_of' => '2026-11-10'])
            ->assertJsonCount(1, 'data.by_product.rows')
            ->assertJsonPath('data.by_product.rows.0.group_name', 'Regular Savings');

        $this->actingAs($this->admin)->getJson("/api/savings/dashboard?product_id={$this->timeProduct->id}")->assertOk()
            ->assertJsonPath('data.kpis.savings_balance', '0.00')
            ->assertJsonPath('data.kpis.time_deposits', ['count' => 4, 'amount' => '37200.00']);
    }

    /**
     * The series is worked out from one monthly flows query and the opening
     * balances, so twelve months cost the same queries as three.
     */
    public function test_the_query_count_does_not_grow_with_the_series(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $series = $this->actingAs($this->admin)->getJson('/api/savings/dashboard')->assertOk()->json('data.series');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertNotEmpty($series);

            return $count;
        };

        // The first request also loads the caller's permissions.
        $queries();
        $threeMonths = $queries();
        $this->onDay('2027-12-15');
        $twelveMonths = $queries();

        $this->assertSame($threeMonths, $twelveMonths);
        $this->assertLessThanOrEqual(60, $twelveMonths);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     */
    private function assertRowsAddUp(array $rows, array $totals): void
    {
        foreach ($totals as $field => $total) {
            $sum = array_sum(array_map(fn (array $row) => is_string($row[$field]) ? SavingsMath::signedCentavos($row[$field]) : $row[$field], $rows));
            $expected = is_string($total) ? SavingsMath::signedCentavos($total) : $total;

            $this->assertSame($expected, $sum, "The rows' {$field} add up to the total.");
        }
    }
}
