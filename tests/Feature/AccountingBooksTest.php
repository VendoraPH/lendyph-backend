<?php

namespace Tests\Feature;

use App\Services\Accounting\JournalPoster;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The BIR books of account — the general journal and the general ledger.
 *
 * The central claim these tests defend is that the two books are ONE set of
 * postings in two orders. If they ever disagree about what happened in a
 * period, one of them is lying to an auditor, and the failure would be
 * invisible on screen because each book is internally consistent.
 */
class AccountingBooksTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return array<string, mixed> */
    private function book(string $path, string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return $this->getJson("/api/accounting/books/{$path}?from={$from}&to={$to}")
            ->assertOk()
            ->json('data');
    }

    public function test_general_journal_returns_the_shape_the_books_screen_renders(): void
    {
        $this->postSimpleJournal('1010', '4010', 123456, [
            'date' => '2026-09-10',
            'reference' => 'COL-10254',
            'description' => 'Interest collected',
        ]);

        $book = $this->book('general-journal');

        // Every key `AccountingBook` in src/types/accounting.ts declares.
        $this->assertSame('general_journal', $book['kind']);
        $this->assertSame('2026-09-01', $book['from']);
        $this->assertSame('2026-09-30', $book['to']);
        $this->assertCount(2, $book['rows']);

        // And every key of `BookRow`, which book-report.tsx reads by name —
        // a missing one renders as an empty cell rather than an error.
        $row = $book['rows'][0];
        $this->assertSame(
            ['date', 'journal_no', 'reference', 'particulars', 'account_code', 'account_name', 'debit', 'credit'],
            array_keys($row),
        );
        $this->assertSame('2026-09-10', $row['date']);
        $this->assertSame('COL-10254', $row['reference']);
        $this->assertSame('Interest collected', $row['particulars']);
        $this->assertSame('1010', $row['account_code']);
        $this->assertStringContainsString('Cash', $row['account_name']);
    }

    public function test_amounts_are_integer_centavos_not_decimal_strings(): void
    {
        $this->postSimpleJournal('1010', '4010', 123456, ['date' => '2026-09-10']);

        $book = $this->book('general-journal');

        // assertSame, so a "123456" string fails. A decimal string reaching the
        // client is not hypothetical here: it is what made the Cash & Bank
        // total read ₱0.00 while every row beneath it formatted correctly, and
        // `formatCentavos` would coerce it silently on the way past.
        $this->assertSame(123456, $book['rows'][0]['debit']);
        $this->assertSame(0, $book['rows'][0]['credit']);
        $this->assertSame(123456, $book['total_debit']);
        $this->assertSame(123456, $book['total_credit']);
    }

    public function test_the_two_books_are_the_same_postings_in_two_orders(): void
    {
        $this->postSimpleJournal('1010', '4010', 500000, ['date' => '2026-09-20']);
        $this->postSimpleJournal('1040', '3010', 250000, ['date' => '2026-09-05']);

        $journal = $this->book('general-journal');
        $ledger = $this->book('general-ledger');

        $this->assertSame('general_ledger', $ledger['kind']);

        // Same lines, same money. This is the invariant that makes them one
        // report rather than two that happen to look alike.
        $this->assertCount(4, $journal['rows']);
        $this->assertCount(4, $ledger['rows']);
        $this->assertSame($journal['total_debit'], $ledger['total_debit']);
        $this->assertSame($journal['total_credit'], $ledger['total_credit']);
        $this->assertSame(750000, $journal['total_debit']);

        // The journal is a diary: chronological.
        $this->assertSame(
            ['2026-09-05', '2026-09-05', '2026-09-20', '2026-09-20'],
            array_column($journal['rows'], 'date'),
        );

        // The ledger is by account, in code order — which is statement order,
        // because codes are fixed-width numeric strings.
        $this->assertSame(
            ['1010', '1040', '3010', '4010'],
            array_column($ledger['rows'], 'account_code'),
        );
    }

    public function test_drafts_are_excluded_and_reversals_are_included(): void
    {
        // A draft is not in the books at all. A book of account showing
        // unposted entries would be a misrepresentation, not a preview.
        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 999900, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => 999900],
        ], ['date' => '2026-09-12']);

        $posted = $this->postSimpleJournal('1010', '4010', 100000, ['date' => '2026-09-12']);
        app(JournalPoster::class)
            ->reverse($posted, '2026-09-13', 'Wrong account', $this->admin->id);

        $book = $this->book('general-journal');

        // 2 lines for the original + 2 for its mirror. The draft's 999900
        // appears nowhere.
        $this->assertCount(4, $book['rows']);
        $this->assertSame(200000, $book['total_debit']);

        // A reversed entry is a posted historical fact whose mirror nets it to
        // zero — both halves stay on the books, so the two sides are equal.
        $this->assertSame($book['total_debit'], $book['total_credit']);
    }

    public function test_only_postings_inside_the_range_are_returned(): void
    {
        $this->postSimpleJournal('1010', '4010', 111100, ['date' => '2026-08-31']);
        $this->postSimpleJournal('1010', '4010', 222200, ['date' => '2026-09-01']);
        $this->postSimpleJournal('1010', '4010', 333300, ['date' => '2026-09-30']);
        $this->postSimpleJournal('1010', '4010', 444400, ['date' => '2026-10-01']);

        $book = $this->book('general-journal');

        // Both ends INCLUSIVE — 1 September and 30 September are in a book for
        // September. An off-by-one at either edge silently drops a day's
        // trading from a statutory register.
        $this->assertSame(222200 + 333300, $book['total_debit']);
    }

    public function test_a_range_longer_than_a_year_is_refused_rather_than_truncated(): void
    {
        // The bound is on the RANGE, never on the rows. A row cap would answer
        // 200 with a partial book that totals only what it kept — and
        // `AccountingBook` has no way to say so.
        $this->getJson('/api/accounting/books/general-journal?from=2026-01-01&to=2027-01-02')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');

        // Exactly a year is fine, inclusive of both ends.
        $this->getJson('/api/accounting/books/general-journal?from=2026-01-01&to=2026-12-31')
            ->assertOk();
    }

    public function test_dates_are_required_and_must_be_in_order(): void
    {
        // A book of account is a book FOR A PERIOD. Defaulting the range would
        // put figures on screen that answer a question nobody asked.
        $this->getJson('/api/accounting/books/general-journal')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);

        $this->getJson('/api/accounting/books/general-journal?from=2026-09-30&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_both_books_require_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole());

        $this->getJson('/api/accounting/books/general-journal?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
        $this->getJson('/api/accounting/books/general-ledger?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }

    public function test_a_manager_may_read_the_books(): void
    {
        // `manager` is the read-only accounting role. The books are a read.
        $this->actingAs($this->userWithRole('manager'));

        $this->getJson('/api/accounting/books/general-ledger?from=2026-09-01&to=2026-09-30')
            ->assertOk();
    }

    public function test_a_branch_filter_narrows_the_book(): void
    {
        $this->postSimpleJournal('1010', '4010', 123400, [
            'date' => '2026-09-10',
            'branch_id' => $this->branch->id,
        ]);
        $this->postSimpleJournal('1010', '4010', 567800, [
            'date' => '2026-09-11',
            'branch_id' => null,
        ]);

        $book = $this->getJson(
            '/api/accounting/books/general-journal?from=2026-09-01&to=2026-09-30&branch_id='.$this->branch->id
        )->assertOk()->json('data');

        $this->assertSame(123400, $book['total_debit']);
    }

    public function test_an_empty_period_is_an_empty_book_rather_than_an_error(): void
    {
        $book = $this->book('general-journal', '2026-01-01', '2026-01-31');

        // The screen renders its own "nothing recorded" state off `rows.length`,
        // so this must be a 200 with zero rows, not a 404.
        $this->assertSame([], $book['rows']);
        $this->assertSame(0, $book['total_debit']);
        $this->assertSame(0, $book['total_credit']);
    }
}
