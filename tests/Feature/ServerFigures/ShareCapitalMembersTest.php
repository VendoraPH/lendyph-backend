<?php

namespace Tests\Feature\ServerFigures;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /share-capital/members: the Subsidiary Ledger's per-member totals and
 * balances, computed by the server over every entry. The page used to build
 * them in the browser from whatever ledger page had loaded, and ran same-day
 * entries in reverse order.
 */
class ShareCapitalMembersTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $ana;

    private Borrower $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->ana = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->ben = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        foreach ([
            [$this->ana, '2026-07-15', 500.10, 0],
            [$this->ana, '2026-08-15', 250.25, 0],
            [$this->ana, '2026-08-15', 0, 100.05],
            [$this->ben, '2026-08-20', 1000.00, 0],
        ] as [$borrower, $date, $credit, $debit]) {
            ShareCapitalLedger::factory()->create([
                'borrower_id' => $borrower->id, 'date' => $date, 'credit' => $credit, 'debit' => $debit,
            ]);
        }
    }

    public function test_each_member_row_carries_server_totals_and_a_balance_equal_to_the_statement(): void
    {
        $row = $this->getJson('/api/share-capital/members?search='.$this->ana->borrower_code)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame([
            'borrower_id' => $this->ana->id,
            'borrower_code' => $this->ana->borrower_code,
            'full_name' => $this->ana->full_name,
        ], array_intersect_key($row, array_flip(['borrower_id', 'borrower_code', 'full_name'])));
        $this->assertEquals(750.35, $row['total_credits']);
        $this->assertEquals(100.05, $row['total_debits']);
        $this->assertEquals(650.30, $row['balance']);
        $this->assertSame(3, $row['entry_count']);
        $this->assertSame('2026-08-15', $row['last_transaction_date']);

        $statement = $this->getJson("/api/reports/share-capital-statement/{$this->ana->id}")->assertOk()->json('data');
        $this->assertEquals($statement['closing_balance'], $row['balance']);
    }

    public function test_it_is_paginated_and_its_grand_totals_cover_every_member_not_just_the_page(): void
    {
        $response = $this->getJson('/api/share-capital/members?per_page=1')->assertOk()->assertJsonCount(1, 'data');

        $sums = DB::table('share_capital_ledger')->selectRaw('SUM(credit) as credits, SUM(debit) as debits')->first();
        $members = DB::table('share_capital_ledger')->distinct()->count('borrower_id');

        $this->assertSame($members, $response->json('meta.total'));
        $this->assertEquals(round((float) $sums->credits, 2), $response->json('meta.totals.total_credits'));
        $this->assertEquals(round((float) $sums->debits, 2), $response->json('meta.totals.total_debits'));
        $this->assertEquals(round((float) $sums->credits - (float) $sums->debits, 2), $response->json('meta.totals.balance'));
    }

    public function test_the_statement_runs_same_day_entries_in_the_order_they_were_posted(): void
    {
        // Pins the order the members endpoint and the detail view rely on: the
        // browser ran same-day entries in reverse.
        $entries = $this->getJson("/api/reports/share-capital-statement/{$this->ana->id}")->assertOk()->json('data.entries');

        $this->assertSame(['2026-07-15', '2026-08-15', '2026-08-15'], array_column($entries, 'date'));
        $this->assertEquals([500.10, 750.35, 650.30], array_column($entries, 'running_balance'));
        $this->assertSame(array_column($entries, 'id'), collect($entries)->sortBy('id')->pluck('id')->all());
    }

    public function test_search_narrows_the_rows_and_the_matching_totals_but_not_the_book_totals(): void
    {
        $response = $this->getJson('/api/share-capital/members?search='.$this->ben->borrower_code)->assertOk();

        $this->assertSame([$this->ben->id], array_column($response->json('data'), 'borrower_id'));
        $this->assertEquals(['member_count' => 1, 'total_credits' => 1000, 'total_debits' => 0, 'balance' => 1000], $response->json('meta.matching_totals'));
        $this->assertGreaterThanOrEqual(2, $response->json('meta.totals.member_count'));
    }

    public function test_it_sorts_by_balance(): void
    {
        $balances = array_column(
            $this->getJson('/api/share-capital/members?sort=balance&direction=desc&per_page=100')->assertOk()->json('data'),
            'balance',
        );

        $sorted = $balances;
        rsort($sorted);
        $this->assertSame($sorted, $balances);
    }

    public function test_each_ledger_entry_carries_the_member_s_running_balance_in_posting_order(): void
    {
        // Newest first, as the list is ordered; each balance is the member's
        // after that entry, same-day entries in the order they were posted.
        $rows = $this->getJson("/api/share-capital/ledger?borrower_id={$this->ana->id}")->assertOk()->json('data');

        $this->assertEquals([650.30, 750.35, 500.10], array_column($rows, 'running_balance'));
    }

    public function test_the_running_balance_counts_entries_outside_the_date_filter_and_the_period_summary_opens_with_them(): void
    {
        $response = $this->getJson("/api/share-capital/ledger?borrower_id={$this->ana->id}&date_from=2026-08-01&date_to=2026-08-31")
            ->assertOk();

        $this->assertEquals([650.30, 750.35], array_column($response->json('data'), 'running_balance'));
        $this->assertEquals([
            'opening_balance' => 500.10,
            'total_credits' => 250.25,
            'total_debits' => 100.05,
            'ending_balance' => 650.30,
        ], $response->json('meta.summary'));
    }

    public function test_a_later_page_of_one_member_s_ledger_carries_balances_counting_every_earlier_entry(): void
    {
        // Newest first: page 1 holds the 650.30 entry; page 2 starts at the
        // entry before it, whose balance still counts the oldest one.
        $page2 = $this->getJson("/api/share-capital/ledger?borrower_id={$this->ana->id}&per_page=1&page=2")->assertOk()->json('data');
        $page3 = $this->getJson("/api/share-capital/ledger?borrower_id={$this->ana->id}&per_page=1&page=3")->assertOk()->json('data');

        $this->assertEquals([750.35], array_column($page2, 'running_balance'));
        $this->assertEquals([500.10], array_column($page3, 'running_balance'));
    }

    public function test_a_user_without_share_capital_view_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => $this->branch->id]));

        $this->getJson('/api/share-capital/members')->assertForbidden();
    }
}
