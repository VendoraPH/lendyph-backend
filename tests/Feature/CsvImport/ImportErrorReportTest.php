<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportRun;
use Tests\TestCase;
use Tests\Traits\StagesCsvImportRuns;

/**
 * The report an admin fixes their spreadsheet from.
 *
 * Three things are easy to get wrong here and all three are silent: printing a
 * row number the admin's spreadsheet does not show, shipping errors without the
 * warnings beside them, and echoing the coop's own cells back into a file they
 * open in Excel without neutralising the ones that begin like a formula.
 */
class ImportErrorReportTest extends TestCase
{
    use StagesCsvImportRuns;

    private CsvImportRun $run;

    private CsvImportFile $loans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        $this->run = $this->makeImportRun(['phase' => 'importing_loans']);
        $this->loans = $this->makeImportFile($this->run, 'loans', ['original_filename' => 'binhs-loans-2026.csv']);
    }

    private function csv(string $query = ''): string
    {
        $response = $this->get("/api/imports/{$this->run->id}/errors.csv{$query}");
        $response->assertOk();

        return $response->streamedContent();
    }

    public function test_it_paginates_and_caps_per_page_at_one_hundred(): void
    {
        foreach (range(2, 121) as $line) {
            $this->stageRow($this->loans, $line, $this->loanValues(['loan_no' => "L-{$line}"]), [
                'errors' => [['interest_rate', 'rate_invalid', 'Not a rate.']],
            ]);
        }

        $meta = $this->getJson("/api/imports/{$this->run->id}/errors?per_page=1000")->assertOk()->json('meta');

        $this->assertSame(100, $meta['per_page'], 'per_page must be clamped at 100.');
        $this->assertSame(120, $meta['total']);
        $this->assertSame(2, $meta['last_page']);
        $this->assertSame(100, $meta['rows_on_page']);

        $second = $this->getJson("/api/imports/{$this->run->id}/errors?per_page=1000&page=2")->json();
        $this->assertCount(20, $second['data']);

        // The summary is a run-wide scan and a header block the client already
        // has from page one. Recomputing it per page re-scans the table for an
        // answer that has not changed.
        $this->assertNull($second['meta']['stats']);
        $this->assertTrue($second['meta']['stats_omitted']);
    }

    public function test_the_summary_counts_stay_exact_when_the_wordings_are_too_many_to_hold(): void
    {
        // One category, sixty distinct messages — the shape ValueNormalizer
        // produces, since its messages interpolate the offending cell. Holding
        // one array entry per distinct bad cell is how a real 400,000-row
        // migration file exhausts memory_limit, which is fatal rather than
        // catchable.
        foreach (range(1, 60) as $i) {
            $this->stageRow($this->loans, $i + 1, $this->loanValues(['loan_no' => "L-{$i}"]), [
                'errors' => [['contact_number', 'unusable', "\"0917{$i}\" is not a usable contact number."]],
            ]);
        }

        $group = $this->getJson("/api/imports/{$this->run->id}/errors")->json('meta.stats.by_category.0');

        $this->assertSame(60, $group['count'], 'Counting stays exact; only the sample of wordings is capped.');
        $this->assertSame(50, $group['distinct_messages']);
        $this->assertTrue($group['distinct_messages_truncated']);
        $this->assertSame(60, $this->getJson("/api/imports/{$this->run->id}/errors")->json('meta.stats.total_issues'));
    }

    public function test_the_grouped_summary_matches_the_rows_it_summarises(): void
    {
        // The line an admin actually acts on: one reason, one count.
        foreach (range(2, 4) as $line) {
            $this->stageRow($this->loans, $line, $this->loanValues(['loan_no' => "P-{$line}"]), [
                'result' => 'failed',
                'result_category' => 'product_unmapped',
                'result_message' => "Loan Product 'Regular' could not be resolved.",
            ]);
        }

        foreach (range(5, 6) as $line) {
            $this->stageRow($this->loans, $line, $this->loanValues(['loan_no' => "R-{$line}"]), [
                'errors' => [['interest_rate', 'rate_invalid', '"abc" is not a valid interest rate.']],
            ]);
        }

        foreach (range(7, 10) as $line) {
            $this->stageRow($this->loans, $line, $this->loanValues(['loan_no' => "W-{$line}"]), [
                'warnings' => [['purpose', 'truncated', 'Purpose was shortened to fit.']],
            ]);
        }

        // One row, two issues — the reason the report's unit is the issue and
        // the paginator's unit is the row, and both numbers are published.
        $this->stageRow($this->loans, 11, $this->loanValues(['loan_no' => 'B-11']), [
            'errors' => [['maturity_date', 'date_invalid', 'Not a date.']],
            'warnings' => [['contact_number', 'unusable', 'Number dropped.']],
        ]);

        // Rows with nothing to say must not appear at all.
        $this->stageRow($this->loans, 12, $this->loanValues(['loan_no' => 'OK-12']), ['result' => 'imported']);

        $body = $this->getJson("/api/imports/{$this->run->id}/errors?per_page=100")->assertOk()->json();
        $stats = $body['meta']['stats'];

        $this->assertSame(11, $stats['total_issues'], '3 failures + 2 errors + 4 warnings + 1 row carrying both.');
        $this->assertSame(10, $stats['rows_reported']);
        $this->assertSame(10, $body['meta']['total'], 'meta.total counts reported ROWS.');
        $this->assertCount(11, $body['data'], 'data carries one entry per issue.');
        $this->assertSame(11, $body['meta']['issues_on_page']);

        $this->assertSame(['error' => 6, 'warning' => 5], $stats['by_severity']);
        $this->assertSame(
            $stats['total_issues'],
            array_sum(array_column($stats['by_category'], 'count')),
            'The grouped summary must account for every issue and no more.',
        );
        $this->assertSame($stats['total_issues'], array_sum($stats['by_severity']));

        $groups = collect($stats['by_category'])->keyBy('category');
        $this->assertSame(3, $groups['product_unmapped']['count']);
        $this->assertSame("Loan Product 'Regular' could not be resolved.", $groups['product_unmapped']['label']);
        $this->assertSame(4, $groups['truncated']['count']);

        // The CSV is the same dataset, one line per issue.
        $lines = array_values(array_filter(explode("\n", trim($this->csv()))));
        $this->assertCount(12, $lines, 'Header plus one line per issue.');
    }

    public function test_it_carries_warnings_as_well_as_errors(): void
    {
        $this->stageRow($this->loans, 2, $this->loanValues(['loan_no' => 'E-1']), [
            'errors' => [['loan_amount', 'money_invalid', 'Not an amount.']],
        ]);

        // No error anywhere on this row. An admin who only receives errors never
        // learns that a phone number was blanked or a value was rewritten.
        $this->stageRow($this->loans, 3, $this->loanValues(['loan_no' => 'W-1']), [
            'warnings' => [['contact_number', 'unusable', 'The second number was dropped.']],
        ]);

        $data = collect($this->getJson("/api/imports/{$this->run->id}/errors")->json('data'));

        $this->assertSame(['error', 'warning'], $data->pluck('severity')->unique()->sort()->values()->all());
        $this->assertStringContainsString('dropped', $this->csv());

        // And each half can be isolated when the admin wants only one.
        $this->assertSame(
            ['warning'],
            collect($this->getJson("/api/imports/{$this->run->id}/errors?severity=warning")->json('data'))
                ->pluck('severity')->unique()->values()->all(),
        );
    }

    public function test_the_row_number_is_the_physical_line_the_spreadsheet_shows(): void
    {
        // Physical line 4,813 — the third data record of a file with a header.
        // `record_number` is 4,812, and telling the admin to fix that row sends
        // them to the wrong line.
        $row = $this->stageRow($this->loans, 4813, $this->loanValues(['account_no' => 'A-77', 'loan_no' => '2019-0007']), [
            'errors' => [['maturity_date', 'date_invalid', 'Not a date.']],
        ]);

        $this->assertSame(4812, $row->record_number);

        $issue = $this->getJson("/api/imports/{$this->run->id}/errors")->json('data.0');

        $this->assertSame(4813, $issue['row_number']);
        $this->assertSame('binhs-loans-2026.csv', $issue['file']);
        $this->assertSame('A-77', $issue['account_no']);
        $this->assertSame('2019-0007', $issue['loan_no']);

        $csv = $this->csv();
        $this->assertStringContainsString(
            'File,"Row Number","Account No.","Loan No.",Severity,Category,Field,Message,"Original Value"',
            $csv,
        );
        $this->assertStringContainsString('binhs-loans-2026.csv,4813,A-77,2019-0007,error,date_invalid,"Maturity Date"', $csv);
        $this->assertStringNotContainsString(',4812,', $csv);
    }

    public function test_a_cell_that_begins_like_a_formula_is_neutralised_in_both_original_value_and_message(): void
    {
        /**
         * Original Value echoes the coop's own cell into a file they open in
         * Excel, and Message interpolates that same cell — so sanitising one
         * column and not the other reintroduces through the second exactly what
         * the first closed. All four leading characters Excel treats as a
         * formula are covered.
         */
        $this->stageRow(
            $this->loans,
            2,
            $this->loanValues(['loan_no' => 'X-1']),
            [
                'raw' => [
                    'account_no' => 'A-001',
                    'loan_no' => 'X-1',
                    'interest_rate' => '=SUM(A1:A9)',
                    'interest_amount' => '+1+1',
                    'purpose' => '@INDIRECT(Z9)',
                    'other_fee_detail' => '-2-2',
                ],
                'errors' => [
                    ['interest_rate', 'rate_invalid', '=SUM(A1:A9) is not a valid interest rate.'],
                    ['interest_amount', 'money_invalid', '+1+1 is not a valid amount.'],
                ],
                'warnings' => [
                    ['purpose', 'truncated', '@INDIRECT(Z9) was shortened to fit.'],
                    ['other_fee_detail', 'truncated', '-2-2 was shortened to fit.'],
                ],
            ],
        );

        $csv = $this->csv();

        foreach (['=SUM(A1:A9)', '+1+1', '@INDIRECT(Z9)', '-2-2'] as $payload) {
            // Once in Message, once in Original Value — both prefixed.
            $this->assertSame(
                2,
                substr_count($csv, "'\t".$payload),
                "[{$payload}] must be neutralised in BOTH the Message and Original Value columns.",
            );

            $this->assertSame(
                0,
                substr_count($csv, ','.$payload),
                "[{$payload}] must never reach a cell unprefixed.",
            );
        }

        // The JSON view is not opened in Excel and reports the cell verbatim,
        // which is what an admin searching their file needs.
        $issues = collect($this->getJson("/api/imports/{$this->run->id}/errors")->json('data'));
        $this->assertSame('=SUM(A1:A9)', $issues->firstWhere('category', 'rate_invalid')['original_value']);
    }

    public function test_it_never_writes_the_report_to_disk(): void
    {
        $this->stageRow($this->loans, 2, $this->loanValues(), [
            'errors' => [['loan_amount', 'money_invalid', 'Not an amount.']],
        ]);

        $before = $this->storageSnapshot();
        $this->csv();

        $this->assertSame($before, $this->storageSnapshot(), 'The report streams; it must not leave a file behind.');
    }

    /**
     * @return list<string>
     */
    private function storageSnapshot(): array
    {
        $files = [];
        $root = storage_path('app');

        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_a_loan_officer_may_not_read_either_report_endpoint(): void
    {
        $this->stageRow($this->loans, 2, $this->loanValues(), [
            'errors' => [['loan_amount', 'money_invalid', 'Not an amount.']],
        ]);

        $this->actingAs($this->userWithRoleNamed('loan_officer'));

        $this->getJson("/api/imports/{$this->run->id}/errors")->assertForbidden();
        $this->get("/api/imports/{$this->run->id}/errors.csv")->assertForbidden();
    }
}
