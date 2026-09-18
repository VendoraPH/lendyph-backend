<?php

namespace Tests\Feature;

use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Reconciling a money account against its statement.
 *
 * The notice on that screen is the specification these tests defend: a
 * difference between the books and the statement is a REAL discrepancy, not
 * something to be adjusted away. So the assertions are about what the engine
 * REFUSES to do — claim one ledger line for two statement lines, show a green
 * row whose counterpart is outside the period, or keep reporting a difference
 * that the missing entry has since closed — at least as much as about what it
 * pairs.
 */
class AccountingReconciliationTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->account('1040'),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            // ₱50,000.50 in centavos.
            'statement_balance' => 5000050,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function create(array $overrides = []): array
    {
        return $this->postJson('/api/accounting/reconciliations', $this->payload($overrides))
            ->assertCreated()
            ->json('data');
    }

    /** Deposit money into the bank so the ledger has something to match. */
    private function deposit(int $centavos, string $date, ?string $reference = null): void
    {
        $this->postSimpleJournal('1040', '3010', $centavos, [
            'date' => $date,
            'reference' => $reference,
            'description' => 'Capital deposit',
        ]);
    }

    // ── The shape the screen consumes ──

    public function test_the_list_answers_with_the_raw_paginator_envelope_and_the_fields_the_card_reads(): void
    {
        $this->deposit(5000050, '2026-09-10');
        $this->create();

        $response = $this->getJson('/api/accounting/reconciliations')->assertOk();

        // `reconciliationsList` calls `api.getRaw`, so `data`/`links`/`meta`
        // sit at the TOP level and `fetchAllPages` reads `meta.last_page` and
        // `meta.total` from there.
        $response->assertJsonStructure([
            'data' => [[
                'id', 'account_id', 'period', 'book_balance', 'statement_balance',
                'difference', 'lines' => [['journal_id', 'date', 'description', 'amount', 'match']],
            ]],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $row = $response->json('data.0');

        // The card is keyed on `${account_id}-${period}`, so both have to be
        // present and the pair has to be unique — see the test below.
        $this->assertSame($this->account('1040'), $row['account_id']);
        $this->assertSame('September 2026', $row['period']);

        // CENTAVOS, as integers. `formatCentavos` on the card would render a
        // decimal string as ₱0.00 while every line beneath it looked fine.
        $this->assertIsInt($row['book_balance']);
        $this->assertIsInt($row['statement_balance']);
        $this->assertIsInt($row['difference']);
    }

    public function test_the_period_label_is_derived_from_a_whole_month(): void
    {
        $reconciliation = $this->create();

        // Derived rather than typed, so "September 2026", "Sept 2026" and
        // "09/2026" cannot become three cards for one month — which, given the
        // unique index on (account, period), is the difference between "you
        // already reconciled this" and a screen quietly showing it twice.
        $this->assertSame('September 2026', $reconciliation['period']);
    }

    public function test_a_range_that_is_not_a_whole_month_keeps_its_dates(): void
    {
        $reconciliation = $this->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-25']);

        $this->assertSame('2026-09-01 – 2026-09-25', $reconciliation['period']);
    }

    public function test_one_account_cannot_have_two_reconciliations_for_the_same_period(): void
    {
        $this->create();

        $this->postJson('/api/accounting/reconciliations', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_an_account_that_is_not_money_has_no_statement_to_be_proved_against(): void
    {
        // 1110 Loans Receivable. Reconciling it against a bank statement is a
        // question with no answer, and the difference would be the whole balance.
        $this->postJson('/api/accounting/reconciliations', $this->payload([
            'account_id' => $this->account('1110'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    // ── The book balance is live ──

    /**
     * The difference CLOSES when the missing entry is posted.
     *
     * This is the whole reason `book_balance` is computed rather than stored. A
     * snapshot would keep reporting yesterday's gap after today's fix, so the
     * card would stay amber and the only way to clear it would be to redo the
     * reconciliation — which teaches people to redo reconciliations until the
     * number looks right, the exact habit the notice on that screen exists to
     * discourage.
     */
    public function test_posting_the_missing_entry_closes_the_difference(): void
    {
        $this->deposit(4000050, '2026-09-10');

        $reconciliation = $this->create(); // statement says 5000050

        $this->assertSame(4000050, $reconciliation['book_balance']);
        $this->assertSame(-1000000, $reconciliation['difference']);
        $this->assertFalse($reconciliation['is_reconciled']);

        // The line that was missing from the books all along.
        $this->deposit(1000000, '2026-09-20');

        $after = $this->getJson("/api/accounting/reconciliations/{$reconciliation['id']}")
            ->assertOk()->json('data');

        $this->assertSame(5000050, $after['book_balance']);
        $this->assertSame(0, $after['difference']);
        $this->assertTrue($after['is_reconciled']);
    }

    public function test_the_book_balance_is_cumulative_rather_than_the_periods_own_movement(): void
    {
        // A balance is what the account HOLDS, not what moved through it in
        // September. An opening balance from August has to be in it.
        $this->deposit(3000000, '2026-08-15');
        $this->deposit(2000050, '2026-09-10');

        $reconciliation = $this->create();

        $this->assertSame(5000050, $reconciliation['book_balance']);
    }

    // ── The matching engine ──

    public function test_the_same_amount_a_few_days_apart_is_offered_as_a_possible_match(): void
    {
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->create([
            'statement_balance' => 1500000,
            // Value dating: a Friday deposit that the bank posts on Monday.
            'lines' => [[
                'date' => '2026-09-13',
                'description' => 'DEP 001',
                'amount' => 1500000,
            ]],
        ]);

        // ONE row, not two. A matched or suggested pair stands for one event;
        // emitting both halves would double the worksheet and make the count of
        // things left to do wrong.
        $this->assertCount(1, $reconciliation['lines']);
        $this->assertSame('possible', $reconciliation['lines'][0]['match']);
        $this->assertSame(1500000, $reconciliation['lines'][0]['amount']);
    }

    public function test_an_amount_a_centavo_out_is_not_a_match(): void
    {
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->create([
            'lines' => [['date' => '2026-09-10', 'description' => 'DEP 001', 'amount' => 1500001]],
        ]);

        // Two amounts a centavo apart are not the same transaction — they are a
        // transaction and a bug, and pairing them would bury the bug.
        $matches = array_column($reconciliation['lines'], 'match');
        $this->assertSame(['unmatched', 'unmatched'], $matches);
    }

    public function test_a_date_beyond_the_window_is_not_a_match(): void
    {
        $this->deposit(1500000, '2026-09-01');

        $reconciliation = $this->create([
            // 20 days later — far outside the 5-day value-dating tolerance. A
            // recurring payment of the same amount must not match the wrong week.
            'lines' => [['date' => '2026-09-21', 'description' => 'DEP 001', 'amount' => 1500000]],
        ]);

        $this->assertSame(['unmatched', 'unmatched'], array_column($reconciliation['lines'], 'match'));
    }

    public function test_a_reference_the_bank_echoed_back_beats_the_date_window(): void
    {
        // Same amount on both, but the reference names which entry it is — so
        // the far-away one wins over the near one.
        $this->deposit(1500000, '2026-09-02', 'DEP-9981');
        $this->deposit(1500000, '2026-09-20');

        $reconciliation = $this->create([
            'lines' => [[
                'date' => '2026-09-22',
                'description' => 'Deposit slip 9981',
                'amount' => 1500000,
                'external_reference' => 'DEP-9981',
            ]],
        ]);

        $paired = collect($reconciliation['lines'])->firstWhere('match', 'possible');

        $this->assertNotNull($paired);
        $this->assertSame('2026-09-02', $paired['date'], 'The reference pass should have won over the nearer date.');
    }

    public function test_one_ledger_line_is_never_offered_to_two_statement_lines(): void
    {
        // One deposit in the books, the same amount twice on the statement:
        // one of the two really is missing from the ledger, and that is the
        // finding. Offering the single entry to both would report the
        // reconciliation as clean and hide it.
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->create([
            'lines' => [
                ['date' => '2026-09-10', 'description' => 'DEP A', 'amount' => 1500000],
                ['date' => '2026-09-11', 'description' => 'DEP B', 'amount' => 1500000],
            ],
        ]);

        $matches = array_count_values(array_column($reconciliation['lines'], 'match'));

        $this->assertSame(1, $matches['possible'] ?? 0);
        $this->assertSame(1, $matches['unmatched'] ?? 0);
    }

    // ── Confirming ──

    public function test_accepting_the_suggestions_turns_them_into_confirmed_matches(): void
    {
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->create([
            'lines' => [['date' => '2026-09-11', 'description' => 'DEP 001', 'amount' => 1500000]],
        ]);

        $confirmed = $this->postJson(
            "/api/accounting/reconciliations/{$reconciliation['id']}/match",
            ['accept_suggestions' => true],
        )->assertOk()->json('data');

        $this->assertSame('matched', $confirmed['lines'][0]['match']);
        $this->assertSame(1, $confirmed['matched_count']);

        // Persisted, unlike the suggestion it came from.
        $this->assertNotNull(AccountingReconciliationLine::sole()->matched_journal_line_id);
    }

    public function test_a_confirmation_can_be_undone_with_a_null_journal_line(): void
    {
        $this->deposit(1500000, '2026-09-10');
        $reconciliation = $this->create([
            'lines' => [['date' => '2026-09-11', 'description' => 'DEP 001', 'amount' => 1500000]],
        ]);

        $this->postJson("/api/accounting/reconciliations/{$reconciliation['id']}/match", [
            'accept_suggestions' => true,
        ])->assertOk();

        $lineId = AccountingReconciliationLine::sole()->id;

        $undone = $this->postJson("/api/accounting/reconciliations/{$reconciliation['id']}/match", [
            'matches' => [['line_id' => $lineId, 'journal_line_id' => null]],
        ])->assertOk()->json('data');

        // Back to the engine's opinion rather than a decision.
        $this->assertSame('possible', $undone['lines'][0]['match']);
        $this->assertNull(AccountingReconciliationLine::sole()->matched_journal_line_id);
    }

    public function test_a_ledger_line_already_claimed_cannot_be_matched_a_second_time(): void
    {
        $this->deposit(1500000, '2026-09-10');

        $reconciliation = $this->create([
            'lines' => [
                ['date' => '2026-09-10', 'description' => 'DEP A', 'amount' => 1500000],
                ['date' => '2026-09-11', 'description' => 'DEP B', 'amount' => 1500000],
            ],
        ]);

        $this->postJson("/api/accounting/reconciliations/{$reconciliation['id']}/match", [
            'accept_suggestions' => true,
        ])->assertOk();

        $journalLineId = AccountingReconciliationLine::query()
            ->whereNotNull('matched_journal_line_id')->value('matched_journal_line_id');
        $otherLineId = AccountingReconciliationLine::query()
            ->whereNull('matched_journal_line_id')->value('id');

        $this->postJson("/api/accounting/reconciliations/{$reconciliation['id']}/match", [
            'matches' => [['line_id' => $otherLineId, 'journal_line_id' => $journalLineId]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('matches');
    }

    public function test_a_ledger_line_on_another_account_cannot_be_matched(): void
    {
        $this->deposit(1500000, '2026-09-10');
        $cashEntry = $this->postSimpleJournal('1010', '3010', 1500000, ['date' => '2026-09-10']);

        $reconciliation = $this->create([
            'lines' => [['date' => '2026-09-10', 'description' => 'DEP A', 'amount' => 1500000]],
        ]);

        $cashLineId = $cashEntry->lines()
            ->where('accounting_account_id', $this->account('1010'))->value('id');

        $this->postJson("/api/accounting/reconciliations/{$reconciliation['id']}/match", [
            'matches' => [[
                'line_id' => AccountingReconciliationLine::sole()->id,
                'journal_line_id' => $cashLineId,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('matches');
    }

    public function test_sending_both_modes_at_once_is_refused_rather_than_resolved(): void
    {
        $reconciliation = $this->create();

        $this->postJson("/api/accounting/reconciliations/{$reconciliation['id']}/match", [
            'accept_suggestions' => true,
            'matches' => [['line_id' => 1, 'journal_line_id' => null]],
        ])->assertStatus(422);
    }

    // ── Reconciling writes no journal, ever ──

    public function test_nothing_in_this_module_posts_a_journal(): void
    {
        $this->deposit(1500000, '2026-09-10');
        $before = DB::table('accounting_journals')->count();

        $reconciliation = $this->create([
            'lines' => [['date' => '2026-09-11', 'description' => 'DEP 001', 'amount' => 1500000]],
        ]);

        $this->postJson("/api/accounting/reconciliations/{$reconciliation['id']}/match", [
            'accept_suggestions' => true,
        ])->assertOk();

        // Reconciling PROVES the books; it does not adjust them. A module that
        // could write a correcting entry would let a difference be made to go
        // away instead of explained.
        $this->assertSame($before, DB::table('accounting_journals')->count());
    }

    // ── Performance ──

    /**
     * A page of reconciliations costs a bounded number of queries.
     *
     * The screen renders EVERY line of EVERY reconciliation it drains, so the
     * obvious implementation — run the matching engine per row — is an N+1 that
     * grows with the page size and only shows up once a co-op has a year of
     * history behind it.
     */
    public function test_listing_many_reconciliations_does_not_scale_its_queries_with_the_page(): void
    {
        $this->deposit(1000000, '2026-01-15');

        foreach (range(1, 12) as $month) {
            $start = sprintf('2026-%02d-01', $month);
            AccountingReconciliation::factory()->create([
                'accounting_account_id' => $this->account('1040'),
                'period' => sprintf('2026-%02d', $month),
                'start_date' => $start,
                'end_date' => date('Y-m-t', strtotime($start)),
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/accounting/reconciliations?per_page=100')->assertOk()
            ->assertJsonCount(12, 'data');

        // Pagination, the page itself, two eager loads, one ledger read, and
        // one balance query per distinct period end. Comfortably under what
        // three queries per row would cost, and the ceiling is what matters —
        // the point is that it does not grow with the number of ROWS.
        $this->assertLessThan(
            24,
            $queries,
            "A page of 12 reconciliations took {$queries} queries — the matching engine is running per row.",
        );
    }
}
