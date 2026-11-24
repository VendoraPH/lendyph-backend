<?php

namespace Tests\Feature\CsvImport;

use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvRow;
use App\Services\CsvImport\NormalizedRow;
use App\Services\CsvImport\ShareCapitalRowNormalizer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The share capital file's own checks, made when it is staged (finish run
 * round 2, decision 13): a year-month-day date not after today, an amount
 * above ₱0.00 with at most two decimals, a known transaction type, and no
 * row that repeats an earlier one of the same file.
 */
class ShareCapitalRowNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_row_reads_into_typed_values(): void
    {
        $row = $this->row(['amount' => '₱1,500.50', 'remarks' => 'Opening balance']);

        $this->assertTrue($row->isValid());
        $this->assertSame('A-001', $row->value('account_no'));
        $this->assertSame('2026-01-15', $row->value('transaction_date'));
        $this->assertSame('OR-1001', $row->value('reference_number'));
        $this->assertSame('credit', $row->value('transaction_type'));
        $this->assertSame(150050, $row->value('amount'));
        $this->assertSame('Opening balance', $row->value('remarks'));

        // The staged payload rebuilds the same values, the amount as centavos.
        $rebuilt = NormalizedRow::fromPayload($row->toPayload());
        $this->assertSame(150050, $rebuilt->value('amount'));
        $this->assertSame('credit', $rebuilt->value('transaction_type'));
    }

    public function test_the_transaction_type_is_read_in_words(): void
    {
        foreach (['Contribution', 'contribution', 'CONTRIBUTION', 'Share Capital Contribution', 'Credit', 'CR'] as $word) {
            $this->assertSame('credit', $this->row(['transaction_type' => $word])->value('transaction_type'), $word);
        }

        foreach (['Withdrawal', 'withdrawal', 'Share capital withdrawal', 'Debit', 'dr'] as $word) {
            $this->assertSame('debit', $this->row(['transaction_type' => $word])->value('transaction_type'), $word);
        }

        $this->assertRefused($this->row(['transaction_type' => 'Dividend']), 'transaction_type', 'transaction_type_invalid', '"Dividend" is not a transaction type. Write Contribution or Withdrawal.');
        $this->assertRefused($this->row(['transaction_type' => '']), 'transaction_type', 'transaction_type_required');
    }

    public function test_the_date_is_written_year_month_day_and_not_after_today(): void
    {
        $this->assertRefused($this->row(['transaction_date' => '03/04/2026']), 'transaction_date', 'date_invalid', '"03/04/2026" isn\'t a date written as year-month-day, such as 2026-11-24.');
        $this->assertRefused($this->row(['transaction_date' => '2026-02-30']), 'transaction_date', 'date_invalid');
        $this->assertRefused($this->row(['transaction_date' => '45000']), 'transaction_date', 'date_invalid');
        $this->assertRefused($this->row(['transaction_date' => '']), 'transaction_date', 'date_required');
        $this->assertRefused($this->row(['transaction_date' => '2026-11-25']), 'transaction_date', 'transaction_date_future');
        $this->assertRefused($this->row(['transaction_date' => '1899-12-31']), 'transaction_date', 'transaction_date_implausible');

        $this->assertTrue($this->row(['transaction_date' => '2026-11-24'])->isValid(), 'today is allowed');
        $this->assertTrue($this->row(['transaction_date' => '1900-01-01'])->isValid());
    }

    public function test_the_amount_is_above_zero_with_at_most_two_decimals(): void
    {
        $this->assertRefused($this->row(['amount' => '0']), 'amount', 'amount_zero');
        $this->assertRefused($this->row(['amount' => '0.00']), 'amount', 'amount_zero');
        $this->assertRefused($this->row(['amount' => '-500']), 'amount', 'money_negative');
        $this->assertRefused($this->row(['amount' => '(500.00)']), 'amount', 'money_negative');
        $this->assertRefused($this->row(['amount' => '1.005']), 'amount', 'money_precision');
        $this->assertRefused($this->row(['amount' => 'five hundred']), 'amount', 'money_invalid');
        $this->assertRefused($this->row(['amount' => '']), 'amount', 'money_required');

        $this->assertSame(1, $this->row(['amount' => '0.01'])->value('amount'));
        $this->assertSame(50000, $this->row(['amount' => '500'])->value('amount'));
    }

    public function test_the_member_and_reference_are_never_cut_short(): void
    {
        $this->assertRefused($this->row(['account_no' => '']), 'account_no', 'required');
        $this->assertRefused($this->row(['account_no' => str_repeat('7', 51)]), 'account_no', 'account_no_too_long');
        $this->assertRefused($this->row(['reference_number' => str_repeat('R', 101)]), 'reference_number', 'reference_number_too_long');

        $this->assertTrue($this->row(['reference_number' => ''])->isValid(), 'a reference is optional');
        $this->assertNull($this->row(['reference_number' => '  '])->value('reference_number'));
    }

    public function test_long_remarks_are_shortened_with_a_warning(): void
    {
        $row = $this->row(['remarks' => str_repeat('x', 300)]);

        $this->assertTrue($row->isValid());
        $this->assertSame(255, mb_strlen((string) $row->value('remarks')));
        $this->assertSame(['truncated'], array_column($row->warningsToArray(), 'code'));
    }

    public function test_a_row_with_the_wrong_number_of_columns_is_refused(): void
    {
        $row = (new ShareCapitalRowNormalizer)->normalize(new CsvRow(2, 1, ['A-001', '2026-01-15']));

        $this->assertRefused($row, '__row', 'row_column_count');
    }

    public function test_a_row_repeating_an_earlier_one_of_the_file_is_refused_and_the_first_kept(): void
    {
        $file = new ShareCapitalRowNormalizer;

        $first = $this->row(['reference_number' => 'OR-7', 'amount' => '100'], $file, line: 2);
        $sameReference = $this->row(['reference_number' => 'or-7', 'amount' => '999'], $file, line: 3);
        $blank = $this->row(['reference_number' => '', 'amount' => '40'], $file, line: 4);
        $sameFigures = $this->row(['reference_number' => '', 'account_no' => 'a-001', 'amount' => '40'], $file, line: 5);
        $otherAmount = $this->row(['reference_number' => '', 'amount' => '41'], $file, line: 6);

        $this->assertTrue($first->isValid());
        $this->assertRefused($sameReference, 'reference_number', 'duplicate_in_file', 'Reference or-7 is also on line 2 of this file. A reference number belongs to one transaction, so only that first row is imported.');
        $this->assertTrue($blank->isValid(), 'no reference: its figures are its own so far');
        $this->assertRefused($sameFigures, '__row', 'duplicate_in_file', 'This row repeats line 4 of this file: the same member, date, type and amount, with no reference number. Only that first row is imported; if both are real, give each its own reference number.');
        $this->assertTrue($otherAmount->isValid());
    }

    public function test_a_row_with_no_reference_repeating_a_referenced_rows_figures_is_refused(): void
    {
        $file = new ShareCapitalRowNormalizer;

        $this->assertTrue($this->row(['reference_number' => 'OR-9'], $file, line: 2)->isValid());
        // Nothing tells the two apart.
        $this->assertRefused($this->row(['reference_number' => ''], $file, line: 3), '__row', 'duplicate_in_file');
        // A referenced row is told apart by its own reference.
        $this->assertTrue($this->row(['reference_number' => 'OR-10'], $file, line: 4)->isValid());

        $this->assertTrue($this->row(['reference_number' => ''], new ShareCapitalRowNormalizer, line: 2)->isValid(), 'another file starts afresh');
    }

    public function test_an_invalid_row_is_not_remembered_as_the_first(): void
    {
        $file = new ShareCapitalRowNormalizer;

        $this->assertFalse($this->row(['reference_number' => 'OR-1', 'amount' => '0'], $file, line: 2)->isValid());
        $this->assertTrue($this->row(['reference_number' => 'OR-1'], $file, line: 3)->isValid());
    }

    public function test_the_shape_has_the_template_columns_in_order(): void
    {
        $this->assertSame(
            ['Account No. or Member ID', 'Transaction Date', 'Reference Number', 'Transaction Type', 'Amount', 'Remarks'],
            CsvImportSchema::labels(CsvImportSchema::SHARE_CAPITAL),
        );
        $this->assertSame([6], CsvImportSchema::acceptedWidths(CsvImportSchema::SHARE_CAPITAL));
        $this->assertContains(CsvImportSchema::SHARE_CAPITAL, CsvImportSchema::shapes());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, string>  $values
     */
    private function row(array $values = [], ?ShareCapitalRowNormalizer $file = null, int $line = 2): NormalizedRow
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::SHARE_CAPITAL);
        $cells = array_fill(0, count($keys), '');
        $defaults = [
            'account_no' => 'A-001',
            'transaction_date' => '2026-01-15',
            'reference_number' => 'OR-1001',
            'transaction_type' => 'Contribution',
            'amount' => '250.00',
        ];

        foreach (array_merge($defaults, $values) as $key => $value) {
            $cells[CsvImportSchema::indexOf(CsvImportSchema::SHARE_CAPITAL, $key)] = $value;
        }

        return ($file ?? new ShareCapitalRowNormalizer)->normalize(new CsvRow($line, $line - 1, $cells));
    }

    private function assertRefused(NormalizedRow $row, string $field, string $code, ?string $message = null): void
    {
        $this->assertFalse($row->isValid(), "expected {$code}");

        $errors = array_values(array_filter(
            $row->errorsToArray(),
            static fn (array $note): bool => $note['field'] === $field && $note['code'] === $code,
        ));

        $this->assertNotEmpty($errors, "no {$code} on {$field}: ".json_encode($row->errorsToArray()));

        if ($message !== null) {
            $this->assertSame($message, $errors[0]['message']);
        }
    }
}
