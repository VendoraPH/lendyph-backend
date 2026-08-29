<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportFile;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Services\BorrowerPurgeService;
use App\Services\CsvImport\CsvImportRowRedactor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PDOException;
use Tests\TestCase;
use Tests\Traits\StagesCsvImportRuns;

/**
 * Retention and erasure for the personal data staged in `csv_import_rows`.
 *
 * Two halves of one finding. The uploaded CSV is deleted when a run closes, but
 * staging had already copied every member's name, birthdate, contact number and
 * income into these rows, and nothing removed them — so a completed migration
 * left a full membership register readable through the error report for the life
 * of the deployment, and deleting a borrower left their line in it intact.
 *
 * The erasure half has a second edge, and it is the one that actually bit:
 * `borrower_id` links only the rows that produced or matched a member, so an
 * erasure that redacted on it alone blanked the linked line and left the
 * member's ambiguous and invalid lines streaming out of `errors.csv`. Those are
 * matched on `external_account_no` now, and the tests at the bottom of this file
 * assert all three kinds of row together — a test covering only the linked row
 * passes against the broken predicate and proves nothing.
 *
 * What both halves must NOT do is change the arithmetic. The counts on the
 * status endpoint and the groups on the error summary are what an operator
 * reconciles a migration against years later, and they are asserted here to be
 * byte-identical across a redaction.
 */
class CsvImportRowRetentionTest extends TestCase
{
    use StagesCsvImportRuns;

    private CsvImportRun $run;

    private CsvImportFile $customers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        $this->run = $this->makeImportRun([
            'phase' => 'completed',
            'finished_at' => now()->subDays(45),
        ]);

        $this->customers = $this->makeImportFile($this->run, 'customers');
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $options
     */
    private function stageMember(int $line, array $values = [], array $options = []): CsvImportRow
    {
        return $this->stageRow($this->customers, $line, array_merge([
            'account_no' => 'A-'.$line,
            'last_name' => 'Dela Cruz',
            'first_name' => 'Maria',
            'birthdate' => '1979-04-02',
            'contact_number' => '09171234567',
            'street_address' => '12 Rizal St',
            'monthly_income' => 1800000,
        ], $values), $options);
    }

    public function test_it_blanks_the_personal_columns_and_keeps_the_countable_ones(): void
    {
        $row = $this->stageMember(2, [], [
            'result' => 'imported',
            'result_category' => 'imported_with_warnings',
            'result_message' => '"09171234567 / 0928" is not a usable contact number',
            'warnings' => [['contact_number', 'contact_number_unusable', '"09171234567 / 0928" is not a usable contact number']],
        ]);

        Artisan::call('imports:redact-rows');
        $row->refresh();

        $this->assertNull($row->raw, 'raw holds every cell of the member verbatim.');
        $this->assertNull($row->result_message, 'the message quotes the offending cell.');
        $this->assertNotNull($row->redacted_at);

        // `normalized` survives only as its note codes; the parsed member goes.
        $this->assertArrayNotHasKey('values', $row->normalized, 'values IS the parsed member.');
        $this->assertSame('contact_number', $row->normalized['warnings'][0]['field']);
        $this->assertSame('contact_number_unusable', $row->normalized['warnings'][0]['code']);
        $this->assertSame(CsvImportRowRedactor::REDACTED_MESSAGE, $row->normalized['warnings'][0]['message']);

        $this->assertStringNotContainsString('09171234567', json_encode($row->getAttributes()));
        $this->assertStringNotContainsString('Dela Cruz', json_encode($row->getAttributes()));

        // The four the counts and the grouped summary are derived from.
        $this->assertSame(2, $row->line_number);
        $this->assertSame('valid', $row->status);
        $this->assertSame('imported', $row->result);
        $this->assertSame('imported_with_warnings', $row->result_category);

        $this->assertNotNull($this->run->fresh()->rows_redacted_at);
    }

    public function test_it_keeps_the_field_and_code_of_an_error_note_and_drops_its_message(): void
    {
        $row = $this->stageMember(3, ['birthdate' => '31/02/1979'], [
            'errors' => [['birthdate', 'date_invalid', '"31/02/1979" is not a date this importer recognises.']],
        ]);

        Artisan::call('imports:redact-rows');
        $row->refresh();

        /*
         * Blanking the whole `errors` column would have been simpler and would
         * have collapsed every per-field code into the row-level
         * `result_category`, quietly changing the grouped summary a redaction is
         * supposed to leave alone. The codes are a bounded vocabulary; only the
         * messages quote the member's cell.
         */
        $this->assertSame('birthdate', $row->errors[0]['field']);
        $this->assertSame('date_invalid', $row->errors[0]['code']);
        $this->assertSame(CsvImportRowRedactor::REDACTED_MESSAGE, $row->errors[0]['message']);
        $this->assertStringNotContainsString('31/02/1979', json_encode($row->getAttributes()));
    }

    public function test_the_status_counts_and_error_summary_survive_a_redaction(): void
    {
        $this->stageMember(2, [], ['result' => 'imported']);
        $this->stageMember(3, [], [
            'errors' => [['birthdate', 'date_invalid', 'Not a date.']],
            'result' => 'failed',
            'result_category' => 'row_invalid',
            'result_message' => 'Not a date.',
        ]);
        $this->stageMember(4, [], [
            'result' => 'skipped',
            'result_category' => 'duplicate_account_no',
            'result_message' => 'Account A-4 appears twice.',
        ]);

        $countsBefore = $this->getJson("/api/imports/{$this->run->id}")->assertOk()->json('data.totals');
        $summaryBefore = $this->getJson("/api/imports/{$this->run->id}/errors")->assertOk()->json('meta');

        Artisan::call('imports:redact-rows');

        $countsAfter = $this->getJson("/api/imports/{$this->run->id}")->assertOk()->json('data.totals');
        $summaryAfter = $this->getJson("/api/imports/{$this->run->id}/errors")->assertOk()->json('meta');

        $this->assertSame($countsBefore, $countsAfter, 'Redaction must not move a single count.');
        $this->assertSame(3, $countsAfter['total']);

        $this->assertSame($summaryBefore['total'], $summaryAfter['total'], 'The same rows must still be reported.');
        $this->assertSame($summaryBefore['stats']['total_issues'], $summaryAfter['stats']['total_issues']);
        $this->assertSame($summaryBefore['stats']['rows_reported'], $summaryAfter['stats']['rows_reported']);
        $this->assertSame($summaryBefore['stats']['by_severity'], $summaryAfter['stats']['by_severity']);
        $this->assertSame(
            array_column($summaryBefore['stats']['by_category'], 'count', 'category'),
            array_column($summaryAfter['stats']['by_category'], 'count', 'category'),
            'Every group is derived from a code or a result_category, and redaction keeps both.',
        );

        // What it DOES lose, asserted so nobody discovers it in production: the
        // sentence beside each group, which is where the cell was quoted.
        $labels = array_column($summaryAfter['stats']['by_category'], 'label', 'category');
        $this->assertSame(CsvImportRowRedactor::REDACTED_MESSAGE, $labels['date_invalid']);
        $this->assertStringNotContainsString('Dela Cruz', json_encode($summaryAfter));
        $this->assertStringNotContainsString('Dela Cruz', $this->get("/api/imports/{$this->run->id}/errors.csv")->streamedContent());
    }

    public function test_it_publishes_the_redaction_date_on_the_status_endpoint(): void
    {
        $this->stageMember(2, [], ['result' => 'imported']);

        $this->assertNull($this->getJson("/api/imports/{$this->run->id}")->json('data.rows_redacted_at'));

        Artisan::call('imports:redact-rows');

        $this->assertNotNull(
            $this->getJson("/api/imports/{$this->run->id}")->json('data.rows_redacted_at'),
            'An operator whose error report went quiet is owed the date it happened.',
        );
    }

    public function test_it_leaves_a_run_that_finished_inside_the_window_alone(): void
    {
        $this->run->forceFill(['finished_at' => now()->subDays(3)])->save();
        $row = $this->stageMember(2, [], ['result' => 'imported']);

        Artisan::call('imports:redact-rows');

        $this->assertNotNull($row->fresh()->raw, 'A run finished three days ago is still being worked on.');
        $this->assertNull($this->run->fresh()->rows_redacted_at);
    }

    public function test_it_never_touches_a_run_that_is_still_open(): void
    {
        $this->run->forceFill([
            'phase' => 'importing_customers',
            'finished_at' => null,
            'updated_at' => now()->subDays(120),
        ])->save();

        $row = $this->stageMember(2);

        Artisan::call('imports:redact-rows');

        $this->assertNotNull(
            $row->fresh()->raw,
            'These rows are the resume set; redacting them would destroy an import mid-flight.',
        );
    }

    public function test_a_closed_run_with_no_finished_at_is_still_redacted(): void
    {
        /*
         * `finished_at` is stamped on every terminal transition today, so this
         * is the defensive case: without the COALESCE onto updated_at, a closed
         * run carrying a NULL there would never satisfy the cutoff and would
         * hold its membership register forever.
         */
        $row = $this->stageMember(2, [], ['result' => 'imported']);

        CsvImportRun::withoutTimestamps(fn () => $this->run->forceFill([
            'finished_at' => null,
            'updated_at' => now()->subDays(90),
        ])->saveQuietly());

        Artisan::call('imports:redact-rows');

        $this->assertNull($row->fresh()->raw);
    }

    public function test_dry_run_writes_nothing_and_reports_what_the_real_run_does(): void
    {
        $this->stageMember(2, [], ['result' => 'imported']);
        $this->stageMember(3, [], ['result' => 'imported']);

        Artisan::call('imports:redact-rows', ['--dry-run' => true]);
        $dry = Artisan::output();

        $this->assertStringContainsString("would redact run #{$this->run->id}", $dry);
        $this->assertStringContainsString('2 row(s)', $dry);
        $this->assertStringContainsString('Would redact 2 staged row(s) across 1 run(s)', $dry);

        $this->assertSame(2, CsvImportRow::query()->unredacted()->count(), 'A dry run must write nothing.');
        $this->assertNull($this->run->fresh()->rows_redacted_at);

        Artisan::call('imports:redact-rows');
        $real = Artisan::output();

        $this->assertSame(
            str_replace(['would redact', 'Would redact'], ['redacting', 'Redacted'], $dry),
            $real,
            'The dry run must describe exactly what the real run then does.',
        );
    }

    public function test_it_is_idempotent_and_never_restamps_a_redacted_row(): void
    {
        $row = $this->stageMember(2, [], ['result' => 'imported']);

        Artisan::call('imports:redact-rows');
        $firstStamp = $row->fresh()->redacted_at;

        $this->travel(2)->days();
        Artisan::call('imports:redact-rows');
        $output = Artisan::output();

        $this->assertEquals($firstStamp, $row->fresh()->redacted_at, 'The date of the redaction is the answer to an erasure request.');
        $this->assertStringContainsString('No import run has been finished', $output);
    }

    public function test_purging_a_borrower_redacts_the_staged_rows_that_created_them(): void
    {
        Storage::fake('private');

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->importBranch->id,
            'external_account_no' => 'A-2',
            'last_name' => 'Dela Cruz',
        ]);

        $row = $this->stageMember(2, [], ['result' => 'imported']);
        $row->forceFill(['borrower_id' => $borrower->id])->save();

        /*
         * The run is INSIDE the retention window. The clock is not the erasure
         * path — a member asking to be forgotten today must not be told to wait
         * 30 days for a scheduled command.
         */
        $this->run->forceFill(['finished_at' => now()->subDay()])->save();

        app(BorrowerPurgeService::class)->purge($borrower);

        $row->refresh();

        $this->assertNull($row->raw, 'An orphaned row with the data still in it is not an erasure.');
        $this->assertNull($row->normalized);
        $this->assertNull($row->result_message);
        $this->assertNotNull($row->redacted_at);

        // The import record itself survives, exactly as nullOnDelete intends.
        $this->assertNull($row->borrower_id);
        $this->assertSame('imported', $row->result);
        $this->assertDatabaseMissing('borrowers', ['id' => $borrower->id]);
    }

    public function test_a_quiet_purge_redacts_the_staged_rows_too(): void
    {
        Storage::fake('private');

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->importBranch->id,
            'status' => 'pending',
        ]);

        $row = $this->stageMember(2, [], ['result' => 'imported']);
        $row->forceFill(['borrower_id' => $borrower->id])->save();

        /*
         * `audit: false` is the retention prune's mode. The flag decides whether
         * the Auditable trait copies the borrower's attributes into
         * audit_logs.old_values; it has no bearing on this sweep, which writes
         * no personal data in either mode.
         */
        app(BorrowerPurgeService::class)->purge($borrower, audit: false);

        $this->assertNull($row->fresh()->raw);
    }

    public function test_a_failed_purge_leaves_the_staged_rows_intact(): void
    {
        Storage::fake('private');

        $borrower = Borrower::factory()->create(['branch_id' => $this->importBranch->id]);

        $row = $this->stageMember(2, [], ['result' => 'imported']);
        $row->forceFill(['borrower_id' => $borrower->id])->save();

        /*
         * `loans.borrower_id` is restrictOnDelete, so a borrower with a loan
         * makes purge() throw part way through. The redaction must roll back
         * with it: a surviving borrower whose import history has been wiped is
         * the mirror image of the bug this fixes.
         */
        Loan::factory()->create([
            'borrower_id' => $borrower->id,
            'branch_id' => $this->importBranch->id,
        ]);

        try {
            app(BorrowerPurgeService::class)->purge($borrower);
            $this->fail('Deleting a borrower with a loan must throw.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertNotNull($row->fresh()->raw);
        $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
    }

    /**
     * The three staged rows an erasure has to reach, only ONE of which carries a
     * borrower.
     *
     * `csv_import_rows.borrower_id` is set when a row produced or matched a
     * member. Five of the import pass's outcomes leave it NULL while the row
     * still holds that member's entire line: markInvalidRowsSkipped(), which
     * stamps every row that failed validation at staging; BorrowerMatch::
     * ambiguous(), which passes `borrower: null` even though its candidates are
     * real members on file; a loan row that fails `borrower_not_found`; a row
     * abandoned after repeated attempts; and a row that threw mid-write.
     * Redacting on `borrower_id` alone therefore answered a right-to-erasure
     * request with the linked row blanked and the rest of the member still
     * readable.
     *
     * Two of the three staged below stand in for that whole set, because they
     * fail for the same structural reason: nothing linked them.
     *
     * The scenario is the ordinary one: a member imported successfully by a
     * later run whose earlier line was ambiguous or invalid. Both runs are
     * INSIDE the retention window, so nothing here can be credited to
     * `imports:redact-rows` — only the purge can explain it.
     */
    public function test_purging_a_member_redacts_the_lines_that_never_linked_to_them(): void
    {
        Storage::fake('private');

        $this->run->forceFill(['finished_at' => now()->subDay()])->save();

        $ambiguous = $this->stageMember(3, ['account_no' => 'A-001'], [
            'result' => 'skipped',
            'result_category' => 'ambiguous_match',
            'result_message' => 'Two members match Maria Dela Cruz born 1979-04-02, so the row was left for a human.',
        ]);

        $invalid = $this->stageRow($this->customers, 4, [
            'account_no' => 'A-001',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Maria',
        ], [
            // The birthdate never normalised, so it survives only in `raw` —
            // which is exactly where the error report reads Original Value from.
            'raw' => [
                'account_no' => 'A-001',
                'last_name' => 'Dela Cruz',
                'first_name' => 'Maria',
                'birthdate' => '31/02/1979',
            ],
            'errors' => [['birthdate', 'date_invalid', '"31/02/1979" is not a date this importer recognises.']],
            'result' => 'skipped',
            'result_category' => 'invalid_row',
            'result_message' => 'This row did not pass validation at staging and was not imported.',
        ]);

        // The later run, which did produce the member.
        $laterRun = $this->makeImportRun(['phase' => 'completed', 'finished_at' => now()->subDay()]);
        $laterFile = $this->makeImportFile($laterRun, 'customers');

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->importBranch->id,
            'external_account_no' => 'A-001',
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
        ]);

        $linked = $this->stageRow($laterFile, 2, [
            'account_no' => 'A-001',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Maria',
            'birthdate' => '1979-04-02',
        ], ['result' => 'imported']);
        $linked->forceFill(['borrower_id' => $borrower->id])->save();

        /*
         * The leak, before the fix is applied to it. Asserted rather than
         * assumed: a test that only checked the "after" would pass against a
         * report that never named the member in the first place.
         */
        $before = $this->get("/api/imports/{$this->run->id}/errors.csv")->streamedContent();
        $this->assertStringContainsString('A-001', $before);
        $this->assertStringContainsString('31/02/1979', $before);

        app(BorrowerPurgeService::class)->purge($borrower);

        foreach (['linked' => $linked, 'ambiguous' => $ambiguous, 'invalid' => $invalid] as $label => $row) {
            $row->refresh();

            $this->assertNotNull($row->redacted_at, "The {$label} row was not redacted.");
            $this->assertNull($row->raw, "The {$label} row still holds every cell of the member.");
            $this->assertNull($row->result_message, "The {$label} row's message quotes the member's cell.");
            $this->assertNull($row->external_account_no, "The {$label} row still names the erased member's account.");

            $attributes = json_encode($row->getAttributes());
            $this->assertStringNotContainsString('A-001', $attributes, "The {$label} row still names the account.");
            $this->assertStringNotContainsString('31/02/1979', $attributes, "The {$label} row still holds the birthdate.");
            $this->assertStringNotContainsString('Dela Cruz', $attributes, "The {$label} row still names the member.");
        }

        // And the endpoint the finding was proven through.
        $after = $this->get("/api/imports/{$this->run->id}/errors.csv")->streamedContent();
        $this->assertStringNotContainsString('A-001', $after, 'errors.csv still streams the erased account number.');
        $this->assertStringNotContainsString('31/02/1979', $after, 'errors.csv still streams the erased birthdate.');
        $this->assertStringNotContainsString('Dela Cruz', $after);

        // The arithmetic is untouched: the same lines are still reported, they
        // just no longer say whose they were.
        $this->assertSame(
            substr_count($before, "\n"),
            substr_count($after, "\n"),
            'An erasure must not change how many rows the report accounts for.',
        );

        $this->assertDatabaseMissing('borrowers', ['id' => $borrower->id]);
    }

    /**
     * The predicate is the member's OWN account number, not "any row without
     * one".
     *
     * `orWhere('external_account_no', null)` builds `= NULL` and matches
     * nothing, but a `whereNull` written in its place would match every staged
     * row that has no account number — in every run, belonging to everybody. The
     * blank row below is what fails if that ever happens.
     */
    public function test_a_purge_leaves_every_other_members_staged_lines_alone(): void
    {
        Storage::fake('private');

        $this->run->forceFill(['finished_at' => now()->subDay()])->save();

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->importBranch->id,
            'external_account_no' => 'A-001',
        ]);

        $mine = $this->stageMember(2, ['account_no' => 'A-001'], [
            'result' => 'skipped',
            'result_category' => 'ambiguous_match',
        ]);

        $somebodyElse = $this->stageMember(3, ['account_no' => 'A-002'], [
            'result' => 'skipped',
            'result_category' => 'ambiguous_match',
        ]);

        $noAccountNumber = $this->stageMember(4, ['account_no' => null], [
            'errors' => [['account_no', 'required', 'This field is required and the cell is blank.']],
            'result' => 'skipped',
            'result_category' => 'invalid_row',
        ]);

        $this->assertNull($noAccountNumber->fresh()->external_account_no);

        app(BorrowerPurgeService::class)->purge($borrower);

        $this->assertNull($mine->fresh()->raw);
        $this->assertNotNull($somebodyElse->fresh()->raw, 'A purge blanked a different member.');
        $this->assertNotNull($noAccountNumber->fresh()->raw, 'A purge blanked every row with no account number.');
    }

    /**
     * The erasure key is itself personal data, and goes with the rest of it.
     *
     * It is the cooperative's own identifier for the member, copied out of the
     * file — not a surrogate id like `borrower_id`, which is kept because it is
     * a link and means nothing outside this app. Keeping it would leave an
     * erased person's account number readable in this table forever, and it has
     * no job left: a redacted row holds nothing for a later erasure to find.
     */
    public function test_a_redaction_blanks_the_erasure_key_itself(): void
    {
        $row = $this->stageMember(2, ['account_no' => 'A-001'], ['result' => 'imported']);

        $this->assertSame('A-001', $row->external_account_no);

        Artisan::call('imports:redact-rows');

        $this->assertNull($row->fresh()->external_account_no);
        $this->assertStringNotContainsString('A-001', json_encode($row->fresh()->getAttributes()));
    }

    /**
     * Rows staged before the column existed are reachable too.
     *
     * Without the migration's backfill this fix would have a silent hole in
     * exactly the shape it closes: every row already in the table carries a NULL
     * key, so the widened predicate would miss all of them, and on a box that
     * has already run an import that is all of them.
     *
     * The migration is called directly rather than re-run through the migrator,
     * because the suite migrates fresh before every test and would otherwise
     * only ever see rows staged after the column existed — which is the one case
     * that cannot fail.
     */
    public function test_the_backfill_reaches_rows_staged_before_the_column_existed(): void
    {
        Storage::fake('private');

        $this->run->forceFill(['finished_at' => now()->subDay()])->save();

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->importBranch->id,
            'external_account_no' => 'A-001',
        ]);

        $legacy = $this->stageMember(3, ['account_no' => 'A-001'], [
            'result' => 'skipped',
            'result_category' => 'ambiguous_match',
        ]);

        $blank = $this->stageMember(4, ['account_no' => null], [
            'errors' => [['account_no', 'required', 'This field is required and the cell is blank.']],
            'result' => 'skipped',
            'result_category' => 'invalid_row',
        ]);

        // What a row staged before the migration looks like.
        DB::table('csv_import_rows')->update(['external_account_no' => null]);

        $migration = require database_path(
            'migrations/2026_08_30_100300_add_external_account_no_to_csv_import_rows_table.php'
        );
        $migration->backfill();

        $this->assertSame('A-001', $legacy->fresh()->external_account_no);

        /*
         * JSON_UNQUOTE of a JSON null returns the four-character string "null",
         * so without the JSON_TYPE guard this row would come back carrying a key
         * that looks populated, matches no member, and hides the fact that it
         * has none.
         */
        $this->assertNull($blank->fresh()->external_account_no, 'A blank account number was backfilled as text.');

        app(BorrowerPurgeService::class)->purge($borrower);

        $this->assertNull($legacy->fresh()->raw, 'A row staged before the column is still unreachable by an erasure.');
        $this->assertNotNull($blank->fresh()->raw);
    }

    /**
     * A redaction that fails must not print or log the members in the query that
     * failed.
     *
     * A QueryException's message is the failing SQL WITH THE BINDINGS
     * SUBSTITUTED IN, so `$e->getMessage()` on a sweep over this table is a
     * member's record — onto an operator's terminal, and into
     * `storage/logs/laravel.log`, which is the `single` channel: one file, never
     * rotated, mode 644. That the redactor's own statements happen to bind file
     * ids, nulls and timestamps today is a property of today's code, not a rule;
     * this test is the rule.
     *
     * The exception below is shaped like the real one — a lock-wait timeout,
     * which is the systemic failure that would hit every row rather than one.
     */
    public function test_a_failed_redaction_never_prints_or_logs_the_query_that_failed(): void
    {
        $this->stageMember(2, [], ['result' => 'imported']);

        $driver = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
        $driver->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

        $failure = new QueryException(
            'mysql',
            'update `csv_import_rows` set `raw` = ? where `id` = ?',
            ['["A-001","Dela Cruz","Maria","1979-04-02","09171234567"]', 41],
            $driver,
        );

        $this->assertStringContainsString('Dela Cruz', $failure->getMessage(), 'The fixture has to actually carry a member, or this test proves nothing.');

        $this->app->bind(CsvImportRowRedactor::class, fn () => new class($failure) extends CsvImportRowRedactor
        {
            public function __construct(private readonly QueryException $failure) {}

            public function redact(EloquentBuilder $query): int
            {
                throw $this->failure;
            }
        });

        Log::spy();

        $exit = Artisan::call('imports:redact-rows');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringNotContainsString('Dela Cruz', $output, 'The failing query was printed to the operator.');
        $this->assertStringNotContainsString('09171234567', $output);

        // What the operator DOES get: the driver's own number, which is the
        // difference between "retry it" and "something is wrong".
        $this->assertStringContainsString('database error (1205)', $output);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context);

            $this->assertStringNotContainsString('Dela Cruz', $encoded, 'The failing query was written to the shared log.');
            $this->assertStringNotContainsString('09171234567', $encoded);
            $this->assertSame('1205', $context['driver_code'] ?? null);
            $this->assertSame('HY000', $context['sql_state'] ?? null);

            return true;
        })->once();
    }

    /**
     * An erasure reaches a line that never PARSED, not just one that never
     * imported.
     *
     * The sibling of the five-outcome case above, and it fails for a different
     * reason: not "no borrower was produced" but "no VALUES were produced". A
     * column-count failure leaves both normalizers returning an empty
     * NormalizedRow, so there is no `account_no` to key on — while `raw` still
     * holds the member's whole line. CsvImportStager::externalAccountNo() falls
     * back to the raw cell for exactly this row, and this is the test that says
     * the fallback is load-bearing rather than defensive decoration.
     *
     * `values` is empty here on purpose: it IS the parse-failure shape, and a
     * fixture that filled it in would key off `normalized` and prove nothing.
     */
    public function test_purging_a_member_redacts_a_line_that_never_parsed(): void
    {
        Storage::fake('private');

        $this->run->forceFill(['finished_at' => now()->subDay()])->save();

        $unparseable = $this->stageRow($this->customers, 3, [], [
            'raw' => [
                'account_no' => 'A-001',
                'last_name' => 'Dela Cruz',
                'first_name' => 'Maria',
                'birthdate' => '1979-04-02',
                'contact_number' => '09171234567',
            ],
            'errors' => [['__row', 'row_column_count', 'This row has 23 columns but 22 were expected, so its values cannot be matched to fields.']],
            'result' => 'skipped',
            'result_category' => 'invalid_row',
        ]);

        $this->assertNull(
            $unparseable->normalized['values'][0],
            'The fixture has to have no parsed account number, or it is not testing the fallback.',
        );
        $this->assertSame('A-001', $unparseable->external_account_no, 'The key has to come from `raw` here.');

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->importBranch->id,
            'external_account_no' => 'A-001',
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
        ]);

        $before = $this->get("/api/imports/{$this->run->id}/errors.csv")->streamedContent();
        $this->assertStringContainsString('A-001', $before);

        app(BorrowerPurgeService::class)->purge($borrower);

        $unparseable->refresh();

        $this->assertNotNull($unparseable->redacted_at);
        $this->assertNull($unparseable->raw, 'The member is still in a line nothing could parse.');
        $this->assertNull($unparseable->external_account_no);
        $this->assertStringNotContainsString('09171234567', json_encode($unparseable->getAttributes()));
        $this->assertStringNotContainsString('Dela Cruz', json_encode($unparseable->getAttributes()));

        $after = $this->get("/api/imports/{$this->run->id}/errors.csv")->streamedContent();
        $this->assertStringNotContainsString('A-001', $after);
        $this->assertStringNotContainsString('Dela Cruz', $after);
    }
}
