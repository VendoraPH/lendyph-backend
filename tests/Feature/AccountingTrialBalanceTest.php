<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The trial balance: the proof the books hold together.
 *
 * The balance sheet and the income statement are built client-side by
 * regrouping these rows, so this is not one report among several — it is the
 * single source every balance-sheet figure descends from. A row that is wrong
 * here is wrong on three screens at once, and none of them will disagree with
 * each other about it.
 */
class AccountingTrialBalanceTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsByCode(array $params = []): array
    {
        $response = $this->getJson('/api/accounting/trial-balance?'.http_build_query($params + ['as_of' => '2026-12-31']))
            ->assertOk();

        $rows = [];

        foreach ($response->json('data.rows') as $row) {
            $rows[$row['account_code']] = $row;
        }

        return $rows;
    }

    public function test_a_set_of_posted_entries_balances(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000000);
        $this->postSimpleJournal('1110', '1010', 50000000);
        $this->postSimpleJournal('1010', '4010', 8000000);
        $this->postSimpleJournal('5020', '1010', 3000000);

        $response = $this->getJson('/api/accounting/trial-balance?as_of=2026-12-31')->assertOk();

        $this->assertSame(0, $response->json('data.difference'));
        $this->assertTrue($response->json('data.is_balanced'));
        $this->assertSame(
            $response->json('data.total_debit'),
            $response->json('data.total_credit'),
        );
        $this->assertSame('2026-12-31', $response->json('data.as_of'));
    }

    public function test_each_account_is_netted_before_it_is_placed(): void
    {
        // Cash debited ₱500 and credited ₱200 is ONE ₱300 debit, not both
        // figures side by side — otherwise every account that moved in both
        // directions is double-counted in the totals.
        $this->postSimpleJournal('1010', '3010', 50000);
        $this->postSimpleJournal('5030', '1010', 20000);

        $cash = $this->rowsByCode()['1010'];

        $this->assertSame(30000, $cash['debit']);
        $this->assertSame(0, $cash['credit']);
    }

    public function test_an_overdrawn_cash_account_lands_in_the_credit_column(): void
    {
        // A cash account that has swung against its normal side is a real
        // condition someone needs to see. Reporting it as a NEGATIVE debit
        // would make the columns balance on paper while hiding it.
        $this->postSimpleJournal('1010', '3010', 10000);
        $this->postSimpleJournal('5030', '1010', 40000);

        $rows = $this->rowsByCode();

        $this->assertSame(0, $rows['1010']['debit']);
        $this->assertSame(30000, $rows['1010']['credit']);

        // And the report still balances, because the swing was not hidden.
        $this->assertTrue($this->getJson('/api/accounting/trial-balance?as_of=2026-12-31')->json('data.is_balanced'));
    }

    public function test_a_reversed_original_is_still_counted_so_the_pair_nets_to_zero(): void
    {
        // THE failure this module is easiest to get wrong. A reversed entry is
        // a posted historical fact; its reversal is a second entry that nets it
        // out. Filter on `status = 'posted'` alone and every REVERSAL is
        // included while the entry it reverses is not — each affected account
        // then shows the exact negative of a transaction it no longer has, and
        // the report claims an imbalance that does not exist.
        $original = $this->postSimpleJournal('5030', '1010', 350000);
        $this->postSimpleJournal('1010', '3010', 1000000);

        $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => '2026-09-20'])
            ->assertCreated();

        $response = $this->getJson('/api/accounting/trial-balance?as_of=2026-12-31')->assertOk();
        $rows = $this->rowsByCode();

        $this->assertTrue($response->json('data.is_balanced'));
        $this->assertSame(0, $response->json('data.difference'));

        // Electricity moved only inside the reversed pair, so it nets to zero
        // and drops off the report — rather than appearing as a ₱3,500 CREDIT,
        // which is what counting the reversal alone would produce.
        $this->assertArrayNotHasKey('5030', $rows);
        $this->assertSame(1000000, $rows['1010']['debit']);
    }

    public function test_drafts_are_excluded(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000);

        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 99999999, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 99999999],
        ]);

        // A draft is not in the books. Counting one would make the trial
        // balance report money nobody has committed.
        $this->assertSame(100000, $this->rowsByCode()['1010']['debit']);
    }

    public function test_accounts_that_never_moved_are_left_off(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000);

        $codes = array_column(
            $this->getJson('/api/accounting/trial-balance?as_of=2026-12-31')->json('data.rows'),
            'account_code',
        );

        // A report listing every zero account buries the rows that matter.
        $this->assertSame(['1010', '3010'], $codes);
    }

    public function test_an_account_whose_movements_cancel_exactly_is_left_off(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000);
        $this->postSimpleJournal('5030', '1010', 50000);
        $this->postSimpleJournal('1010', '5030', 50000);

        $this->assertArrayNotHasKey('5030', $this->rowsByCode());
    }

    public function test_group_headings_never_appear(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000);

        $rows = $this->rowsByCode();

        // Nothing can post to a heading, and its balance is the sum of its
        // subtree — including one would count the same money twice.
        foreach (['1000', '1100', '2000', '3000', '4000', '5000'] as $groupCode) {
            $this->assertArrayNotHasKey($groupCode, $rows);
        }
    }

    public function test_rows_come_out_in_account_code_order(): void
    {
        $this->postSimpleJournal('5020', '1010', 1000);
        $this->postSimpleJournal('1010', '4010', 3000);

        // Codes are fixed-width numeric strings, so lexical order IS statement
        // order: 1010 -> 4010 -> 5020.
        $codes = array_column(
            $this->getJson('/api/accounting/trial-balance?as_of=2026-12-31')->json('data.rows'),
            'account_code',
        );

        $this->assertSame(['1010', '4010', '5020'], $codes);
    }

    public function test_the_as_of_date_excludes_later_entries(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-09-10']);
        $this->postSimpleJournal('1010', '3010', 900000, ['date' => '2026-10-10']);

        $this->assertSame(100000, $this->rowsByCode(['as_of' => '2026-09-30'])['1010']['debit']);
        $this->assertSame(1000000, $this->rowsByCode(['as_of' => '2026-10-31'])['1010']['debit']);
    }

    public function test_an_empty_book_is_balanced_at_zero_rather_than_broken(): void
    {
        $response = $this->getJson('/api/accounting/trial-balance?as_of=2026-12-31')->assertOk();

        $this->assertSame([], $response->json('data.rows'));
        $this->assertSame(0, $response->json('data.total_debit'));
        $this->assertTrue($response->json('data.is_balanced'));
    }

    public function test_the_trial_balance_needs_the_accounting_view_permission(): void
    {
        $this->actingAs($this->userWithNoRole());

        $this->getJson('/api/accounting/trial-balance?as_of=2026-12-31')->assertForbidden();
    }
}
