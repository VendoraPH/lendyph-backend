<?php

namespace Tests\Feature\CsvImport;

use App\Services\CsvImport\CsvImportReader;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CustomerRowNormalizer;
use App\Services\CsvImport\LoanReconstructionInput;
use App\Services\CsvImport\LoanRowNormalizer;
use App\Services\CsvImport\LoanScheduleReconstructor;
use Tests\TestCase;

/**
 * The three stages composed end to end, from bytes on disk to a schedule.
 *
 * The unit tests each pin one trap; this one proves the pieces actually fit
 * together — the reader's cells are positionally what the normalizers expect,
 * and the normalizers' output is what the reconstructor consumes.
 */
class CsvImportPipelineTest extends TestCase
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
        $path = tempnam(sys_get_temp_dir(), 'csvpipeline');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function line(string $shape, array $values, string $delimiter = ','): string
    {
        $cells = array_fill(0, CsvImportSchema::width($shape), '');

        foreach ($values as $key => $value) {
            $cells[CsvImportSchema::indexOf($shape, $key)] = $value;
        }

        // Quote like a real export does: a money cell holding "70,000.07" that
        // is not quoted is not one cell, it is two, and the whole row shifts.
        return implode($delimiter, array_map(
            static fn (string $cell): string => str_contains($cell, $delimiter) || str_contains($cell, '"')
                ? '"'.str_replace('"', '""', $cell).'"'
                : $cell,
            $cells,
        ));
    }

    public function test_a_realistic_customer_file_reads_and_normalises_end_to_end(): void
    {
        // A BOM, Windows-1252 bytes, semicolon separators, CRLF endings, a
        // peso-signed amount, an NBSP and an unusable phone number — i.e. one
        // Excel-on-Windows export.
        $header = implode(';', CsvImportSchema::labels(CsvImportSchema::CUSTOMERS));
        $good = $this->line(CsvImportSchema::CUSTOMERS, [
            'account_no' => 'A-001',
            'last_name' => "Pe\xF1a",
            'first_name' => "Juan\u{00A0}Carlos",
            'birthdate' => '03/04/1985',
            'gender' => 'M',
            'civil_status' => 'MARRIED',
            'contact_number' => '09171234567 / 09281234567',
            'email' => 'juan@example.com',
            'monthly_income' => '₱25,000.50',
            'pledge_amount' => '',
        ], ';');
        $messy = $this->line(CsvImportSchema::CUSTOMERS, [
            'account_no' => 'A-002',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'birthdate' => '',
            'civil_status' => 'maried',
            'contact_number' => 'none',
            'email' => 'not-an-email',
        ], ';');
        $broken = $this->line(CsvImportSchema::CUSTOMERS, [
            'account_no' => '',
            'last_name' => 'Reyes',
            'first_name' => 'Pedro',
            'birthdate' => '13/45/2020',
        ], ';');

        $path = $this->writeFile("\xEF\xBB\xBF".implode("\r\n", [$header, $good, $messy, $broken])."\r\n");

        $result = (new CsvImportReader)->read($path, CsvImportSchema::CUSTOMERS);
        $normalizer = new CustomerRowNormalizer;
        $rows = array_map(fn ($row) => $normalizer->normalize($row), iterator_to_array($result->rows()));

        $this->assertSame(';', $result->delimiter);
        $this->assertTrue($result->hasHeader);
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('Windows-1252', $result->notes()[0] ?? '');

        // Row 1 — imports cleanly, warning only about the second phone number.
        $this->assertTrue($rows[0]->isValid());
        $this->assertSame('A-001', $rows[0]->value('account_no'));
        $this->assertSame('Peña', $rows[0]->value('last_name'));
        $this->assertSame('Juan Carlos', $rows[0]->value('first_name'));
        $this->assertSame('1985-03-04', $rows[0]->value('birthdate'));
        $this->assertSame('male', $rows[0]->value('gender'));
        $this->assertSame('married', $rows[0]->value('civil_status'));
        $this->assertSame('09171234567', $rows[0]->value('contact_number'));
        $this->assertSame(2500050, $rows[0]->value('monthly_income'));
        $this->assertNull($rows[0]->value('pledge_amount'));

        // Row 2 — still imports. Every one of its problems is a field the app
        // itself would refuse, and failing the row would orphan this member's
        // loans over a typo in an optional column.
        $this->assertTrue($rows[1]->isValid());
        $this->assertNull($rows[1]->value('civil_status'));
        $this->assertNull($rows[1]->value('contact_number'));
        $this->assertNull($rows[1]->value('email'));
        $this->assertEqualsCanonicalizing(
            ['enum_unmapped', 'contact_number_invalid', 'email_invalid'],
            array_column($rows[1]->warningsToArray(), 'code'),
        );

        // Row 3 — fails, because a missing account number and a date that does
        // not exist are not things a warning can rescue.
        $this->assertFalse($rows[2]->isValid());
        $this->assertEqualsCanonicalizing(
            ['date_invalid', 'required'],
            array_column($rows[2]->errorsToArray(), 'code'),
        );
    }

    public function test_a_loan_file_reads_normalises_and_reconstructs_end_to_end(): void
    {
        $header = implode(',', CsvImportSchema::labels(CsvImportSchema::LOANS));
        $line = $this->line(CsvImportSchema::LOANS, [
            'loan_no' => 'L-0001',
            'account_no' => 'A-001',
            'loan_product' => 'Regular Loan',
            'loan_amount' => '70,000.07',
            'interest_rate' => '3',
            'interest_type' => 'Straight (Fixed)',
            'payment_frequency' => 'Weekly',
            'term_in_months' => '6',
            'date_released' => '01/15/2025',
            'maturity_date' => '07/15/2025',
            'interest_amount' => '14,700.15',
            'loan_balance' => '30,000.03',
            'interest_balance' => '6,300.07',
        ]);

        $result = (new CsvImportReader)->read($this->writeFile($header."\n".$line."\n"), CsvImportSchema::LOANS);
        $row = (new LoanRowNormalizer)->normalize(iterator_to_array($result->rows())[0]);

        $this->assertTrue($row->isValid());
        $this->assertSame(7000007, $row->value('loan_amount'));
        $this->assertSame(3000003, $row->value('loan_balance'));
        $this->assertSame('weekly', $row->value('payment_frequency'));
        $this->assertSame(6, $row->value('term_in_months'));

        $schedule = (new LoanScheduleReconstructor)->reconstruct(LoanReconstructionInput::fromNormalizedRow($row));

        $this->assertTrue($schedule->isValid());

        // "6 months, Weekly" is 26 periods. The CSV's own "Term in Months" of 6
        // is carried through normalisation and then deliberately not used.
        $this->assertSame(26, $schedule->term);
        $this->assertSame('2025-07-15', $schedule->periods[25]->dueDate);

        // Exact equality, in centavos, against the two figures the file states.
        $this->assertSame(3000003, $schedule->outstandingPrincipal());
        $this->assertSame(630007, $schedule->outstandingInterest());
        $this->assertSame(7000007, $schedule->totalPrincipalDue());
        $this->assertSame(1470015, $schedule->totalInterestDue());

        $rows = $schedule->toScheduleRows();
        $this->assertCount(26, $rows);
        $this->assertSame('0.00', $rows[0]['penalty_amount']);
        $this->assertSame(26, $rows[25]['period_number']);
    }
}
