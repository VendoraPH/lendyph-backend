<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The general ledger: one account's history, each row carrying the balance
 * after it.
 *
 * The running balance is the whole reason this endpoint is harder than a list.
 * A ledger that starts a page from zero puts a formatted, plausible, WRONG
 * figure on screen — and the last row of the last page is the one a reader
 * treats as the account's current position.
 */
class AccountingGeneralLedgerTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function ledger(array $params): TestResponse
    {
        return $this->getJson('/api/accounting/general-ledger?'.http_build_query($params));
    }

    public function test_running_balances_accumulate_in_the_accounts_normal_direction(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('5030', '1010', 30000, ['date' => '2026-09-02']);
        $this->postSimpleJournal('1010', '4010', 50000, ['date' => '2026-09-03']);

        $rows = $this->ledger(['account_id' => $this->account('1010')])->assertOk()->json('data');

        // Cash is debit-normal, so it grows with debits and shrinks with
        // credits: 1000 -> 700 -> 1200.
        $this->assertSame([100000, 70000, 120000], array_column($rows, 'running_balance'));
    }

    public function test_running_balances_on_a_credit_normal_account_grow_with_credits(): void
    {
        $this->postSimpleJournal('5030', '2010', 100000, ['date' => '2026-09-01']);
        $this->postSimpleJournal('2010', '1010', 40000, ['date' => '2026-09-02']);

        $rows = $this->ledger(['account_id' => $this->account('2010')])->assertOk()->json('data');

        // A payable's ledger reads as the amount OWED. A raw debit-minus-credit
        // would be negative for every credit-normal account on the chart.
        $this->assertSame([100000, 60000], array_column($rows, 'running_balance'));
    }

    public function test_a_filtered_range_starts_from_the_opening_balance_not_from_zero(): void
    {
        $this->postSimpleJournal('1010', '3010', 500000, ['date' => '2026-08-15']);
        $this->postSimpleJournal('1010', '4010', 20000, ['date' => '2026-09-05']);

        $rows = $this->ledger([
            'account_id' => $this->account('1010'),
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ])->assertOk()->json('data');

        // One row in range, but the account did not start September at zero.
        // Reporting ₱200 here would present a month's movement as the account's
        // position.
        $this->assertCount(1, $rows);
        $this->assertSame(520000, $rows[0]['running_balance']);
    }

    public function test_the_opening_balance_boundary_includes_the_day_before_from(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-08-31']);
        $this->postSimpleJournal('1010', '3010', 1, ['date' => '2026-09-01']);

        $rows = $this->ledger([
            'account_id' => $this->account('1010'),
            'from' => '2026-09-01',
        ])->assertOk()->json('data');

        // The 31 August entry is opening balance; the 1 September entry is row
        // one. An off-by-one either double-counts the first day or drops it,
        // and both look like a plausible balance.
        $this->assertCount(1, $rows);
        $this->assertSame(100001, $rows[0]['running_balance']);
    }

    public function test_page_two_continues_from_page_one_rather_than_restarting(): void
    {
        // 105 postings so a 100-row page has something after it.
        for ($i = 1; $i <= 105; $i++) {
            $this->postSimpleJournal('1010', '3010', 1000, [
                'date' => '2026-09-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'description' => "Contribution {$i}",
            ]);
        }

        $pageOne = $this->ledger([
            'account_id' => $this->account('1010'),
            'per_page' => 100,
            'page' => 1,
        ])->assertOk();

        $pageTwo = $this->ledger([
            'account_id' => $this->account('1010'),
            'per_page' => 100,
            'page' => 2,
        ])->assertOk();

        $one = $pageOne->json('data');
        $two = $pageTwo->json('data');

        $this->assertCount(100, $one);
        $this->assertCount(5, $two);
        $this->assertSame(105, $pageOne->json('meta.total'));

        // THE assertion. Paginate first and accumulate after, and this would be
        // 1000 — page two restarting from the opening balance, with every row
        // on it out by the sum of page one.
        $this->assertSame(100000, $one[99]['running_balance']);
        $this->assertSame(101000, $two[0]['running_balance']);
        $this->assertSame(105000, $two[4]['running_balance']);
    }

    public function test_the_ledger_counts_reversed_originals(): void
    {
        $original = $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-09-01']);
        $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => '2026-09-02'])
            ->assertCreated();

        $rows = $this->ledger(['account_id' => $this->account('1010')])->assertOk()->json('data');

        // Both halves are on the record and the balance returns to zero — as
        // opposed to showing only the reversal and a balance of MINUS ₱1,000.
        $this->assertCount(2, $rows);
        $this->assertSame([100000, 0], array_column($rows, 'running_balance'));
    }

    public function test_drafts_never_appear_in_the_ledger(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-09-01']);

        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 99999999, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 99999999],
        ]);

        $rows = $this->ledger(['account_id' => $this->account('1010')])->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame(100000, $rows[0]['running_balance']);
    }

    public function test_the_ledger_carries_the_entry_the_movement_came_from(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, [
            'date' => '2026-09-01',
            'source' => 'cash_in',
            'reference' => 'OR-0001',
            'description' => 'Opening contribution',
        ]);

        $row = $this->ledger(['account_id' => $this->account('1010')])->assertOk()->json('data.0');

        $this->assertSame('JE-000001', $row['journal_no']);
        $this->assertSame('cash_in', $row['source']);
        $this->assertSame('OR-0001', $row['reference']);
        $this->assertSame('2026-09-01', $row['date']);
        $this->assertSame(100000, $row['debit']);
        $this->assertSame(0, $row['credit']);
    }

    public function test_an_account_id_is_required(): void
    {
        // A running balance across several accounts is meaningless: it
        // accumulates in ONE account's normal direction.
        $this->getJson('/api/accounting/general-ledger')
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    public function test_a_group_heading_has_no_ledger_of_its_own(): void
    {
        // Nothing can post to a heading, so its ledger would always be empty —
        // and an empty page is indistinguishable from "no activity".
        $this->ledger(['account_id' => $this->account('1100')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    public function test_per_page_is_clamped_at_one_hundred(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->postSimpleJournal('1010', '3010', 100, ['date' => '2026-09-01']);
        }

        $response = $this->ledger([
            'account_id' => $this->account('1010'),
            'per_page' => 5000,
        ])->assertOk();

        $this->assertSame(100, $response->json('meta.per_page'));
    }
}
