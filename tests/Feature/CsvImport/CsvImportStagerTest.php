<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportRow;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvImportStager;
use App\Services\CsvImport\NormalizedRow;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * Staging, and specifically the two MySQL JSON properties that decide how a
 * staged row has to be shaped.
 *
 * Both are asserted against a real MySQL round trip rather than against the PHP
 * value on the way in, because that is the only place either one happens. Assert
 * the value before it is written and both tests pass on a design that corrupts
 * every row.
 */
class CsvImportStagerTest extends TestCase
{
    use BuildsCsvImports;

    /**
     * `raw` must survive as a positional list.
     *
     * MySQL rewrites JSON OBJECT key order — by key length, then
     * lexicographically — so a `raw` keyed by header name reads back in an order
     * that has nothing to do with the file. Any consumer that recovers position
     * by iteration (pairing `raw` against the header for the error report, say)
     * then shows every value under the wrong heading. Arrays are preserved
     * verbatim, so `raw` is a list.
     *
     * The row below is built so that a key-ordered object would be OBVIOUS: the
     * account number, surname and given name are the three shortest labels and
     * would sort to the front regardless of their file position.
     */
    public function test_raw_round_trips_through_json_with_column_order_intact(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', [
                'last_name' => 'Zamora',
                'first_name' => 'Andres',
                'province' => 'Nueva Ecija',
                'spouse_occupation' => 'Tricycle driver',
            ]),
        ]);

        (new CsvImportStager)->stage($file);

        $row = CsvImportRow::where('csv_import_file_id', $file->id)->firstOrFail();

        $this->assertIsArray($row->raw);
        $this->assertTrue(array_is_list($row->raw), 'raw came back as an object, so its column order is already lost.');
        $this->assertCount(CsvImportSchema::width(CsvImportSchema::CUSTOMERS), $row->raw);

        // Positional, against the schema, cell by cell. This is the assertion
        // that a key-ordered object fails.
        $keys = CsvImportSchema::keys(CsvImportSchema::CUSTOMERS);

        $this->assertSame('A-001', $row->raw[array_search('account_no', $keys, true)]);
        $this->assertSame('Zamora', $row->raw[array_search('last_name', $keys, true)]);
        $this->assertSame('Andres', $row->raw[array_search('first_name', $keys, true)]);
        $this->assertSame('Nueva Ecija', $row->raw[array_search('province', $keys, true)]);
        $this->assertSame('Tricycle driver', $row->raw[array_search('spouse_occupation', $keys, true)]);
    }

    /**
     * Money must not be retyped by the column.
     *
     * A whole-number float loses its type through a JSON column — 12500.0 goes
     * in and comes back as int 12500 — while 12500.5 survives as a float. So
     * whole pesos and pesos-with-centavos would behave differently, silently.
     * The payload therefore carries every value as a STRING, and this test reads
     * the raw column to prove it rather than reading it through the cast that
     * would hide the difference.
     */
    public function test_money_keeps_its_type_through_the_json_column(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            // A whole peso amount, which is the value at risk, and one with
            // centavos, which is not — both have to come back the same way.
            $this->customerRow('A-002', ['pledge_amount' => '12,500.00', 'monthly_income' => '18,500.75']),
        ]);

        (new CsvImportStager)->stage($file);

        $stored = DB::table('csv_import_rows')->where('csv_import_file_id', $file->id)->first();
        $payload = json_decode($stored->normalized, true);

        $keys = CsvImportSchema::keys(CsvImportSchema::CUSTOMERS);
        $pledge = $payload['values'][array_search('pledge_amount', $keys, true)];
        $income = $payload['values'][array_search('monthly_income', $keys, true)];

        $this->assertIsString($pledge, 'A whole-peso amount came back as '.get_debug_type($pledge).'. It has to be a string or the column retypes it.');
        $this->assertIsString($income);
        $this->assertSame('1250000', $pledge);
        $this->assertSame('1850075', $income);

        // And it rebuilds as integer centavos, which is what the import pass
        // hands to the schedule arithmetic.
        $rebuilt = NormalizedRow::fromPayload($payload);

        $this->assertSame(1250000, $rebuilt->value('pledge_amount'));
        $this->assertIsInt($rebuilt->value('pledge_amount'));
    }

    public function test_it_stages_line_and_record_numbers_separately(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-010'),
            $this->customerRow('A-011'),
        ]);

        (new CsvImportStager)->stage($file);

        $rows = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();

        // The header is line 1, so the first data row is line 2 and record 1.
        // Storing both is what stops an error report telling an admin to fix the
        // row above the broken one.
        $this->assertSame([2, 1], [$rows[0]->line_number, $rows[0]->record_number]);
        $this->assertSame([3, 2], [$rows[1]->line_number, $rows[1]->record_number]);
        $this->assertSame(2, $file->fresh()->record_count);
        $this->assertTrue((bool) $file->fresh()->header_skipped);
    }

    public function test_it_records_invalid_rows_with_their_errors_rather_than_dropping_them(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-020'),
            $this->customerRow('', ['first_name' => '']),
        ]);

        $result = (new CsvImportStager)->stage($file);

        $this->assertSame(2, $result->staged);
        $this->assertSame(1, $result->valid);
        $this->assertSame(1, $result->invalid);

        $invalid = CsvImportRow::where('csv_import_file_id', $file->id)->where('status', 'invalid')->firstOrFail();

        $this->assertNotEmpty($invalid->errors);
        $this->assertContains('required', array_column($invalid->errors, 'code'));
        // Not decided yet — the import pass has not seen it.
        $this->assertNull($invalid->result);
    }

    public function test_it_collects_the_distinct_loan_products_for_the_mapping_phase(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'loans', [
            $this->loanRow('A-001', 'L-1', ['loan_product' => 'Salary Loan']),
            $this->loanRow('A-002', 'L-2', ['loan_product' => 'Emergency Loan']),
            $this->loanRow('A-003', 'L-3', ['loan_product' => 'Salary Loan']),
        ]);

        $result = (new CsvImportStager)->stage($file);

        $this->assertSame(['Salary Loan', 'Emergency Loan'], $result->loanProducts);
    }

    /**
     * A file whose rows already carry results is the record of what was written
     * to `borrowers` and `loans`. Re-staging would delete that record and then
     * import the same members a second time with nothing left to detect it.
     */
    public function test_it_refuses_to_restage_a_file_the_import_pass_has_touched(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [$this->customerRow('A-030')]);

        $stager = new CsvImportStager;
        $stager->stage($file);

        DB::table('csv_import_rows')->where('csv_import_file_id', $file->id)->update(['result' => 'imported']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cannot be re-staged/');

        $stager->stage($file->fresh());
    }

    /**
     * A staging pass that died leaves `record_count` NULL and no results, and is
     * simply redone — without duplicating the rows it did manage to write.
     */
    public function test_it_restages_cleanly_after_an_interrupted_pass(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-040'),
            $this->customerRow('A-041'),
        ]);

        $stager = new CsvImportStager;
        $stager->stage($file);

        // Simulate the crash: rows are there, the completion flag is not.
        $file->forceFill(['record_count' => null])->save();
        $this->assertFalse($stager->isStaged($file->fresh()));

        $stager->stage($file->fresh());

        $this->assertSame(2, CsvImportRow::where('csv_import_file_id', $file->id)->count());
        $this->assertTrue($stager->isStaged($file->fresh()));
    }
}
