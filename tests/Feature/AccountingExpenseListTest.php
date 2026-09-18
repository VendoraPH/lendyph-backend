<?php

namespace Tests\Feature;

use App\Models\AccountingExpense;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The expenses LIST: paging, draining, and the derived status.
 *
 * The Expenses screen does not render a page — it drains every page and sums
 * `amount - amount_paid` over the result into a headline "Outstanding" figure.
 * That makes two ordinary-looking properties load-bearing:
 *
 * - `meta.total` and `meta.last_page` have to be there, because that is what
 *   the drain follows and what the truncation notice reads.
 * - The order has to be TOTAL, because a partial order lets the same row appear
 *   on two pages while another appears on none, and the headline figure is a
 *   sum over whatever came back.
 */
class AccountingExpenseListTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    // ── The paginator envelope the drain reads ──

    public function test_the_list_answers_with_the_raw_paginator_envelope(): void
    {
        AccountingExpense::factory()->count(3)->create();

        $response = $this->getJson('/api/accounting/expenses')->assertOk();

        // `accountingService.expensesList` calls `api.getRaw`, which returns
        // `response.data` unwrapped — so `data`, `links` and `meta` have to sit
        // at the TOP level. Nesting them one deeper leaves `fetchAllPages` with
        // no rows and no `meta.total`, and the screen renders an empty state.
        $response->assertJsonStructure([
            'data' => [['id', 'date', 'payee', 'amount', 'amount_paid', 'status']],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $this->assertSame(3, $response->json('meta.total'));
    }

    /**
     * `per_page` is clamped at 100, silently, exactly as `src/lib/paginate.ts`
     * documents. The drain is built around this number; if the server ever
     * raised it, that file is the one place that changes.
     */
    public function test_per_page_is_clamped_at_one_hundred(): void
    {
        AccountingExpense::factory()->count(105)->create();

        $response = $this->getJson('/api/accounting/expenses?per_page=9999')->assertOk();

        $this->assertCount(100, $response->json('data'));
        $this->assertSame(100, $response->json('meta.per_page'));
        $this->assertSame(105, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_a_per_page_of_zero_is_refused_rather_than_silently_becoming_fifteen(): void
    {
        $this->getJson('/api/accounting/expenses?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    /**
     * Draining every page yields every expense EXACTLY ONCE.
     *
     * All 150 rows share one date on purpose. `order by date desc` alone is a
     * partial order over them, and MySQL is free to return a different
     * arrangement per query — which is how a drained list serves one row twice
     * and drops another, producing an "Outstanding" total that is simply wrong
     * and looks entirely plausible. `id` as the tiebreaker is what makes the
     * order total.
     */
    public function test_draining_every_page_returns_each_expense_once(): void
    {
        AccountingExpense::factory()->count(150)->create(['date' => '2026-09-18']);

        $seen = [];
        $page = 1;

        do {
            $response = $this->getJson("/api/accounting/expenses?page={$page}&per_page=100")->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $lastPage = (int) $response->json('meta.last_page');
            $page++;
        } while ($page <= $lastPage);

        $this->assertCount(150, $seen);
        $this->assertCount(150, array_unique($seen), 'A row was served on two pages.');
        $this->assertEqualsCanonicalizing(
            AccountingExpense::query()->pluck('id')->all(),
            $seen,
        );
    }

    // ── The derived status ──

    public function test_an_unpaid_expense_past_its_due_date_reads_as_overdue(): void
    {
        $overdue = AccountingExpense::factory()->overdue()->create();

        $row = $this->getJson('/api/accounting/expenses')->assertOk()->json('data.0');

        $this->assertSame($overdue->id, $row['id']);
        $this->assertSame('overdue', $row['status']);
    }

    /**
     * Overdue beats partially_paid, and that precedence is a decision.
     *
     * `ExpenseStatus` is single-valued and the screen filters on exactly this
     * string, so one of the two facts has to win. Reporting it as
     * `partially_paid` would drop a late bill out of the Overdue filter — the
     * one view whose entire purpose is to be complete.
     */
    public function test_a_part_paid_expense_past_its_due_date_reads_as_overdue(): void
    {
        AccountingExpense::factory()->partiallyPaid()->create(['due_date' => '2020-01-01']);

        $row = $this->getJson('/api/accounting/expenses')->assertOk()->json('data.0');
        $this->assertSame('overdue', $row['status']);
    }

    public function test_a_settled_expense_is_never_overdue_however_old_its_due_date(): void
    {
        AccountingExpense::factory()->paid()->create(['due_date' => '2020-01-01']);

        $row = $this->getJson('/api/accounting/expenses')->assertOk()->json('data.0');
        $this->assertSame('paid', $row['status']);
    }

    public function test_a_due_date_in_the_future_is_not_yet_overdue(): void
    {
        AccountingExpense::factory()->create(['due_date' => '2099-12-31']);

        $row = $this->getJson('/api/accounting/expenses')->assertOk()->json('data.0');
        $this->assertSame('unpaid', $row['status']);
    }

    /**
     * The SQL filter and the emitted badge have to agree, row for row.
     *
     * They are two separate implementations of the same ladder —
     * `AccountingExpense::status()` and `scopeWithStatus()` — and a filter that
     * disagreed with the badge would list a row under "Unpaid" whose badge read
     * "Overdue", with no way to tell which was lying.
     */
    public function test_the_status_filter_returns_exactly_the_rows_whose_status_it_names(): void
    {
        AccountingExpense::factory()->create();                                        // unpaid, no due date
        AccountingExpense::factory()->create(['due_date' => '2099-12-31']);            // unpaid, not yet due
        AccountingExpense::factory()->overdue()->create();                             // overdue
        AccountingExpense::factory()->partiallyPaid()->create();                       // partially paid
        AccountingExpense::factory()->partiallyPaid()->create(['due_date' => '2020-01-01']); // overdue
        AccountingExpense::factory()->paid()->create();                                // paid

        $all = $this->getJson('/api/accounting/expenses?per_page=100')->assertOk()->json('data');

        foreach (['unpaid', 'partially_paid', 'paid', 'overdue'] as $status) {
            $filtered = $this->getJson("/api/accounting/expenses?status={$status}&per_page=100")
                ->assertOk()->json('data');

            $expected = array_column(
                array_values(array_filter($all, fn (array $row): bool => $row['status'] === $status)),
                'id',
            );

            $this->assertEqualsCanonicalizing(
                $expected,
                array_column($filtered, 'id'),
                "The `{$status}` filter disagrees with the status the list emits.",
            );

            foreach ($filtered as $row) {
                $this->assertSame($status, $row['status']);
            }
        }
    }

    public function test_an_unknown_status_is_refused_rather_than_returning_everything(): void
    {
        AccountingExpense::factory()->count(3)->create();

        $this->getJson('/api/accounting/expenses?status=settled')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ── The joined field the table renders ──

    public function test_each_row_carries_the_name_of_the_account_the_cost_landed_in(): void
    {
        AccountingExpense::factory()->create();

        $row = $this->getJson('/api/accounting/expenses')->assertOk()->json('data.0');

        // The table renders `expense_account_name ?? "—"`. Absent means every
        // row shows a dash.
        $this->assertSame('Electricity', $row['expense_account_name']);
    }
}
