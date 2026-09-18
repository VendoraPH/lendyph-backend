<?php

namespace Tests\Feature;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Services\Accounting\JournalPoster;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Accounting periods, and the lock that makes closing one mean something.
 *
 * The dialog on the Period Closing screen makes a promise — "No entry can be
 * posted into this period afterwards" — and most of this file exists to hold
 * the application to it through every door into the books: the manual entry
 * screen, an expense, a fund transfer, a reversal, and a draft prepared while
 * the month was still open.
 */
class AccountingPeriodTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return list<array<string, mixed>> */
    private function periods(): array
    {
        return $this->getJson('/api/accounting/periods?per_page=100')->assertOk()->json('data');
    }

    private function closeMonth(string $code): array
    {
        $this->getJson('/api/accounting/periods?per_page=100')->assertOk();

        $period = AccountingPeriod::where('code', $code)->sole();

        return $this->postJson("/api/accounting/periods/{$period->id}/close")
            ->assertOk()->json('data');
    }

    // ── Where periods come from ──

    /**
     * Nobody creates a period by hand, and there is no endpoint that would let
     * them: the months the books span are a fact about the ledger.
     */
    public function test_periods_are_provisioned_from_the_earliest_journal_through_today(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);

        $codes = array_column($this->periods(), 'code');

        $this->assertContains('2026-07', $codes);
        $this->assertContains('2026-08', $codes);
        $this->assertContains(now()->format('Y-m'), $codes);

        // Chronological, oldest first — the screen's own reasoning: the periods
        // someone opens it to close are the OLD ones, and a newest-first list
        // would push them past the first page.
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes);
    }

    public function test_provisioning_is_idempotent_and_never_reopens_a_closed_month(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        $before = AccountingPeriod::count();

        $this->periods();
        $this->periods();

        $this->assertSame($before, AccountingPeriod::count());
        $this->assertSame('closed', AccountingPeriod::where('code', '2026-07')->value('status'));
    }

    public function test_a_ledger_with_no_journals_still_gets_the_current_month(): void
    {
        $codes = array_column($this->periods(), 'code');

        $this->assertSame([now()->format('Y-m')], $codes);
    }

    // ── Closing ──

    public function test_closing_records_who_did_it_and_when(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);

        $closed = $this->closeMonth('2026-07');

        $this->assertSame('closed', $closed['status']);
        $this->assertNotNull($closed['closed_at']);

        // A NAME, not an id. The screen concatenates it straight into
        // "`${formatDateTime(closed_at)} · ${closed_by}`", so an integer here
        // would render as "· 7".
        $this->assertSame($this->admin->full_name, $closed['closed_by']);
        $this->assertIsString($closed['closed_by']);
    }

    public function test_a_closed_period_cannot_be_closed_again(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        $id = AccountingPeriod::where('code', '2026-07')->value('id');

        $this->postJson("/api/accounting/periods/{$id}/close")
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    // ── THE LOCK ──

    /** A manual entry is refused. */
    public function test_a_closed_period_refuses_a_new_journal(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        $this->expectException(ValidationException::class);
        $this->postSimpleJournal('1010', '3010', 50000, ['date' => '2026-07-20']);
    }

    /**
     * A draft prepared while the month was open, posted after it closed.
     *
     * This is the case only the check at POST time can see, and it is the
     * ordinary one: a bookkeeper drafts an entry on the 30th and it is approved
     * on the 3rd, by which time the month may have been signed off.
     */
    public function test_a_draft_made_before_the_close_cannot_be_posted_after_it(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);

        $draft = $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 50000, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 50000],
        ], ['date' => '2026-07-20']);

        $this->closeMonth('2026-07');

        $this->expectException(ValidationException::class);
        app(JournalPoster::class)->post($draft, $this->admin->id);
    }

    /** An expense is refused, and nothing is written. */
    public function test_a_closed_period_refuses_an_expense(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        $this->postJson('/api/accounting/expenses', [
            'date' => '2026-07-20',
            'payee' => 'Meralco',
            'expense_account_id' => $this->account('5030'),
            'amount' => 1500050,
            'payment_account_id' => $this->account('1010'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        // The expense row goes with the journal — the transaction rolls both
        // back. An expense recorded into a closed month with no entry behind it
        // would be exactly the cost-with-no-journal the module refuses to create.
        $this->assertSame(0, AccountingExpense::count());
    }

    /** A fund transfer is refused. */
    public function test_a_closed_period_refuses_a_transfer(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => '2026-07-20',
            'from_account_id' => $this->account('1010'),
            'to_account_id' => $this->account('1040'),
            'amount' => 50000,
            'description' => 'Cash to bank',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    /** A reversal is refused. Correcting a closed month means reopening it. */
    public function test_a_closed_period_refuses_a_reversal_into_it(): void
    {
        $entry = $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        $this->expectException(ValidationException::class);
        app(JournalPoster::class)->reverse($entry, '2026-07-31', 'Wrong account', $this->admin->id);
    }

    public function test_an_open_month_is_unaffected_by_a_closed_neighbour(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        // August was never closed, so it still takes entries. Closing locks one
        // month, not the books.
        $entry = $this->postSimpleJournal('1010', '3010', 50000, ['date' => '2026-08-01']);

        $this->assertSame('posted', $entry->status);
    }

    /**
     * A date with no period row posts freely.
     *
     * The only safe default. Periods are provisioned by a screen, and a co-op
     * that has never opened it must not find its own books refusing every entry
     * on the day this shipped.
     */
    public function test_a_date_with_no_period_row_is_not_locked(): void
    {
        $this->assertSame(0, AccountingPeriod::count());

        $entry = $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);

        $this->assertSame('posted', $entry->status);
    }

    // ── Reopening ──

    public function test_reopening_records_itself_and_keeps_the_original_sign_off(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $closed = $this->closeMonth('2026-07');

        $id = AccountingPeriod::where('code', '2026-07')->value('id');

        $reopened = $this->postJson("/api/accounting/periods/{$id}/reopen")->assertOk()->json('data');

        $this->assertSame('open', $reopened['status']);
        $this->assertNotNull($reopened['reopened_at']);
        $this->assertSame($this->admin->full_name, $reopened['reopened_by']);

        // `closed_by`/`closed_at` survive. They are the record that this month
        // was once signed off and by whom, which is exactly the fact that makes
        // reopening worth recording at all.
        $this->assertSame($closed['closed_at'], $reopened['closed_at']);
        $this->assertSame($closed['closed_by'], $reopened['closed_by']);
    }

    public function test_reopening_lets_entries_in_again(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->closeMonth('2026-07');

        $id = AccountingPeriod::where('code', '2026-07')->value('id');
        $this->postJson("/api/accounting/periods/{$id}/reopen")->assertOk();

        $entry = $this->postSimpleJournal('1010', '3010', 50000, ['date' => '2026-07-20']);
        $this->assertSame('posted', $entry->status);
    }

    public function test_an_open_period_cannot_be_reopened(): void
    {
        $this->periods();
        $id = AccountingPeriod::first()->id;

        $this->postJson("/api/accounting/periods/{$id}/reopen")
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    // ── Paging and permissions ──

    public function test_per_page_is_clamped_at_one_hundred(): void
    {
        // Ten years of monthly periods, which a co-op live since 2016 has.
        AccountingJournal::query()->insert([
            'date' => '2016-01-15', 'source' => 'manual', 'description' => 'Opening',
            'status' => 'draft', 'total_debit' => 0, 'total_credit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/accounting/periods?per_page=9999')->assertOk();

        $this->assertLessThanOrEqual(100, count($response->json('data')));
        $this->assertSame(100, $response->json('meta.per_page'));
        $this->assertGreaterThan(100, $response->json('meta.total'));
    }

    public function test_a_bookkeeper_cannot_close_a_period(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2026-07-15']);
        $this->periods();

        $id = AccountingPeriod::where('code', '2026-07')->value('id');

        // Closing locks a month. Along with posting and reversing, it is one of
        // the three things nobody should be able to do to their own work — see
        // the accounting permissions migration.
        $this->actingAs($this->userWithRole('general_bookkeeper'))
            ->postJson("/api/accounting/periods/{$id}/close")
            ->assertForbidden();
    }
}
