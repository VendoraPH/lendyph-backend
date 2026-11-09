<?php

namespace Tests\Feature\Accounting\Dashboard;

use App\Models\AccountingPeriod;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The dashboard's period figures, money accounts, 12-month trend, accounting
 * period and recent journals (Phase 2 decision 10). Centavos throughout.
 */
class DashboardPeriodAndTrendTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function dashboard(string $query = 'as_of=2026-09-30'): array
    {
        return $this->getJson('/api/accounting/dashboard?'.$query)->assertOk()->json('data');
    }

    public function test_the_period_defaults_to_the_first_of_as_ofs_month_and_equals_month_to_date(): void
    {
        $this->postSimpleJournal('1010', '4010', 700000, ['date' => '2026-08-20']);
        $this->postSimpleJournal('1010', '4010', 250000, ['date' => '2026-09-10']);
        $this->postSimpleJournal('5030', '1010', 90000, ['date' => '2026-09-12']);
        // After as_of: never counted.
        $this->postSimpleJournal('1010', '4010', 11100, ['date' => '2026-10-01']);

        $dashboard = $this->dashboard();

        $this->assertSame([
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'income' => 250000,
            'expenses' => 90000,
            'net_income' => 160000,
        ], $dashboard['period']);
        $this->assertSame($dashboard['income_mtd'], $dashboard['period']['income']);
        $this->assertSame($dashboard['expenses_mtd'], $dashboard['period']['expenses']);
        $this->assertSame($dashboard['net_income_mtd'], $dashboard['period']['net_income']);
    }

    public function test_a_chosen_from_covers_its_own_range_and_a_loss_reads_negative(): void
    {
        $this->postSimpleJournal('1010', '4010', 700000, ['date' => '2026-08-20']);
        $this->postSimpleJournal('5030', '1010', 900000, ['date' => '2026-09-12']);
        // A debit to an income account reduces income.
        $this->postSimpleJournal('4010', '1010', 50000, ['date' => '2026-09-13']);

        $period = $this->dashboard('as_of=2026-09-30&from=2026-08-01')['period'];

        $this->assertSame('2026-08-01', $period['from']);
        $this->assertSame(650000, $period['income']);
        $this->assertSame(900000, $period['expenses']);
        $this->assertSame(-250000, $period['net_income']);
    }

    public function test_from_must_be_a_y_m_d_date_not_after_as_of(): void
    {
        $this->getJson('/api/accounting/dashboard?as_of=2026-09-30&from=2026-10-01')
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/accounting/dashboard?as_of=2026-09-30&from=09/01/2026')
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/accounting/dashboard?as_of=2026-09-30&from=2026-09-30')->assertOk();
    }

    public function test_cash_accounts_list_every_money_account_in_code_order_and_add_up_to_the_cash_cards(): void
    {
        $this->postSimpleJournal('1010', '3010', 500000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1040', '3010', 2000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1020', '3010', 150000, ['date' => '2026-09-01']);

        $dashboard = $this->dashboard();
        $accounts = collect($dashboard['cash_accounts']);

        $this->assertSame(['1010', '1020', '1030', '1040'], $accounts->pluck('account_code')->all());
        $this->assertSame([
            'account_id' => $this->account('1010'),
            'account_code' => '1010',
            'account_name' => 'Cash on Hand',
            'cash_kind' => 'cash',
            'balance' => 500000,
        ], $accounts->first());
        $this->assertSame(0, $accounts->firstWhere('account_code', '1030')['balance']);
        $this->assertSame(
            $dashboard['cash_on_hand'] + $dashboard['cash_in_bank'] + $dashboard['e_wallets'],
            $accounts->sum('balance'),
        );
    }

    public function test_months_are_the_twelve_ending_with_as_ofs_month_oldest_first(): void
    {
        // Before the window: only its cash carries in.
        $this->postSimpleJournal('1010', '3010', 1000000, ['date' => '2025-06-01']);
        $this->postSimpleJournal('1010', '4010', 30000, ['date' => '2025-10-31']);
        $this->postSimpleJournal('5030', '1010', 10000, ['date' => '2026-02-14']);
        $this->postSimpleJournal('1010', '4010', 20000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('5030', '1040', 5000, ['date' => '2026-09-15']);
        // After as_of, inside its month: left out of the month and its cash.
        $this->postSimpleJournal('1010', '4010', 99900, ['date' => '2026-09-20']);

        $months = collect($this->dashboard('as_of=2026-09-15')['months']);

        $this->assertCount(12, $months);
        $this->assertSame('2025-10', $months->first()['month']);
        $this->assertSame('2026-09', $months->last()['month']);
        $this->assertSame(
            ['month' => '2025-10', 'income' => 30000, 'expenses' => 0, 'net_income' => 30000, 'cash' => 1030000],
            $months->first(),
        );
        $this->assertSame(
            ['month' => '2025-11', 'income' => 0, 'expenses' => 0, 'net_income' => 0, 'cash' => 1030000],
            $months[1],
        );
        $this->assertSame(
            ['month' => '2026-02', 'income' => 0, 'expenses' => 10000, 'net_income' => -10000, 'cash' => 1020000],
            $months->firstWhere('month', '2026-02'),
        );
        $this->assertSame(
            ['month' => '2026-09', 'income' => 20000, 'expenses' => 5000, 'net_income' => 15000, 'cash' => 1035000],
            $months->last(),
        );
    }

    public function test_months_cross_a_year_end_by_calendar_month(): void
    {
        $months = collect($this->dashboard('as_of=2026-01-31')['months'])->pluck('month')->all();

        $expected = [];
        for ($i = 11; $i >= 0; $i--) {
            $expected[] = CarbonImmutable::parse('2026-01-01')->subMonthsNoOverflow($i)->format('Y-m');
        }

        $this->assertSame($expected, $months);
    }

    public function test_recent_journals_are_the_ten_latest_posted_or_reversed_on_or_before_as_of(): void
    {
        for ($day = 1; $day <= 11; $day++) {
            $this->postSimpleJournal('5030', '1010', 1000 * $day, [
                'date' => sprintf('2026-09-%02d', $day),
                'description' => "Entry {$day}",
            ]);
        }
        $mistake = $this->postSimpleJournal('1010', '3010', 777700, ['date' => '2026-09-12', 'description' => 'Mistake']);
        $this->postJson("/api/accounting/journals/{$mistake->id}/reverse", ['date' => '2026-09-13'])->assertCreated();
        // A draft, and an entry after as_of: never listed.
        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 5, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 5],
        ], ['date' => '2026-09-14']);
        $this->postSimpleJournal('5030', '1010', 123, ['date' => '2026-10-01']);

        $recent = $this->dashboard()['recent_journals'];

        $this->assertCount(10, $recent);
        $this->assertSame(
            ['2026-09-13', '2026-09-12', '2026-09-11', '2026-09-10', '2026-09-09', '2026-09-08', '2026-09-07', '2026-09-06', '2026-09-05', '2026-09-04'],
            array_column($recent, 'date'),
        );
        $this->assertSame('reversal', $recent[0]['source']);
        $this->assertSame(777700, $recent[0]['amount']);
        $this->assertSame([
            'id' => $mistake->id,
            'journal_no' => $mistake->fresh()->journal_no,
            'date' => '2026-09-12',
            'source' => 'manual',
            'description' => 'Mistake',
            'branch_name' => $this->branch->name,
            'amount' => 777700,
        ], $recent[1]);
        $this->assertSame(11000, $recent[2]['amount']);
    }

    public function test_recent_journals_name_no_branch_as_null(): void
    {
        $this->postSimpleJournal('5030', '1010', 1000, ['date' => '2026-09-01', 'branch_id' => null]);

        $this->assertNull($this->dashboard()['recent_journals'][0]['branch_name']);
    }

    public function test_no_period_covering_as_of_reads_null_for_both(): void
    {
        AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse('2026-08-01')) + ['status' => 'open']);

        $dashboard = $this->dashboard();

        $this->assertNull($dashboard['current_period']);
        $this->assertNull($dashboard['open_period']);
    }

    public function test_an_open_period_covering_as_of_is_named(): void
    {
        $period = AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse('2026-09-01')) + ['status' => 'open']);

        $dashboard = $this->dashboard('as_of=2026-09-15');

        $this->assertSame([
            'id' => $period->id,
            'code' => '2026-09',
            'name' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'open',
        ], $dashboard['current_period']);
        $this->assertSame('September 2026', $dashboard['open_period']);
    }

    public function test_a_closed_period_covering_as_of_is_shown_but_not_open(): void
    {
        AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse('2026-09-01')) + ['status' => 'closed']);

        $dashboard = $this->dashboard('as_of=2026-09-15');

        $this->assertSame('closed', $dashboard['current_period']['status']);
        $this->assertSame('September 2026', $dashboard['current_period']['name']);
        $this->assertNull($dashboard['open_period']);
    }

    public function test_every_existing_field_keeps_its_name(): void
    {
        $dashboard = $this->dashboard();

        foreach ([
            'as_of', 'cash_on_hand', 'cash_in_bank', 'e_wallets', 'loans_receivable_net', 'total_assets',
            'total_liabilities', 'total_equity', 'income_mtd', 'expenses_mtd', 'net_income_mtd',
            'unposted_journals', 'is_balanced', 'open_period',
        ] as $field) {
            $this->assertArrayHasKey($field, $dashboard, $field);
        }
    }
}
