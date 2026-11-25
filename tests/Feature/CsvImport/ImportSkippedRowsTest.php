<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportRun;
use App\Services\CsvImport\CsvImportProcessor;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;
use Tests\Traits\StagesCsvImportRuns;

/**
 * The import result (finish run, round 5): a row that was not imported is
 * counted as skipped, never as a warning, and its notes say what would have
 * happened. A warning means the row WAS imported with something adjusted, and
 * imported rows keep their wording.
 */
class ImportSkippedRowsTest extends TestCase
{
    use BuildsCsvImports, StagesCsvImportRuns;

    public function test_a_row_that_was_not_imported_is_counted_as_skipped_never_as_a_warning(): void
    {
        $this->seedAndLoginAsImportAdmin();
        $run = $this->makeImportRun(['phase' => 'completed']);
        $members = $this->makeImportFile($run, 'customers');

        // Refused at the checks, and skipped by the import pass with its note.
        $this->stageRow($members, 2, ['account_no' => 'A-001', 'last_name' => 'Cruz', 'first_name' => 'Ana'], [
            'errors' => [['monthly_income', 'money_precision', '"1234.500" has more than 2 decimal places.']],
            'warnings' => [['pledge_amount', 'amount_above_app_maximum', 'Too large for the member form.']],
            'result' => 'skipped',
            'result_category' => 'invalid_row',
            'result_message' => 'This row didn\'t pass the checks before import, so it wasn\'t imported.',
        ]);
        // Imported, with something adjusted: the one real warning.
        $this->stageRow($members, 3, ['account_no' => 'A-002', 'last_name' => 'Reyes', 'first_name' => 'Ben'], [
            'warnings' => [['email', 'email_invalid', 'Not an email address.']],
            'result' => 'imported',
        ]);
        // Failed at import, still carrying a note from the checks.
        $this->stageRow($members, 4, ['account_no' => 'A-003', 'last_name' => 'Santos', 'first_name' => 'Cora'], [
            'warnings' => [['contact_number', 'contact_number_invalid', 'Not a contact number.']],
            'result' => 'failed',
            'result_category' => 'membership_number_taken',
            'result_message' => 'Membership Number 7 is already another member\'s.',
        ]);
        // Skipped for review, with no note of its own.
        $this->stageRow($members, 5, ['account_no' => 'A-004', 'last_name' => 'Lim', 'first_name' => 'Dan'], [
            'result' => 'skipped',
            'result_category' => 'ambiguous_match',
            'result_message' => 'More than one member matches this row.',
        ]);

        $body = $this->getJson("/api/imports/{$run->id}/errors?per_page=100")->assertOk()->json();
        $stats = $body['meta']['stats'];

        $this->assertSame(['error' => 2, 'skipped' => 4, 'warning' => 1], $stats['by_severity']);
        $this->assertSame($stats['total_issues'], array_sum($stats['by_severity']));

        $bySeverity = collect($body['data'])->groupBy('severity')->map(fn ($lines) => $lines->pluck('category')->sort()->values()->all());
        $this->assertSame(['email_invalid'], $bySeverity['warning']);
        $this->assertSame(
            ['ambiguous_match', 'amount_above_app_maximum', 'contact_number_invalid', 'invalid_row'],
            $bySeverity['skipped'],
        );

        // Each half can be read on its own, skipped included.
        $skipped = $this->getJson("/api/imports/{$run->id}/errors?severity=skipped&per_page=100")->assertOk()->json();
        $this->assertSame(['skipped'], collect($skipped['data'])->pluck('severity')->unique()->values()->all());
        $this->assertSame(3, $skipped['meta']['total'], 'Three rows were not imported and carry a skipped line.');

        $warnings = $this->getJson("/api/imports/{$run->id}/errors?severity=warning&per_page=100")->assertOk()->json();
        $this->assertSame(['A-002'], collect($warnings['data'])->pluck('account_no')->values()->all());
        $this->assertSame(1, $warnings['meta']['total']);

        $csv = $this->get("/api/imports/{$run->id}/errors.csv?severity=skipped")->assertOk()->streamedContent();
        $this->assertStringContainsString(',skipped,', $csv);
        $this->assertStringNotContainsString(',warning,', $csv);
    }

    public function test_a_staged_row_of_a_run_that_closed_before_it_was_imported_is_skipped(): void
    {
        $this->seedAndLoginAsImportAdmin();
        $run = $this->makeImportRun(['phase' => 'cancelled']);
        $members = $this->makeImportFile($run, 'customers');

        $this->stageRow($members, 2, ['account_no' => 'A-001', 'last_name' => 'Cruz', 'first_name' => 'Ana'], [
            'warnings' => [['email', 'email_invalid', 'Not an email address.']],
        ]);

        $stats = $this->getJson("/api/imports/{$run->id}/errors")->assertOk()->json('meta.stats');

        $this->assertSame(['error' => 0, 'skipped' => 1, 'warning' => 0], $stats['by_severity']);

        // While a run is still going, the same row will be imported: its note stays a warning.
        $run->update(['phase' => 'importing_customers']);
        $this->assertSame(
            ['error' => 0, 'skipped' => 0, 'warning' => 1],
            $this->getJson("/api/imports/{$run->id}/errors")->json('meta.stats.by_severity'),
        );
    }

    public function test_a_skipped_rows_notes_say_what_would_have_happened_and_imported_rows_keep_their_wording(): void
    {
        $this->seedForImport();
        $this->actingAs($this->admin);

        $run = $this->makeRun();
        $this->makeFile($run, 'customers', [
            // Imported, with its email blanked.
            $this->customerRow('A-001', ['email' => 'juana-at-example', 'gender' => 'Babaye']),
            // Refused at the checks (an amount with three decimals), so never imported.
            $this->customerRow('A-002', [
                'email' => 'pedro-at-example',
                'gender' => 'Babaye',
                'contact_number' => '12345',
                'monthly_income' => '1234.500',
                'pledge_amount' => '10,000,000.00',
            ]),
        ]);

        $this->runToIdle($run);

        $lines = collect($this->getJson("/api/imports/{$run->id}/errors?per_page=100")->assertOk()->json('data'));
        $imported = $lines->where('account_no', 'A-001')->keyBy('category');
        $skipped = $lines->where('account_no', 'A-002')->where('severity', 'skipped')->keyBy('category');

        // Imported rows keep their wording.
        $this->assertSame('warning', $imported['email_invalid']['severity']);
        $this->assertStringContainsString('so it was imported as blank', $imported['email_invalid']['message']);
        $this->assertStringContainsString('so it was imported as blank', $imported['enum_unmapped']['message']);

        // A row that was not imported says what would have happened.
        $this->assertSame(['amount_above_app_maximum', 'contact_number_invalid', 'email_invalid', 'enum_unmapped', 'invalid_row'], $skipped->keys()->sort()->values()->all());

        foreach (['amount_above_app_maximum', 'contact_number_invalid', 'email_invalid', 'enum_unmapped'] as $category) {
            $message = $skipped[$category]['message'];
            $this->assertStringContainsString('would be imported as blank', $message, $category);
            $this->assertStringNotContainsString('was imported', $message, $category);
        }

        // The downloaded report reads the same, titles included: a skipped
        // row's title never says the value was left blank.
        $csv = $this->get("/api/imports/{$run->id}/errors.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString(',skipped,"Value not recognised",', $csv);
        $this->assertStringContainsString(',warning,"Value not recognised, left blank",', $csv);
        $this->assertStringNotContainsString(',skipped,"Value not recognised, left blank"', $csv);
        $this->assertStringContainsString('"""pedro-at-example"" is not an email address this system accepts, so it would be imported as blank.', $csv);
        $this->assertStringContainsString('"""juana-at-example"" is not an email address this system accepts, so it was imported as blank.', $csv);
    }

    private function runToIdle(CsvImportRun $run): void
    {
        $processor = new CsvImportProcessor;

        for ($tick = 0; $tick < 200 && ! $processor->advance($run)->idle; $tick++) {
            $run->refresh();
        }

        $this->assertSame('completed', $run->fresh()->phase);
    }
}
