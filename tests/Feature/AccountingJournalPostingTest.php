<?php

namespace Tests\Feature;

use App\Exceptions\PostedJournalIsImmutableException;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Services\Accounting\JournalPoster;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Drafting and posting: the gate every peso in the system crosses.
 *
 * Almost nothing here fails loudly if it is wrong. An entry that posts twice
 * still balances. A header whose totals came from the client still balances. A
 * posted entry that can be edited still balances. In every case the trial
 * balance reports healthy books and the portfolio is quietly wrong, which is
 * why these are tests rather than review notes.
 */
class AccountingJournalPostingTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    // ── Numbering ──

    public function test_posting_assigns_a_sequential_journal_number(): void
    {
        $first = $this->postSimpleJournal('5030', '1010', 350000);
        $second = $this->postSimpleJournal('5030', '1010', 120000);

        $this->assertSame('JE-000001', $first->journal_no);
        $this->assertSame('JE-000002', $second->journal_no);
    }

    public function test_a_draft_carries_no_journal_number_until_it_posts(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        // NULL rather than "", because the column is uniquely indexed and MySQL
        // treats NULLs as distinct — any number of drafts can coexist, while ""
        // could only ever belong to one of them.
        $this->assertNull($draft->journal_no);
        $this->assertSame('draft', $draft->status);

        // The API still answers the contract's non-nullable `journal_no` with a
        // string, which is what the frontend's own draft fixture uses.
        $this->getJson("/api/accounting/journals/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.journal_no', '');
    }

    public function test_a_gap_is_never_left_by_a_draft_that_is_never_posted(): void
    {
        $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        // Numbers are allocated on POST, so an abandoned draft cannot burn one
        // out of the middle of the register.
        $this->assertSame('JE-000001', $this->postSimpleJournal('5030', '1010', 500)->journal_no);
    }

    // ── The gate ──

    public function test_posting_twice_is_refused(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 350000);

        $this->postJson("/api/accounting/journals/{$journal->id}/post")
            ->assertStatus(422)
            ->assertJsonValidationErrors('journal');

        // And no second number was spent on it.
        $this->assertSame('JE-000001', $journal->fresh()->journal_no);
    }

    public function test_an_unbalanced_draft_cannot_post(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 350000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 300000],
        ]);

        $this->postJson("/api/accounting/journals/{$draft->id}/post")
            ->assertStatus(422)
            ->assertJsonValidationErrors('balance');

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_a_single_line_draft_cannot_post(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 350000, 'credit' => 0],
        ]);

        $this->postJson("/api/accounting/journals/{$draft->id}/post")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    public function test_an_entry_of_zero_on_both_sides_cannot_post(): void
    {
        // 0 === 0 balances and records nothing. Posting it would spend a
        // journal number on an entry that appears on no statement — and the
        // line CHECK already refuses a zero line, so this is enforced twice.
        $this->expectException(ValidationException::class);

        $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 0, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 0],
        ]);
    }

    public function test_a_line_against_a_group_heading_is_refused_at_post_time(): void
    {
        // The draft is legal — 1100 Loans Receivable is a real account — and
        // only the POST refuses it. That ordering is the point: the check has
        // to run against the chart as it is now, not as it was when drafted.
        $draft = $this->draftJournal([
            ['account_id' => $this->account('1100'), 'debit' => 100000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100000],
        ]);

        $this->postJson("/api/accounting/journals/{$draft->id}/post")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertStringContainsString(
            'heading',
            $this->postJson("/api/accounting/journals/{$draft->id}/post")->json('errors.lines.0'),
        );
    }

    public function test_an_account_deactivated_between_draft_and_post_is_refused(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100000],
        ]);

        // Drafted this afternoon, approved tomorrow morning, and in between an
        // administrator retired the account.
        AccountingAccount::query()->whereKey($this->account('5030'))->update(['is_active' => false]);

        $this->postJson("/api/accounting/journals/{$draft->id}/post")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertSame('draft', $draft->fresh()->status);
    }

    // ── Totals are the server's ──

    public function test_client_supplied_totals_are_ignored_and_recomputed(): void
    {
        // The header says ₱1.00 while the lines say ₱3,500.00 — the shape a
        // client bug produces. If the header were trusted, every statement
        // would read the lines and every reconciliation would read the header.
        $response = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Electricity for September',
            'branch_id' => $this->branch->id,
            'total_debit' => 100,
            'total_credit' => 100,
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 350000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 350000],
            ],
        ])->assertCreated();

        $id = $response->json('data.id');

        $this->postJson("/api/accounting/journals/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.total_debit', 350000)
            ->assertJsonPath('data.total_credit', 350000);
    }

    public function test_the_source_is_forced_to_manual_on_the_create_route(): void
    {
        // A hand-typed entry must not be able to disguise itself as an
        // automatic posting, or `source` stops meaning anything.
        $response = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Not a collection',
            'source' => 'loan_collection',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
            ],
        ])->assertCreated();

        $this->assertSame('manual', $response->json('data.source'));
    }

    public function test_a_line_with_both_sides_is_refused_at_the_boundary(): void
    {
        $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Both sides',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 100],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.debit');
    }

    public function test_a_line_with_neither_side_is_refused_at_the_boundary(): void
    {
        $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'No amount',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 0, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.debit');
    }

    public function test_an_entry_needs_at_least_two_lines_at_the_boundary(): void
    {
        $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Half an entry',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    // ── Immutability ──

    public function test_updating_a_posted_journal_over_the_api_is_refused(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 350000);

        $this->putJson("/api/accounting/journals/{$journal->id}", [
            'date' => '2026-09-20',
            'description' => 'Rewritten history',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 1, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('journal');

        $this->assertSame(350000, $journal->fresh()->total_debit);
    }

    public function test_updating_a_posted_journal_on_the_model_throws(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 350000);

        // The backstop for every caller that does not go through the API — a
        // console command, an importer, a tinker session during an incident.
        $this->expectException(PostedJournalIsImmutableException::class);

        $journal->update(['description' => 'Rewritten history']);
    }

    public function test_deleting_a_posted_journal_throws(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 350000);

        $this->expectException(PostedJournalIsImmutableException::class);

        $journal->delete();
    }

    public function test_a_posted_journals_lines_cannot_be_touched(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 350000);
        $line = $journal->lines()->first();

        // Without this guard the header's immutability is worth very little:
        // the totals would stay as posted while the lines said something else,
        // and every report reads the LINES.
        $this->expectException(PostedJournalIsImmutableException::class);

        $line->update(['debit' => 1]);
    }

    public function test_a_draft_can_be_edited_and_deleted_freely(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        $this->putJson("/api/accounting/journals/{$draft->id}", [
            'date' => '2026-09-16',
            'description' => 'Corrected before posting',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 250, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 250],
            ],
        ])->assertOk()->assertJsonPath('data.description', 'Corrected before posting');

        $this->assertSame(2, $draft->fresh()->lines()->count());
        $this->assertTrue($draft->fresh()->delete());
    }

    // ── Idempotency ──

    public function test_a_retried_automatic_posting_returns_the_entry_that_already_exists(): void
    {
        $poster = app(JournalPoster::class);

        $attributes = [
            'date' => '2026-09-15',
            'source' => 'loan_release',
            'description' => 'Release of LN-000001',
            'branch_id' => $this->branch->id,
            'postable_type' => 'App\\Models\\Loan',
            'postable_id' => 4242,
        ];

        $lines = [
            ['account_id' => $this->account('1110'), 'debit' => 6000000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 6000000],
        ];

        $first = $poster->postImmediately($attributes, $lines, $this->admin->id);
        $second = $poster->postImmediately($attributes, $lines, $this->admin->id);

        // The retry must not double-book. A second balanced journal would leave
        // the trial balance balanced and the portfolio counted twice, with
        // nothing anywhere to point at the difference.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AccountingJournal::query()->where('source', 'loan_release')->count());
    }

    public function test_the_idempotency_guard_is_scoped_to_the_source(): void
    {
        $poster = app(JournalPoster::class);

        $base = [
            'date' => '2026-09-15',
            'branch_id' => $this->branch->id,
            'postable_type' => 'App\\Models\\Loan',
            'postable_id' => 4242,
        ];

        $release = $poster->postImmediately(
            $base + ['source' => 'loan_release', 'description' => 'Release'],
            [
                ['account_id' => $this->account('1110'), 'debit' => 6000000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 6000000],
            ],
            $this->admin->id,
        );

        // One document legitimately raises several entries — released, then
        // collected against, then charged a fee.
        $fee = $poster->postImmediately(
            $base + ['source' => 'loan_fee', 'description' => 'Processing fee'],
            [
                ['account_id' => $this->account('1010'), 'debit' => 50000, 'credit' => 0],
                ['account_id' => $this->account('4030'), 'debit' => 0, 'credit' => 50000],
            ],
            $this->admin->id,
        );

        $this->assertNotSame($release->id, $fee->id);
    }

    // ── Database constraints: the shapes no code path would create ──

    public function test_the_database_refuses_a_line_carrying_both_sides(): void
    {
        $journal = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        $this->expectException(QueryException::class);

        // Straight past every model event and every validator. A line that is
        // both sides at once sums correctly into BOTH totals, so an entry built
        // from them balances and no report would ever catch it.
        DB::table('accounting_journal_lines')->insert([
            'accounting_journal_id' => $journal->id,
            'accounting_account_id' => $this->account('1010'),
            'line_no' => 90,
            'debit' => 100,
            'credit' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_line_with_nothing_on_either_side(): void
    {
        $journal = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        $this->expectException(QueryException::class);

        DB::table('accounting_journal_lines')->insert([
            'accounting_journal_id' => $journal->id,
            'accounting_account_id' => $this->account('1010'),
            'line_no' => 91,
            'debit' => 0,
            'credit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_unbalanced_posted_header(): void
    {
        $journal = $this->postSimpleJournal('5030', '1010', 350000);

        $this->expectException(QueryException::class);

        DB::table('accounting_journals')
            ->where('id', $journal->id)
            ->update(['total_debit' => 350000, 'total_credit' => 1]);
    }

    public function test_an_account_with_journal_lines_cannot_be_deleted(): void
    {
        // The restricting foreign key, which is what makes "this account has
        // transactions" true AT THE DATABASE rather than merely checked in a
        // controller. Deleting it would destroy one half of a balance.
        $this->postSimpleJournal('5030', '1010', 350000);

        $this->expectException(QueryException::class);

        DB::table('accounting_accounts')->where('id', $this->account('5030'))->delete();
    }

    public function test_deleting_a_draft_takes_its_lines_with_it(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        $draft->delete();

        // Orphan lines would be indistinguishable from real ones in any
        // aggregate that does not join the header.
        $this->assertSame(0, AccountingJournalLine::query()->where('accounting_journal_id', $draft->id)->count());
    }
}
