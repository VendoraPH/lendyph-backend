<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportRun;
use App\Models\LoanProduct;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\StagesCsvImportRuns;

/**
 * The endpoint a browser polls for the whole length of an import.
 *
 * Two properties are load-bearing and neither is visible from the response
 * alone, so both are pinned here: the outcome counts come from ONE grouped
 * query no matter how many files or millions of rows the run has, and
 * `matched_existing` is its own number rather than being folded into `skipped`.
 */
class ImportRunStatusTest extends TestCase
{
    use StagesCsvImportRuns;

    private CsvImportRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        $this->run = $this->makeImportRun(['phase' => 'importing_loans']);
    }

    public function test_the_counts_come_from_one_grouped_query_not_one_per_file_or_outcome(): void
    {
        $customers = $this->makeImportFile($this->run, 'customers');
        $loans = $this->makeImportFile($this->run, 'loans');

        foreach (range(2, 6) as $line) {
            $this->stageRow($customers, $line, ['account_no' => "A-{$line}"], ['result' => 'imported']);
            $this->stageRow($loans, $line, $this->loanValues(['loan_no' => "L-{$line}"]), ['result' => 'imported']);
        }

        $rowQueries = [];

        DB::listen(function ($query) use (&$rowQueries): void {
            if (str_contains($query->sql, 'csv_import_rows')) {
                $rowQueries[] = $query->sql;
            }
        });

        $this->getJson("/api/imports/{$this->run->id}")->assertOk();

        $this->assertCount(
            1,
            $rowQueries,
            "The status poll must read csv_import_rows exactly once. It ran:\n".implode("\n", $rowQueries),
        );
        $this->assertStringContainsString('group by', strtolower($rowQueries[0]));
    }

    public function test_matched_existing_is_reported_separately_from_skipped(): void
    {
        $customers = $this->makeImportFile($this->run, 'customers');

        // The 44 members who already self-registered and are reused, not
        // duplicated. Reporting them as "skipped" tells an admin their data did
        // not land when it did — and the natural next move, re-uploading the
        // file to "fix" it, is the one action that can do real damage.
        foreach (range(1, 4) as $i) {
            $this->stageRow($customers, $i + 1, ['account_no' => "M-{$i}"], ['result' => 'matched_existing']);
        }

        $this->stageRow($customers, 6, ['account_no' => 'S-1'], ['result' => 'skipped']);
        $this->stageRow($customers, 7, ['account_no' => 'I-1'], ['result' => 'imported']);
        $this->stageRow($customers, 8, ['account_no' => 'D-1'], ['result' => 'already_imported']);
        $this->stageRow($customers, 9, ['account_no' => 'F-1'], ['result' => 'failed', 'status' => 'invalid', 'errors' => [['account_no', 'required', 'blank']]]);
        $this->stageRow($customers, 10, ['account_no' => 'P-1']);

        $counts = $this->getJson("/api/imports/{$this->run->id}")->json('data.files.customers.counts');

        $this->assertSame(4, $counts['matched_existing']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(1, $counts['imported']);
        $this->assertSame(1, $counts['already_imported']);
        $this->assertSame(1, $counts['failed']);
        // A NULL result is the resume marker: not decided yet.
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(9, $counts['total']);
        $this->assertSame(1, $counts['invalid']);
        $this->assertSame(8, $counts['valid']);

        $this->assertSame(
            $counts['total'],
            $counts['imported'] + $counts['matched_existing'] + $counts['already_imported']
                + $counts['skipped'] + $counts['failed'] + $counts['pending'],
            'Every row must land in exactly one outcome bucket.',
        );
    }

    public function test_staleness_is_measured_on_the_server_clock(): void
    {
        $this->makeImportFile($this->run, 'customers');

        // The UI renders "last advanced 5m ago". A browser computing that from
        // its own Date.now() reports whatever its clock is wrong by, so both the
        // reference instant and the elapsed seconds come from here.
        $this->travel(5)->minutes();

        $data = $this->getJson("/api/imports/{$this->run->id}")->json('data');

        $this->assertSame(now()->toIso8601String(), $data['server_time']);
        $this->assertSame(300, $data['seconds_since_last_advance']);
        $this->assertSame(
            $this->run->fresh()->updated_at->toIso8601String(),
            $data['last_advanced_at'],
            'last_advanced_at is the run row moving, which is what the importer touches as it works.',
        );

        $this->travel(5)->minutes();
        $this->assertSame(600, $this->getJson("/api/imports/{$this->run->id}")->json('data.seconds_since_last_advance'));
    }

    public function test_it_reports_the_chunks_a_half_finished_upload_still_owes(): void
    {
        $customers = $this->makeImportFile($this->run, 'customers', ['total_chunks' => 4, 'assembled_path' => null]);
        $loans = $this->makeImportFile($this->run, 'loans', ['total_chunks' => 3]);

        $this->receiveChunks($customers, [0, 2]);
        $this->receiveChunks($loans, [0, 1, 2]);

        // Keyed by kind, the shape CsvImportUploadService::runPayload()
        // publishes, so `files.customers` means the same thing whichever
        // endpoint answered.
        $files = $this->getJson("/api/imports/{$this->run->id}")->json('data.files');

        // Missing is the set complement of what arrived — the chunk row's
        // existence IS the record that the chunk arrived.
        $this->assertSame([1, 3], $files['customers']['missing_chunks']);
        $this->assertSame(2, $files['customers']['missing_chunk_count']);
        $this->assertSame(2, $files['customers']['received_chunks']);
        $this->assertFalse($files['customers']['assembled']);
        $this->assertFalse($files['customers']['missing_chunks_truncated']);

        $this->assertSame([], $files['loans']['missing_chunks']);
        $this->assertSame(0, $files['loans']['missing_chunk_count']);
        $this->assertTrue($files['loans']['assembled']);
    }

    public function test_an_assembled_file_never_reports_its_chunks_as_missing(): void
    {
        // Assembly DELETES the chunk rows. Deriving "missing" from that table
        // alone therefore reports every chunk of a finished upload as
        // outstanding, and a client that already succeeded is sent back to
        // re-upload 100 MiB. This is the rule the upload service owns and the
        // reason the status endpoint calls through to it.
        $customers = $this->makeImportFile($this->run, 'customers', [
            'total_chunks' => 200,
            'assembled_path' => 'imports/1/customers.csv',
        ]);

        $this->assertSame(0, $customers->chunks()->count(), 'Fixture reproduces a post-assembly file.');

        $file = $this->getJson("/api/imports/{$this->run->id}")->json('data.files.customers');

        $this->assertTrue($file['assembled']);
        $this->assertSame([], $file['missing_chunks']);
        $this->assertSame(0, $file['missing_chunk_count']);
        $this->assertSame(200, $file['received_chunks']);
    }

    public function test_a_cancelled_run_is_not_reported_as_waiting_on_a_product_mapping(): void
    {
        $loans = $this->makeImportFile($this->run, 'loans');
        $this->stageRow($loans, 2, $this->loanValues());

        $this->assertTrue($this->getJson("/api/imports/{$this->run->id}")->json('data.product_mapping_required'));

        // `cancelled` arrives with the cancel endpoint. A run that is over is
        // not waiting on anybody, and reporting otherwise puts a permanent
        // "action required" badge on an import nobody can act on.
        foreach (['cancelled', 'failed', 'completed'] as $phase) {
            $this->run->update(['phase' => $phase]);

            $data = $this->getJson("/api/imports/{$this->run->id}")->json('data');

            $this->assertTrue($data['is_closed'], "[{$phase}] must report as closed.");
            $this->assertFalse($data['product_mapping_required'], "[{$phase}] must not demand a mapping.");
        }
    }

    public function test_it_reports_whether_the_product_mapping_still_blocks_the_run(): void
    {
        $product = LoanProduct::factory()->create(['name' => 'Regular Loan']);
        $loans = $this->makeImportFile($this->run, 'loans');
        $this->stageRow($loans, 2, $this->loanValues());

        $this->assertTrue($this->getJson("/api/imports/{$this->run->id}")->json('data.product_mapping_required'));
        $this->assertFalse($this->getJson("/api/imports/{$this->run->id}")->json('data.product_mapping_confirmed'));

        $this->putJson("/api/imports/{$this->run->id}/product-mapping", ['Regular Loan' => $product->id])->assertOk();

        $data = $this->getJson("/api/imports/{$this->run->id}")->json('data');
        $this->assertFalse($data['product_mapping_required']);
        $this->assertTrue($data['product_mapping_confirmed']);
    }

    public function test_the_error_report_is_offered_as_soon_as_anything_is_staged(): void
    {
        $loans = $this->makeImportFile($this->run, 'loans');

        $this->assertFalse($this->getJson("/api/imports/{$this->run->id}")->json('data.error_report_available'));

        // A row with a WARNING and no error. Gating the download on the error
        // count would leave this admin never learning that a value in their file
        // was changed on the way in.
        $this->stageRow($loans, 2, $this->loanValues(), [
            'warnings' => [['contact_number', 'unusable', 'A second number was dropped.']],
        ]);

        $data = $this->getJson("/api/imports/{$this->run->id}")->json('data');
        $this->assertTrue($data['error_report_available']);
        $this->assertSame(0, $data['rows_with_errors']);
    }

    public function test_a_loan_officer_may_not_read_the_status(): void
    {
        $this->makeImportFile($this->run, 'customers');

        $this->actingAs($this->userWithRoleNamed('loan_officer'));

        $this->getJson("/api/imports/{$this->run->id}")->assertForbidden();
    }
}
