<?php

namespace Tests\Feature\CsvImport;

use App\Services\CsvImport\CsvFileRejectedException;
use App\Services\CsvImport\CsvImportReader;
use App\Services\CsvImport\CsvImportSchema;
use Tests\TestCase;

/**
 * Every case here is a way a coop's export has silently produced a wrong
 * import. None of them throws on its own — they all parse "successfully" and
 * hand back nonsense, which is exactly why each one needs a test.
 */
class CsvImportReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function writeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csvimport');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<int, string>  $overrides
     */
    private function customerRow(array $overrides = [], string $delimiter = ','): string
    {
        $cells = array_fill(0, CsvImportSchema::width(CsvImportSchema::CUSTOMERS), '');
        $cells[0] = 'A-001';
        $cells[1] = 'Dela Cruz';
        $cells[2] = 'Juan';

        foreach ($overrides as $index => $value) {
            $cells[$index] = $value;
        }

        return implode($delimiter, $cells);
    }

    private function customerHeader(string $delimiter = ','): string
    {
        return implode($delimiter, CsvImportSchema::labels(CsvImportSchema::CUSTOMERS));
    }

    public function test_it_strips_a_utf8_bom_so_the_account_number_is_not_a_different_string(): void
    {
        $path = $this->writeFile("\xEF\xBB\xBF".$this->customerRow()."\n");

        $result = (new CsvImportReader)->read($path, CsvImportSchema::CUSTOMERS);
        $rows = iterator_to_array($result->rows());

        $this->assertSame('A-001', $rows[0]->cell(0));
        // The bytes matter, not just the rendering: "\u{FEFF}A-001" and "A-001"
        // look identical in every UI and would file this member's loans under
        // an account number nothing else in the import uses.
        $this->assertSame(5, strlen((string) $rows[0]->cell(0)));
    }

    public function test_a_bom_prefixed_header_is_still_recognised_as_a_header(): void
    {
        $path = $this->writeFile("\xEF\xBB\xBF".$this->customerHeader()."\n".$this->customerRow()."\n");

        $result = (new CsvImportReader)->read($path, CsvImportSchema::CUSTOMERS);

        $this->assertTrue($result->hasHeader);
        $this->assertCount(1, iterator_to_array($result->rows()));
    }

    public function test_it_converts_windows_1252_cells_and_notes_it_once_for_the_run(): void
    {
        // "Peña" as Excel-on-Windows writes it: 0xF1 is a lone continuation
        // byte in UTF-8 and MySQL refuses the INSERT outright.
        $windows1252LastName = "Pe\xF1a";
        $body = $this->customerRow([1 => $windows1252LastName])."\n"
            .$this->customerRow([0 => 'A-002', 1 => "Mu\xF1oz"])."\n";

        $result = (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
        $rows = iterator_to_array($result->rows());

        $this->assertSame('Peña', $rows[0]->cell(1));
        $this->assertSame('Muñoz', $rows[1]->cell(1));
        $this->assertTrue(mb_check_encoding((string) $rows[0]->cell(1), 'UTF-8'));
        $this->assertCount(1, $result->notes(), 'The encoding note belongs to the run, not to each cell.');
        $this->assertStringContainsString('Windows-1252', $result->notes()[0]);
    }

    public function test_it_detects_a_semicolon_delimited_file(): void
    {
        $body = $this->customerHeader(';')."\n".$this->customerRow([], ';')."\n";

        $result = (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
        $rows = iterator_to_array($result->rows());

        $this->assertSame(';', $result->delimiter);
        // Read as commas this file is one column wide, which is both wrong and
        // indistinguishable from a corrupt upload.
        $this->assertSame('A-001', $rows[0]->cell(0));
        $this->assertSame('Juan', $rows[0]->cell(2));
    }

    public function test_it_detects_a_tab_delimited_file(): void
    {
        $body = $this->customerRow([], "\t")."\n";

        $result = (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);

        $this->assertSame("\t", $result->delimiter);
        $this->assertSame('Dela Cruz', iterator_to_array($result->rows())[0]->cell(1));
    }

    public function test_it_rejects_a_utf16_file_whole_rather_than_row_by_row(): void
    {
        $path = $this->writeFile("\xFF\xFE".mb_convert_encoding($this->customerRow(), 'UTF-16LE', 'UTF-8'));

        try {
            (new CsvImportReader)->read($path, CsvImportSchema::CUSTOMERS);
            $this->fail('A UTF-16 file must be rejected as a file.');
        } catch (CsvFileRejectedException $e) {
            $this->assertSame('utf16_encoding', $e->reasonCode);
            $this->assertStringContainsString('CSV UTF-8', $e->getMessage());
        }
    }

    public function test_it_rejects_carriage_return_only_line_endings_by_name(): void
    {
        // auto_detect_line_endings was removed in PHP 8.1, so fgetcsv sees no
        // terminator and the entire file comes back as one record.
        $body = $this->customerHeader()."\r".$this->customerRow()."\r";

        try {
            (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
            $this->fail('A CR-only file must be rejected.');
        } catch (CsvFileRejectedException $e) {
            $this->assertSame('cr_only_line_endings', $e->reasonCode);
            $this->assertStringContainsString('classic Mac', $e->getMessage());
        }
    }

    public function test_windows_crlf_endings_are_perfectly_normal(): void
    {
        $body = $this->customerHeader()."\r\n".$this->customerRow()."\r\n";

        $result = (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);

        $this->assertCount(1, iterator_to_array($result->rows()));
    }

    public function test_a_wrong_column_count_rejects_the_file_not_the_row(): void
    {
        $short = implode(',', array_fill(0, CsvImportSchema::width(CsvImportSchema::CUSTOMERS) - 1, 'x'));
        $body = str_repeat($short."\n", 5);

        try {
            (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
            $this->fail('A file of the wrong width must be rejected outright.');
        } catch (CsvFileRejectedException $e) {
            // Importing this row by row would produce a complete, plausible
            // import with every value one column out of place — Birthdate into
            // Gender, Pledge Amount into Spouse First Name. Nothing downstream
            // can detect that, so the file never gets to start.
            $this->assertSame('unexpected_column_count', $e->reasonCode);
            $this->assertStringContainsString('21 columns', $e->getMessage());
            $this->assertStringContainsString('22', $e->getMessage());
        }
    }

    public function test_a_header_in_the_wrong_order_rejects_the_file(): void
    {
        $labels = CsvImportSchema::labels(CsvImportSchema::CUSTOMERS);
        [$labels[5], $labels[6]] = [$labels[6], $labels[5]];

        $body = implode(',', $labels)."\n".$this->customerRow()."\n";

        try {
            (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
            $this->fail('A header whose columns are in the wrong order must be rejected.');
        } catch (CsvFileRejectedException $e) {
            // The width check passes here — the count is right, the ORDER is
            // not — so the label sequence is the only thing standing between
            // the coop and every birthdate importing as a gender.
            $this->assertSame('header_mismatch', $e->reasonCode);
            $this->assertStringContainsString('Column 6', $e->getMessage());
        }
    }

    public function test_it_accepts_a_headerless_file_and_common_header_spellings(): void
    {
        $headerless = (new CsvImportReader)->read($this->writeFile($this->customerRow()."\n"), CsvImportSchema::CUSTOMERS);
        $this->assertFalse($headerless->hasHeader);
        $this->assertCount(1, iterator_to_array($headerless->rows()));

        $labels = CsvImportSchema::labels(CsvImportSchema::CUSTOMERS);
        $labels[0] = 'ACCOUNT NO.';
        $labels[1] = 'Surname';
        $labels[5] = 'Date of Birth';

        $withAliases = (new CsvImportReader)->read(
            $this->writeFile(implode(',', $labels)."\n".$this->customerRow()."\n"),
            CsvImportSchema::CUSTOMERS,
        );

        $this->assertTrue($withAliases->hasHeader);
        $this->assertCount(1, iterator_to_array($withAliases->rows()));
    }

    public function test_it_skips_blank_rows_and_numbers_data_rows_from_one(): void
    {
        $body = $this->customerHeader()."\n"
            .$this->customerRow()."\n"
            ."\n"
            .$this->customerRow([0 => 'A-002'])."\n";

        $result = (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
        $rows = iterator_to_array($result->rows());

        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_map(fn ($row) => $row->rowNumber, $rows));
        $this->assertSame('A-002', $rows[1]->cell(0));
    }

    public function test_it_passes_an_explicit_escape_so_a_backslash_survives(): void
    {
        // PHP 8.4 deprecates fgetcsv's historical "\\" escape default, which is
        // not RFC 4180 and eats the backslash in a Philippine address.
        $body = $this->customerRow([8 => '123 Main St \\ Unit 4'])."\n";

        $result = (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);

        $this->assertSame('123 Main St \\ Unit 4', iterator_to_array($result->rows())[0]->cell(8));
    }

    public function test_a_quoted_value_containing_the_delimiter_stays_one_cell(): void
    {
        // The commonest real-world case: money formatted with thousands
        // separators. Unquoted, "70,000.07" is two cells and every field after
        // it shifts left by one.
        $cells = array_fill(0, CsvImportSchema::width(CsvImportSchema::CUSTOMERS), '');
        $cells[0] = 'A-001';
        $cells[1] = 'Dela Cruz';
        $cells[2] = 'Juan';
        $cells[8] = '"123 Main St, Unit 4"';
        $cells[16] = '"70,000.07"';

        $result = (new CsvImportReader)->read($this->writeFile(implode(',', $cells)."\n"), CsvImportSchema::CUSTOMERS);
        $row = iterator_to_array($result->rows())[0];

        $this->assertCount(22, $row->cells);
        $this->assertSame('123 Main St, Unit 4', $row->cell(8));
        $this->assertSame('70,000.07', $row->cell(16));
    }

    public function test_rows_of_inconsistent_width_reject_the_file_even_when_the_modal_width_is_right(): void
    {
        // Half the rows the right width and half not is not a file with some
        // bad rows — it is a file with no reliable shape, usually an unquoted
        // separator inside a value. There is no basis for deciding which rows
        // are the correct ones, so none of them is imported.
        $good = $this->customerRow();
        $shifted = $good.',extra';

        $body = implode("\n", [$good, $shifted, $good, $shifted])."\n";

        try {
            (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
            $this->fail('A file whose rows disagree about their width must be rejected.');
        } catch (CsvFileRejectedException $e) {
            $this->assertSame('inconsistent_column_count', $e->reasonCode);
            $this->assertStringContainsString('unquoted separator', $e->getMessage());
        }
    }

    public function test_one_bad_row_among_many_good_ones_does_not_reject_the_file(): void
    {
        // The counterpart to the test above: a clear majority at the expected
        // width means the file IS that shape, and the single odd row is a row
        // problem for the normalizer to report, not grounds for refusing a
        // whole migration.
        $body = str_repeat($this->customerRow()."\n", 9).$this->customerRow().",extra\n";

        $result = (new CsvImportReader)->read($this->writeFile($body), CsvImportSchema::CUSTOMERS);
        $rows = iterator_to_array($result->rows());

        $this->assertCount(10, $rows);
        $this->assertCount(23, $rows[9]->cells, 'The odd row is still handed over, for the normalizer to fail.');
    }

    public function test_the_row_stream_refuses_to_be_read_twice(): void
    {
        // A second pass over an exhausted handle yields nothing, and a silent
        // empty import is much worse than an exception.
        $result = (new CsvImportReader)->read($this->writeFile($this->customerRow()."\n"), CsvImportSchema::CUSTOMERS);

        $this->assertCount(1, iterator_to_array($result->rows()));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/only be read once/');

        iterator_to_array($result->rows());
    }

    /**
     * The header line exactly as the client's workbook holds it, transcribed
     * from sharedStrings.xml — leading spaces, parenthetical qualifiers,
     * inconsistent bracket spacing and the lowercase `email` all intact.
     *
     * Written out literally rather than built from CsvImportSchema, because a
     * test that builds its fixture from the thing under test proves only that
     * the thing agrees with itself.
     *
     * @return list<string>
     */
    private function workbookCustomerHeader(): array
    {
        return [
            'Account No.', ' Last Name', 'First Name', 'Middle Name', 'Suffix', 'Birthdate', 'Gender',
            'Civil Status', 'Contact Number', 'email', 'Street Address', 'Barangay', 'City/Municipality',
            '  Province', 'Employer/Business Name', 'Monthly Income', 'Pledge Amt(If Applicable)',
            'Spouse FName (If Married)', 'Spouse MName (If Married)', 'Spouse LName (If Married)',
            'Spouse Contact No (If Married)', 'Spouse Occupation (If Married)',
        ];
    }

    /**
     * @return list<string>
     */
    private function workbookLoanHeader(): array
    {
        return [
            'Account No.', 'Loan No.', 'Loan Amount', 'Loan Balance', 'Interest Rate', 'Interest Amount',
            'Interest Balance', 'Purpose', 'Loan Product', 'Term in Months', 'Payment Frequency',
            'Interest Type', 'Date Released', 'Maturity Date', 'Processing Fee', 'Service Fee',
            'Other Fee Detail', 'Other Fee Amount',
        ];
    }

    public function test_the_real_workbook_header_is_accepted_verbatim(): void
    {
        // Leading spaces on " Last Name" and "  Province", no space before the
        // bracket in "Pledge Amt(If Applicable)" but a space in the spouse
        // ones, and a lowercase "email" among title-case neighbours. All of it
        // has to pass, because all of it is what the client will upload.
        $header = $this->workbookCustomerHeader();

        $this->assertCount(22, $header);

        $result = (new CsvImportReader)->read(
            $this->writeFile(implode(',', $header)."\n".$this->customerRow()."\n"),
            CsvImportSchema::CUSTOMERS,
        );

        $this->assertTrue($result->hasHeader);
        $this->assertCount(1, iterator_to_array($result->rows()));
    }

    public function test_the_real_loans_workbook_header_is_accepted_verbatim(): void
    {
        $header = $this->workbookLoanHeader();

        $this->assertCount(18, $header);

        $cells = array_fill(0, 18, '');
        $cells[0] = 'A-001';
        $cells[1] = 'L-0001';

        $result = (new CsvImportReader)->read(
            $this->writeFile(implode(',', $header)."\n".implode(',', $cells)."\n"),
            CsvImportSchema::LOANS,
        );

        $this->assertTrue($result->hasHeader);
        $this->assertCount(1, iterator_to_array($result->rows()));
    }

    public function test_the_declared_schema_matches_the_workbook_label_for_label(): void
    {
        // Compared on labelKey(), which is how the reader compares them — so
        // this asserts the contract that actually governs, not the cosmetics.
        foreach ([
            [CsvImportSchema::CUSTOMERS, $this->workbookCustomerHeader()],
            [CsvImportSchema::LOANS, $this->workbookLoanHeader()],
        ] as [$shape, $workbook]) {
            $declared = array_map(
                static fn (string $label): string => CsvImportSchema::labelKey($label),
                CsvImportSchema::labels($shape),
            );
            $actual = array_map(
                static fn (string $label): string => CsvImportSchema::labelKey($label),
                $workbook,
            );

            $this->assertSame($actual, $declared, "The declared [{$shape}] labels have drifted from the workbook.");
        }
    }

    public function test_label_matching_ignores_spacing_and_punctuation_entirely(): void
    {
        // "Pledge Amt(If Applicable)" and "Pledge Amt (If Applicable)" differ
        // by one space. Rejecting a coop's migration over that would be absurd,
        // so the comparison key strips punctuation and whitespace outright.
        $this->assertSame(
            CsvImportSchema::labelKey('Pledge Amt(If Applicable)'),
            CsvImportSchema::labelKey('Pledge Amt (If Applicable)'),
        );
        $this->assertSame(CsvImportSchema::labelKey('  Province'), CsvImportSchema::labelKey('PROVINCE'));
        $this->assertSame(CsvImportSchema::labelKey('Account No.'), CsvImportSchema::labelKey('account_no'));
        $this->assertSame(CsvImportSchema::labelKey('City/Municipality'), CsvImportSchema::labelKey('City / Municipality'));

        // But different words are still different columns.
        $this->assertNotSame(CsvImportSchema::labelKey('Loan Amount'), CsvImportSchema::labelKey('Loan Balance'));
        $this->assertNotSame(CsvImportSchema::labelKey('Interest Rate'), CsvImportSchema::labelKey('Interest Amount'));
    }

    public function test_the_declared_shapes_are_22_and_18_columns(): void
    {
        // The width is DERIVED from CsvImportSchema, so this is what stops a
        // column being added to the declaration without anyone noticing that
        // every existing positional file now fails.
        $this->assertSame(22, CsvImportSchema::width(CsvImportSchema::CUSTOMERS));
        $this->assertSame(18, CsvImportSchema::width(CsvImportSchema::LOANS));
        $this->assertCount(22, array_unique(CsvImportSchema::keys(CsvImportSchema::CUSTOMERS)));
        $this->assertCount(18, array_unique(CsvImportSchema::keys(CsvImportSchema::LOANS)));
    }
}
