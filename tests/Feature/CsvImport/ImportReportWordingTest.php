<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportFile;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportStager;
use App\Services\CsvImport\ErrorReportBuilder;
use App\Services\CsvImport\ImportTick;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * The result report in words (finish run round 2, polish): a row with errors
 * is "not imported" because it failed the checks before import, never "at
 * staging", and a membership number another member holds is reported on its
 * own column, not on the whole row.
 */
class ImportReportWordingTest extends TestCase
{
    use BuildsCsvImports;

    private const NOT_IMPORTED = 'This row didn\'t pass the checks before import, so it wasn\'t imported. Its errors are listed by field.';

    public function test_a_row_with_errors_is_not_imported_in_words(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('W-001', ['birthdate' => 'sometime in May']),
            $this->customerRow('W-002'),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $row = $this->row($file, 2);
        $this->assertSame('invalid', $row->status);
        $this->assertSame('skipped', $row->result);
        $this->assertSame('invalid_row', $row->result_category);
        $this->assertSame(self::NOT_IMPORTED, $row->result_message);

        // Counted as skipped, never as a warning: the row was not imported.
        $skipped = $this->issue($row, $file, 'invalid_row');
        $this->assertSame(ErrorReportBuilder::SEVERITY_SKIPPED, $skipped['severity']);
        $this->assertSame(self::NOT_IMPORTED, $skipped['message']);
        $this->assertStringNotContainsStringIgnoringCase('staging', $skipped['message']);
    }

    public function test_a_rejected_row_whose_notes_are_gone_still_reads_in_words(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [$this->customerRow('W-003')]);
        $row = CsvImportRow::create([
            'csv_import_file_id' => $file->id,
            'line_number' => 2,
            'record_number' => 1,
            'raw' => [],
            'status' => 'invalid',
        ]);

        $issue = $this->issue($row, $file, 'invalid_row');

        $this->assertSame('This row didn\'t pass the checks before import.', $issue['message']);
    }

    public function test_a_taken_membership_number_is_reported_on_its_column(): void
    {
        $this->seedForImport();
        Borrower::factory()->create(['membership_number' => 'BC-0007']);

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('C-001', ['membership_number' => 'bc-0007']),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $row = $this->row($file, 2);
        $this->assertSame('failed', $row->result);

        $issue = $this->issue($row, $file, 'membership_number_taken');
        $this->assertSame(ErrorReportBuilder::SEVERITY_ERROR, $issue['severity']);
        $this->assertSame('membership_number', $issue['field']);
        $this->assertSame('Membership Number', $issue['field_label']);
        $this->assertSame('bc-0007', $issue['original_value']);
        $this->assertTrue(Str::contains($issue['message'], 'BC-0007 is already another member\'s'));
    }

    private function row(CsvImportFile $file, int $line): CsvImportRow
    {
        return CsvImportRow::query()->where('csv_import_file_id', $file->id)->where('line_number', $line)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function issue(CsvImportRow $row, CsvImportFile $file, string $category): array
    {
        $issues = array_values(array_filter(
            app(ErrorReportBuilder::class)->issuesFor($row, $file),
            fn (array $issue): bool => $issue['category'] === $category,
        ));

        $this->assertCount(1, $issues, "Expected one {$category} issue on line {$row->line_number}.");

        return $issues[0];
    }

    private function runToIdle(CsvImportRun $run, int $maxTicks = 200): ImportTick
    {
        $processor = new CsvImportProcessor;
        $tick = new ImportTick($run->phase, 0);

        for ($i = 0; $i < $maxTicks; $i++) {
            $tick = $processor->advance($run);

            if ($tick->idle) {
                return $tick;
            }

            $run->refresh();
        }

        $this->fail("The run did not settle within {$maxTicks} ticks.");
    }
}
