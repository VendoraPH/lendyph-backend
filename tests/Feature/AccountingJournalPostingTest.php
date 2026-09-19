<?php

namespace Tests\Feature;

use App\Exceptions\PostedJournalIsImmutableException;
use App\Models\AccountingAccount;
use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Money;
use App\Services\RepaymentService;
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

    // ── Balanced is not the same as plausible ──

    public function test_an_absurd_line_amount_is_refused_at_the_boundary(): void
    {
        // `debit`/`credit` are UNSIGNED BIGINT, so this BALANCES, satisfies
        // both CHECK constraints, records a non-zero amount, and would post —
        // landing on the trial balance and the dashboard as a real figure. No
        // report anywhere would refuse it.
        $absurd = Money::maxCentavos() + 1;

        $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Ninety-two quadrillion pesos',
            'lines' => [
                ['account_id' => $this->account('1010'), 'debit' => $absurd, 'credit' => 0],
                ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => $absurd],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.debit', 'lines.1.credit']);
    }

    public function test_the_largest_legitimate_amount_still_posts(): void
    {
        // The bound has to be a ceiling, not a guess — ₱999 trillion is absurd
        // for a co-op and is still a number this system is willing to record.
        $largest = Money::maxCentavos();

        $response = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'At the ceiling',
            'lines' => [
                ['account_id' => $this->account('1010'), 'debit' => $largest, 'credit' => 0],
                ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => $largest],
            ],
        ])->assertCreated();

        $this->postJson('/api/accounting/journals/'.$response->json('data.id').'/post')->assertOk();
    }

    public function test_the_automatic_posting_path_is_bounded_too(): void
    {
        // postImmediately() never sees a FormRequest, so the line rule does not
        // protect it. The per-line check inside post() does.
        //
        // Note the total check alone could NOT catch this: Money::sum() routes
        // each value through a float, so a line one centavo past the bound sums
        // to exactly the bound and the total looks fine. The evidence only
        // exists before the sum.
        $absurd = Money::maxCentavos() + 1;

        $this->expectException(ValidationException::class);

        app(JournalPoster::class)->postImmediately([
            'date' => '2026-09-15',
            'source' => 'loan_release',
            'description' => 'Release of an impossible loan',
            'branch_id' => $this->branch->id,
        ], [
            ['account_id' => $this->account('1110'), 'debit' => $absurd, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => $absurd],
        ], $this->admin->id);
    }

    public function test_a_total_over_the_ceiling_is_refused_even_when_every_line_is_legal(): void
    {
        // Each line is individually under the bound; their sum is not. The
        // per-line rule cannot see this, which is why the total is checked
        // separately after the recompute.
        // Four lines, none of them over the bound on its own — the credit side
        // has to be split too, or the per-line check fires first and this stops
        // testing the total at all.
        $half = intdiv(Money::maxCentavos(), 2) + 1000;

        $draft = $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => $half, 'credit' => 0],
            ['account_id' => $this->account('1110'), 'debit' => $half, 'credit' => 0],
            ['account_id' => $this->account('3010'), 'debit' => 0, 'credit' => $half],
            ['account_id' => $this->account('3020'), 'debit' => 0, 'credit' => $half],
        ]);

        $this->postJson("/api/accounting/journals/{$draft->id}/post")
            ->assertStatus(422)
            ->assertJsonValidationErrors('balance');
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

    // ── The register: GET /api/accounting/journals ──

    /**
     * Every field the resource promises, on the endpoint the Journals screen
     * actually calls.
     *
     * Nothing asserted this list before — no test anywhere had called
     * `GET /api/accounting/journals`, so a field could be dropped from
     * JournalEntryResource and the whole suite would stay green while the
     * register lost a column.
     */
    public function test_the_register_answers_the_documented_field_set(): void
    {
        $this->postSimpleJournal('5030', '1010', 350000);

        $this->getJson('/api/accounting/journals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id', 'journal_no', 'date', 'source', 'reference', 'description',
                        'branch_id', 'branch_name', 'status',
                        'lines' => [['id', 'account_id', 'account_code', 'account_name', 'description', 'debit', 'credit']],
                        'total_debit', 'total_credit',
                        'reverses_journal_id', 'reversed_by_journal_id',
                        'postable_type', 'postable_id', 'postable_label',
                        'created_by', 'created_at', 'posted_by', 'posted_at',
                    ],
                ],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    /**
     * The whole point of the exposure: an entry names the document that caused
     * it, in the API's own vocabulary rather than the application's.
     */
    public function test_a_loan_release_entry_names_the_loan_it_came_from(): void
    {
        $loan = $this->createReleasedLoan();

        $entry = collect($this->getJson('/api/accounting/journals')->assertOk()->json('data'))
            ->firstWhere('source', 'loan_release');

        $this->assertNotNull($entry, 'Releasing a loan wrote no loan_release entry.');

        $this->assertSame('loan', $entry['postable_type']);
        $this->assertSame($loan->id, $entry['postable_id']);
        $this->assertSame($loan->loan_account_number, $entry['postable_label']);

        // The column really does hold the FQCN — this application has no morph
        // map — so the alias is doing work rather than passing a value through.
        $this->assertSame(Loan::class, AccountingJournal::findOrFail($entry['id'])->postable_type);
    }

    public function test_a_collection_entry_names_the_receipt_it_came_from(): void
    {
        $loan = $this->createReleasedLoan();
        $repayment = app(RepaymentService::class)
            ->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $entry = collect($this->getJson('/api/accounting/journals')->assertOk()->json('data'))
            ->firstWhere('source', 'loan_collection');

        $this->assertNotNull($entry, 'A repayment wrote no loan_collection entry.');

        // The RECEIPT, not the loan — which is also what the idempotency index
        // keys on. See AutomaticPoster::loanCollection().
        $this->assertSame('repayment', $entry['postable_type']);
        $this->assertSame($repayment->id, $entry['postable_id']);
        $this->assertSame($repayment->receipt_number, $entry['postable_label']);

        $this->assertSame(Repayment::class, AccountingJournal::findOrFail($entry['id'])->postable_type);
    }

    /**
     * No internal class name reaches the wire, on any entry, ever.
     *
     * This is the assertion that fails if someone "simplifies" the resource by
     * emitting `$this->postable_type` straight from the column.
     */
    public function test_the_register_never_emits_a_fully_qualified_class_name(): void
    {
        $this->createReleasedLoan();
        $this->postSimpleJournal('5030', '1010', 1000);

        $body = $this->getJson('/api/accounting/journals')->assertOk()->getContent();

        $this->assertStringNotContainsString('App\\\\Models', $body);
        $this->assertStringNotContainsString('App\Models', $body);
    }

    /**
     * The three legitimate ways an entry has no source document. All of them
     * answer null on all three fields — never a missing key, which a typed
     * client would read as a different shape.
     */
    public function test_an_entry_with_no_source_document_answers_null_rather_than_omitting_the_fields(): void
    {
        // 1. A manual entry: nobody ever set one.
        $manual = $this->postSimpleJournal('5030', '1010', 350000);

        // 2. A reversal: JournalPoster::reverse() refuses to copy the
        //    original's postable on purpose, so a reversal of an entry that HAS
        //    one still has none.
        $this->postJson("/api/accounting/journals/{$manual->id}/reverse", ['reason' => 'Keyed twice'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'reversal')
            ->assertJsonPath('data.postable_type', null)
            ->assertJsonPath('data.postable_id', null)
            ->assertJsonPath('data.postable_label', null);

        // 3. A fund transfer, through the OTHER controller that returns this
        //    same resource. It is the document rather than being raised by one.
        $this->postSimpleJournal('1020', '3010', 5000000, ['date' => '2026-09-01']);

        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => '2026-09-18',
            'from_account_id' => $this->account('1020'),
            'to_account_id' => $this->account('1040'),
            'amount' => 1000050,
            'description' => 'GCash to bank sweep',
        ])
            ->assertCreated()
            ->assertJsonPath('data.postable_type', null)
            ->assertJsonPath('data.postable_id', null)
            ->assertJsonPath('data.postable_label', null);

        // And on the register, where the keys must be PRESENT and null rather
        // than absent — assertJsonPath(null) passes for a missing key too, so
        // the presence check has to be made separately.
        foreach ($this->getJson('/api/accounting/journals')->assertOk()->json('data') as $entry) {
            $this->assertArrayHasKey('postable_type', $entry);
            $this->assertArrayHasKey('postable_id', $entry);
            $this->assertArrayHasKey('postable_label', $entry);
        }

        $this->getJson("/api/accounting/journals/{$manual->id}")
            ->assertOk()
            ->assertJsonPath('data.postable_type', null)
            ->assertJsonPath('data.postable_label', null);
    }

    /**
     * Resolving the source documents costs ONE query per type, however many
     * rows the page holds.
     *
     * This is the property that separates a real batch load from a lazy one
     * that merely looks fine on a two-row fixture. It is measured two ways,
     * because each catches something the other misses: the per-table counts
     * prove the postables themselves are not fetched per row, and the total
     * proves nothing ELSE on the page became per-row either.
     *
     * The register is warmed first — permissions and the authenticated user
     * are resolved and cached on the first request of a test, which is worth
     * several queries and would otherwise be counted against the smaller page.
     */
    public function test_the_register_resolves_postables_without_going_n_plus_1(): void
    {
        $registerQueries = function (int $perType): array {
            AccountingJournal::query()->delete();

            for ($i = 1; $i <= $perType; $i++) {
                $this->postSimpleJournal('1110', '1010', 1000 + $i, [
                    'source' => 'loan_release',
                    'postable_type' => Loan::class,
                    'postable_id' => 1000 + $i,
                ]);
                $this->postSimpleJournal('1110', '1010', 2000 + $i, [
                    'source' => 'loan_collection',
                    'postable_type' => Repayment::class,
                    'postable_id' => 2000 + $i,
                ]);
                $this->postSimpleJournal('5030', '1010', 3000 + $i, [
                    'source' => 'expense',
                    'postable_type' => AccountingExpense::class,
                    'postable_id' => 3000 + $i,
                ]);
                // Null postables, which must cost nothing at all — they are a
                // large share of a real register.
                $this->postSimpleJournal('5030', '1010', 4000 + $i);
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->getJson('/api/accounting/journals?per_page=100')->assertOk();

            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return $queries;
        };

        // Warm the permission and user caches; the count below is about the
        // page, not about the first request of the test.
        $this->getJson('/api/accounting/journals')->assertOk();

        $one = $registerQueries(1);
        $six = $registerQueries(6);

        foreach (['loans', 'repayments', 'accounting_expenses'] as $table) {
            $hits = count(array_filter($six, fn (string $q): bool => str_contains($q, "from `{$table}`")));

            $this->assertSame(1, $hits, "Six {$table} postables on one page cost {$hits} queries; one batch is the point.");
        }

        // AccountingExpensePayment has no label column, so it is never queried
        // for at all rather than fetched and discarded.
        $this->assertSame(
            0,
            count(array_filter($six, fn (string $q): bool => str_contains($q, 'from `accounting_expense_payments`'))),
        );

        $this->assertSame(
            count($one),
            count($six),
            'The register cost '.count($one).' queries for 4 entries and '.count($six).' for 24. '
            .'A count that grows with the page is the whole bug.'
        );
    }

    /**
     * AutomaticPoster::loanFee(), creditLossProvision(), fundTransfer() and
     * walletCharge() all take an untyped `Model $postable`, so any class can
     * reach this column. An unmapped one must degrade, not throw.
     */
    public function test_an_unmapped_postable_class_degrades_instead_of_breaking_the_register(): void
    {
        // A real class, deliberately absent from POSTABLE_LABEL_COLUMNS.
        $this->postSimpleJournal('5030', '1010', 250000, [
            'source' => 'credit_loss',
            'postable_type' => Branch::class,
            'postable_id' => $this->branch->id,
        ]);

        $entry = $this->getJson('/api/accounting/journals')->assertOk()->json('data.0');

        // Snake-cased basename: readable, no namespace, and explicitly not part
        // of the contract — the fix is to curate it into POSTABLE_ALIASES.
        $this->assertSame('branch', $entry['postable_type']);
        $this->assertSame($this->branch->id, $entry['postable_id']);
        $this->assertNull($entry['postable_label']);
    }

    /**
     * THE REASON THIS IS NOT `with('postable')`.
     *
     * There is no morph map in this application — `Relation::enforceMorphMap()`
     * is called nowhere — so `postable_type` holds a fully-qualified class name
     * and every live database is full of them. Eloquent's morphTo eager-load
     * resolves each distinct type with `new $class`, which is a fatal Error,
     * not a null, when that class has been renamed or removed. A single such
     * row would answer 500 for the WHOLE page, for every user, on a register
     * that renders fine today because nothing dereferences the relation.
     *
     * Verified: swapping AccountingJournal::attachPostables() back to
     * `with('postable')` fails this test with
     * `Class "App\Models\SomeRenamedModel" not found`.
     */
    public function test_a_postable_class_that_no_longer_exists_does_not_take_the_register_down(): void
    {
        $this->postSimpleJournal('5030', '1010', 250000, [
            'source' => 'credit_loss',
            'postable_type' => 'App\Models\SomeRenamedModel',
            'postable_id' => 77,
        ]);

        $entry = $this->getJson('/api/accounting/journals')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('some_renamed_model', $entry['postable_type']);
        $this->assertSame(77, $entry['postable_id']);
        $this->assertNull($entry['postable_label']);

        // And the single-entry route, which resolves the postable separately.
        $this->getJson("/api/accounting/journals/{$entry['id']}")
            ->assertOk()
            ->assertJsonPath('data.postable_type', 'some_renamed_model')
            ->assertJsonPath('data.postable_label', null);
    }

    /**
     * A postable pointing at a row that is not there — the class is real, the
     * id resolves to nothing. The type and id still answer, because they are
     * read off the COLUMN rather than the relation.
     */
    public function test_a_source_document_that_no_longer_exists_still_names_its_type(): void
    {
        $this->postSimpleJournal('1110', '1010', 600000, [
            'source' => 'loan_release',
            'postable_type' => Loan::class,
            'postable_id' => 4242,
        ]);

        $this->getJson('/api/accounting/journals')
            ->assertOk()
            ->assertJsonPath('data.0.postable_type', 'loan')
            ->assertJsonPath('data.0.postable_id', 4242)
            ->assertJsonPath('data.0.postable_label', null);
    }

    /**
     * `?source=penalty` is accepted and returns nothing, and that is the
     * correct answer rather than a bug.
     *
     * Penalties are cash basis: a penalty is a LINE inside the
     * `loan_collection` journal, credited to `penalty_income` at collection,
     * and no entry is ever written with this source. See
     * AccountingJournal::SOURCES and PostingRules::loanCollection(). The filter
     * stays permissive deliberately — the note in
     * AccountingJournalController::index() gives the reasoning.
     */
    public function test_the_source_filter_accepts_a_reserved_value_and_returns_an_empty_page(): void
    {
        $this->postSimpleJournal('5030', '1010', 350000);

        $this->getJson('/api/accounting/journals?source=penalty')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        // And garbage is still refused, which is the job validation owes here.
        $this->getJson('/api/accounting/journals?source=not_a_source')
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');
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

    /**
     * The line count is the last way the per-line ceiling and the total
     * ceiling could be played off against each other: every line legal, but
     * enough of them that the summed total overflows PHP's integer range,
     * wraps, and slips under the total check.
     */
    public function test_an_entry_with_too_many_lines_is_refused(): void
    {
        $cash = $this->account('1010');
        $income = $this->account('4010');

        $lines = [];

        for ($i = 0; $i < JournalPoster::MAX_LINES + 1; $i++) {
            $lines[] = ['account_id' => $cash, 'debit' => 100, 'credit' => 0];
            $lines[] = ['account_id' => $income, 'debit' => 0, 'credit' => 100];
        }

        $this->postJson('/api/accounting/journals', [
            'date' => now()->toDateString(),
            'description' => 'Too many lines',
            'lines' => $lines,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }
}
