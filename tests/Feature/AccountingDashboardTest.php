<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The accounting dashboard.
 *
 * Every figure here has to come from the trial balance, because the cards sit a
 * click away from a balance sheet built by regrouping exactly those rows. Two
 * aggregates would eventually disagree over a reversal or a group account, and
 * a reader would have no way to tell which of two plausible numbers to trust.
 */
class AccountingDashboardTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function dashboard(string $asOf = '2026-09-30'): array
    {
        return $this->getJson('/api/accounting/dashboard?as_of='.$asOf)->assertOk()->json('data');
    }

    public function test_money_is_split_by_where_it_physically_sits(): void
    {
        $this->postSimpleJournal('1010', '3010', 500000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1040', '3010', 2000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1020', '3010', 150000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1030', '3010', 50000, ['date' => '2026-09-01']);

        $dashboard = $this->dashboard();

        // `cash_kind` decides these, not the code and not the name — so an
        // organisation that renames "GCash" or adds a second bank account still
        // gets the right totals.
        $this->assertSame(500000, $dashboard['cash_on_hand']);
        $this->assertSame(2000000, $dashboard['cash_in_bank']);
        $this->assertSame(200000, $dashboard['e_wallets']);
    }

    public function test_net_receivable_is_gross_across_the_group_less_the_allowance(): void
    {
        // Current and past-due receivable are siblings under 1100. Counting
        // only the mapped account would make the portfolio appear to SHRINK
        // every time a borrower fell behind.
        $this->postSimpleJournal('1110', '1010', 6000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1120', '1110', 1000000, ['date' => '2026-09-05']);

        // The allowance is a contra asset: credit-normal, and it SUBTRACTS.
        $this->postSimpleJournal('5140', '1200', 500000, ['date' => '2026-09-06']);

        $dashboard = $this->dashboard();

        // 5,000,000 current + 1,000,000 past due - 500,000 allowance.
        $this->assertSame(6000000 - 500000, $dashboard['loans_receivable_net']);
    }

    public function test_the_statement_totals_come_out_signed_in_their_own_direction(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('5030', '2010', 300000, ['date' => '2026-09-02']);

        $dashboard = $this->dashboard();

        $this->assertSame(10000000, $dashboard['total_assets']);
        $this->assertSame(300000, $dashboard['total_liabilities']);
        $this->assertSame(10000000, $dashboard['total_equity']);
        $this->assertTrue($dashboard['is_balanced']);
    }

    public function test_a_contra_asset_subtracts_from_total_assets_on_its_own(): void
    {
        $this->postSimpleJournal('1110', '3010', 10000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('5140', '1200', 400000, ['date' => '2026-09-02']);

        // The allowance sits in the credit column as an asset, so it subtracts
        // without any special case. Treating it as a negative asset and ADDING
        // would overstate the portfolio by twice the provision.
        $this->assertSame(9600000, $this->dashboard()['total_assets']);
    }

    public function test_month_to_date_is_the_difference_between_two_positions(): void
    {
        // August, which must NOT count towards September's figures.
        $this->postSimpleJournal('1010', '4010', 700000, ['date' => '2026-08-20']);
        $this->postSimpleJournal('5030', '1010', 100000, ['date' => '2026-08-21']);

        // September.
        $this->postSimpleJournal('1010', '4010', 250000, ['date' => '2026-09-10']);
        $this->postSimpleJournal('5030', '1010', 90000, ['date' => '2026-09-12']);

        $dashboard = $this->dashboard();

        $this->assertSame(250000, $dashboard['income_mtd']);
        $this->assertSame(90000, $dashboard['expenses_mtd']);
        $this->assertSame(160000, $dashboard['net_income_mtd']);
    }

    public function test_drafts_are_counted_as_waiting_and_excluded_from_every_balance(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-09-01']);

        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 99999999, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 99999999],
        ]);

        // A draft dated far in the future is still sitting on someone's desk —
        // `unposted_journals` is a work queue and deliberately ignores `as_of`.
        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 5, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 5],
        ], ['date' => '2027-06-01']);

        $dashboard = $this->dashboard();

        $this->assertSame(2, $dashboard['unposted_journals']);
        $this->assertSame(100000, $dashboard['cash_on_hand']);
    }

    public function test_a_reversed_pair_leaves_the_dashboard_where_it_started(): void
    {
        $this->postSimpleJournal('1010', '3010', 1000000, ['date' => '2026-09-01']);
        $mistake = $this->postSimpleJournal('1010', '3010', 4000000, ['date' => '2026-09-02']);

        $this->postJson("/api/accounting/journals/{$mistake->id}/reverse", ['date' => '2026-09-03'])
            ->assertCreated();

        $dashboard = $this->dashboard();

        // Counting only `posted` would leave the reversal in and its original
        // out, reporting cash of MINUS ₱30,000.
        $this->assertSame(1000000, $dashboard['cash_on_hand']);
        $this->assertTrue($dashboard['is_balanced']);
    }

    public function test_the_open_period_is_null_because_there_are_no_periods_yet(): void
    {
        // Honest rather than convenient. Answering with the current month would
        // invent the one field that tells an accountant whether their entry can
        // still be edited.
        $this->assertNull($this->dashboard()['open_period']);
    }

    public function test_the_dashboard_agrees_with_the_trial_balance(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1110', '1010', 4000000, ['date' => '2026-09-02']);
        $this->postSimpleJournal('1010', '4010', 350000, ['date' => '2026-09-03']);

        $dashboard = $this->dashboard();
        $rows = collect($this->getJson('/api/accounting/trial-balance?as_of=2026-09-30')->json('data.rows'))
            ->keyBy('account_code');

        // The same arithmetic over the same rows, which is the whole reason the
        // dashboard calls TrialBalanceBuilder rather than aggregating itself.
        $this->assertSame($rows['1010']['debit'], $dashboard['cash_on_hand']);
        $this->assertSame(
            $rows['1010']['debit'] + $rows['1110']['debit'],
            $dashboard['total_assets'],
        );
    }

    public function test_the_dashboard_needs_the_accounting_view_permission(): void
    {
        $this->actingAs($this->userWithNoRole());

        $this->getJson('/api/accounting/dashboard?as_of=2026-09-30')->assertForbidden();
    }
}
