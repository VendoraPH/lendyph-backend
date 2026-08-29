<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportFile;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Loan;
use App\Services\BorrowerPurgeService;
use App\Services\CsvImport\CsvImportRowRedactor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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
}
