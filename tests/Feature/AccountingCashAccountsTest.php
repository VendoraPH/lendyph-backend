<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The Cash & Bank screen's list of money accounts.
 *
 * The figure this endpoint exists to get right is the one at the bottom of the
 * screen: "Total across all money accounts". Two things can make it wrong
 * without looking wrong — a balance computed by a second aggregate that drifts
 * from the trial balance, and a page of rows that is shorter than the list. Both
 * are pinned below.
 */
class AccountingCashAccountsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_only_money_accounts_are_returned_in_code_order(): void
    {
        $body = $this->getJson('/api/accounting/cash-accounts')->assertOk()->json();

        // The four `cash_kind` accounts on the default chart, and nothing else
        // from a 60-odd account chart.
        $this->assertSame(['1010', '1020', '1030', '1040'], array_column($body['data'], 'code'));
        $this->assertSame(['cash', 'gcash', 'maya', 'bank'], array_column($body['data'], 'cash_kind'));
    }

    public function test_balances_are_integer_centavos_signed_in_the_normal_direction(): void
    {
        $this->postSimpleJournal('1010', '3010', 500000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1040', '3010', 2000000, ['date' => '2026-09-01']);

        $accounts = collect($this->getJson('/api/accounting/cash-accounts')->assertOk()->json('data'))
            ->keyBy('code');

        // assertSame, so a "500000" string fails. `sumCentavos` on the frontend
        // had to be hardened against exactly that after a decimal string made
        // this screen's total read ₱0.00 while every card above it was right.
        $this->assertSame(500000, $accounts['1010']['balance']);
        $this->assertSame(2000000, $accounts['1040']['balance']);

        // Never posted to, so zero rather than absent — the screen renders a
        // card per account and needs a figure for each.
        $this->assertSame(0, $accounts['1020']['balance']);
    }

    public function test_the_total_agrees_exactly_with_the_trial_balance(): void
    {
        $this->postSimpleJournal('1010', '3010', 123456, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1020', '3010', 7891, ['date' => '2026-09-02']);
        $this->postSimpleJournal('1040', '1010', 50000, ['date' => '2026-09-03']);

        $cashTotal = collect($this->getJson('/api/accounting/cash-accounts')->assertOk()->json('data'))
            ->sum('balance');

        // The same four accounts as the trial balance reports them. These two
        // figures are read side by side by an accountant, and the ONLY reason
        // they cannot drift is that both descend from
        // TrialBalanceBuilder::signedBalances() — this asserts that they do.
        $trialBalance = $this->getJson('/api/accounting/trial-balance?as_of=2026-09-30')
            ->assertOk()->json('data.rows');

        $moneyCodes = ['1010', '1020', '1030', '1040'];
        $trialBalanceCashTotal = collect($trialBalance)
            ->whereIn('account_code', $moneyCodes)
            ->sum(fn (array $row): int => $row['debit'] - $row['credit']);

        $this->assertSame(123456 + 7891, $cashTotal);
        $this->assertSame($trialBalanceCashTotal, $cashTotal);
    }

    public function test_an_overdrawn_money_account_reports_a_negative_balance(): void
    {
        // Crediting cash past what it holds. A real condition — reported rather
        // than clamped to zero, which would hide it while the books still say
        // the money left.
        $this->postSimpleJournal('5020', '1010', 250000, ['date' => '2026-09-01']);

        $accounts = collect($this->getJson('/api/accounting/cash-accounts')->assertOk()->json('data'))
            ->keyBy('code');

        $this->assertSame(-250000, $accounts['1010']['balance']);
    }

    public function test_the_response_is_a_paginator_the_client_can_drain(): void
    {
        $body = $this->getJson('/api/accounting/cash-accounts?per_page=2')->assertOk()->json();

        // `fetchAllPages` follows `meta.last_page` and stops on it. Without
        // these keys the drain falls back to "stop on the first short page",
        // and a list whose length happens to equal the page size is silently
        // cut off at one page.
        $this->assertSame(2, $body['meta']['per_page']);
        $this->assertSame(1, $body['meta']['current_page']);
        $this->assertSame(2, $body['meta']['last_page']);
        $this->assertSame(4, $body['meta']['total']);
        $this->assertCount(2, $body['data']);

        $page2 = $this->getJson('/api/accounting/cash-accounts?per_page=2&page=2')->assertOk()->json();
        $this->assertSame(['1030', '1040'], array_column($page2['data'], 'code'));
    }

    public function test_per_page_is_clamped_at_100_rather_than_honoured(): void
    {
        // The clamp is silent and is the whole reason the client asks for
        // exactly 100 and follows `last_page`. Asking for more is not an error
        // and must not look like one — it is simply capped.
        $body = $this->getJson('/api/accounting/cash-accounts?per_page=5000')->assertOk()->json();

        $this->assertSame(100, $body['meta']['per_page']);
    }

    public function test_a_deactivated_money_account_still_counts_toward_the_total(): void
    {
        $this->postSimpleJournal('1020', '3010', 300000, ['date' => '2026-09-01']);
        AccountingAccount::query()->where('code', '1020')->update(['is_active' => false]);

        $accounts = collect($this->getJson('/api/accounting/cash-accounts')->assertOk()->json('data'))
            ->keyBy('code');

        // Deliberate: a retired wallet holding ₱3,000 is money the co-op has,
        // and it is on the trial balance either way. Dropping it here would
        // make two screens in one module disagree about the cash position with
        // nothing on either to say why.
        $this->assertArrayHasKey('1020', $accounts);
        $this->assertSame(300000, $accounts['1020']['balance']);
        $this->assertFalse($accounts['1020']['is_active']);
    }

    public function test_group_headings_are_excluded(): void
    {
        // A heading's balance is the sum of its subtree, so one carrying a
        // `cash_kind` would double-count every account beneath it.
        AccountingAccount::query()->where('code', '1000')->update(['cash_kind' => 'cash']);

        $codes = array_column($this->getJson('/api/accounting/cash-accounts')->assertOk()->json('data'), 'code');

        $this->assertNotContains('1000', $codes);
    }

    public function test_it_requires_cash_accounts_view_and_not_the_chart_permission(): void
    {
        $this->actingAs($this->userWithNoRole());
        $this->getJson('/api/accounting/cash-accounts')->assertForbidden();

        // `manager` is read-only across accounting and holds `cash_accounts:view`.
        $this->actingAs($this->userWithRole('manager'));
        $this->getJson('/api/accounting/cash-accounts')->assertOk();
    }
}
