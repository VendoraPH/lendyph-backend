<?php

namespace Tests\Feature\Accounting\Dashboard;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The general ledger's `meta` (opening and closing balance, totals over the
 * whole range) and its `source` filter, which narrows the rows without
 * changing any row's true running balance.
 */
class GeneralLedgerMetaTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        // Cash: 1,000.00 opening in August, then five September movements.
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-08-15']);
        $this->postSimpleJournal('1010', '4010', 20000, ['date' => '2026-09-01', 'source' => 'loan_collection']);
        $this->postSimpleJournal('5030', '1010', 5000, ['date' => '2026-09-02', 'source' => 'expense']);
        $this->postSimpleJournal('1010', '4010', 30000, ['date' => '2026-09-03', 'source' => 'loan_collection']);
        $this->postSimpleJournal('5030', '1010', 7000, ['date' => '2026-09-04', 'source' => 'expense']);
        $this->postSimpleJournal('1010', '3010', 1000, ['date' => '2026-09-05']);
        // After `to`.
        $this->postSimpleJournal('1010', '3010', 999900, ['date' => '2026-10-01']);
    }

    private function ledger(array $params): TestResponse
    {
        return $this->getJson('/api/accounting/general-ledger?'.http_build_query(array_merge([
            'account_id' => $this->account('1010'),
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ], $params)));
    }

    public function test_meta_carries_the_opening_and_closing_balance_and_the_totals_over_the_whole_range(): void
    {
        $response = $this->ledger(['per_page' => 2])->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(100000, $response->json('meta.opening_balance'));
        $this->assertSame(51000, $response->json('meta.total_debit'));
        $this->assertSame(12000, $response->json('meta.total_credit'));
        $this->assertSame(139000, $response->json('meta.closing_balance'));
        // The paginator envelope stays.
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertNotNull($response->json('links'));
    }

    public function test_the_closing_balance_equals_the_last_rows_running_balance(): void
    {
        $response = $this->ledger(['per_page' => 2, 'page' => 3])->assertOk();

        $this->assertSame(
            $response->json('meta.closing_balance'),
            $response->json('data.0.running_balance'),
        );
    }

    public function test_without_from_the_ledger_opens_at_zero(): void
    {
        $response = $this->ledger(['from' => null])->assertOk();

        $this->assertSame(0, $response->json('meta.opening_balance'));
        $this->assertSame(151000, $response->json('meta.total_debit'));
        $this->assertSame(12000, $response->json('meta.total_credit'));
        $this->assertSame(139000, $response->json('meta.closing_balance'));
    }

    public function test_an_empty_range_closes_where_it_opened(): void
    {
        $response = $this->ledger(['from' => '2026-09-10', 'to' => '2026-09-20'])->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(139000, $response->json('meta.opening_balance'));
        $this->assertSame(139000, $response->json('meta.closing_balance'));
        $this->assertSame(0, $response->json('meta.total_debit'));
        $this->assertSame(0, $response->json('meta.total_credit'));
    }

    public function test_a_source_filter_keeps_each_rows_true_running_balance(): void
    {
        $response = $this->ledger(['source' => 'expense'])->assertOk();

        $rows = $response->json('data');
        $this->assertSame(['expense', 'expense'], array_column($rows, 'source'));
        // 1,000 + 200 - 50 = 1,150; then + 300 - 70 = 1,380.
        $this->assertSame([115000, 138000], array_column($rows, 'running_balance'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(0, $response->json('meta.total_debit'));
        $this->assertSame(12000, $response->json('meta.total_credit'));
        // Opening and closing are the account's, every source.
        $this->assertSame(100000, $response->json('meta.opening_balance'));
        $this->assertSame(139000, $response->json('meta.closing_balance'));
    }

    public function test_a_source_filter_pages_over_the_matching_rows(): void
    {
        $response = $this->ledger(['source' => 'loan_collection', 'per_page' => 1, 'page' => 2])->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame([145000], array_column($response->json('data'), 'running_balance'));
        $this->assertSame(50000, $response->json('meta.total_debit'));
    }

    public function test_an_unknown_source_answers_422(): void
    {
        $this->ledger(['source' => 'not_a_source'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source');
    }
}
