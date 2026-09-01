<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportRow;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvImportStager;
use App\Services\CsvImport\NormalizedRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Every staged row records the account number the erasure path matches on.
     *
     * `csv_import_rows.borrower_id` only ever links the rows that produced or
     * matched a member. The rows that did not — a line rejected at staging, an
     * ambiguous identity match, a loan whose member was not found — still hold
     * that member's whole line, and before this column BorrowerPurgeService had
     * nothing to find them by. So the assertion that matters here is the SECOND
     * row: it is invalid, no borrower will ever be created for it, and it must
     * still carry the key.
     *
     * Asserted against a real MySQL round trip rather than against the value on
     * the way in, because a column that silently refused the write would leave
     * exactly the hole this closes.
     */
    public function test_it_records_the_account_number_an_erasure_matches_on(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001'),
            // Blank surname: the row fails validation at staging, is stamped
            // `skipped`/`invalid_row` by the import pass and never becomes a
            // borrower.
            $this->customerRow('A-002', ['last_name' => '']),
        ]);

        (new CsvImportStager)->stage($file);

        $rows = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();

        $this->assertSame(['A-001', 'A-002'], $rows->pluck('external_account_no')->all());
        $this->assertSame(['valid', 'invalid'], $rows->pluck('status')->all());
    }

    /**
     * The loans shape too, and for the same reason: a loan row that fails
     * `borrower_not_found` names a member the importer could not place, which is
     * not the same thing as naming nobody.
     */
    public function test_it_records_the_account_number_on_loan_rows_as_well(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'loans', [
            $this->loanRow('A-001', 'L-1'),
            $this->loanRow('A-002', 'L-2'),
        ]);

        (new CsvImportStager)->stage($file);

        $this->assertSame(
            ['A-001', 'A-002'],
            CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->pluck('external_account_no')->all(),
        );
    }

    /**
     * A blank account number is NULL, never the empty string.
     *
     * `''` would be a key every blank row in every run shares, and a purge
     * matching on it would blank strangers' lines rather than the member's.
     */
    public function test_a_row_with_no_account_number_carries_no_key(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [$this->customerRow('', ['last_name' => 'Reyes'])]);

        (new CsvImportStager)->stage($file);

        $this->assertNull(
            CsvImportRow::where('csv_import_file_id', $file->id)->value('external_account_no'),
            'An empty string here is a key that matches every blank row in the table.',
        );
    }

    /**
     * A row that never PARSED still records the account number.
     *
     * This is the one hole the "written for every row" argument did not cover.
     * When cellsByKey() fails its column-count check — a stray delimiter inside
     * an unquoted address is the usual cause — both normalizers return a
     * NormalizedRow with EMPTY values, so there is no `account_no` to read.
     * `raw` meanwhile holds the member's whole line: name, birthdate, contact
     * number, address, income. Keyed off `normalized` alone, that member had no
     * link an erasure could find.
     *
     * The middle row below is deliberately the one that breaks, so the file
     * still passes the reader's modal-width check and exactly one record fails
     * — the real shape of this fault, rather than a file-level rejection.
     */
    public function test_a_row_that_fails_to_parse_still_records_the_account_number(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001'),
            $this->customerRow('A-002'),
            $this->customerRow('A-003'),
        ]);

        // One stray delimiter on the second record: 23 cells where 22 are
        // expected. Written straight to the disk because the fixture builder
        // pads every row to the schema width by construction, which is exactly
        // the malformation being tested.
        $lines = explode("\n", rtrim(Storage::disk('private')->get($file->assembled_path), "\n"));
        $lines[2] .= ',stray';
        Storage::disk('private')->put($file->assembled_path, implode("\n", $lines)."\n");

        (new CsvImportStager)->stage($file);

        $rows = CsvImportRow::where('csv_import_file_id', $file->id)->orderBy('id')->get();

        $this->assertSame(['valid', 'invalid', 'valid'], $rows->pluck('status')->all());
        $this->assertSame('row_column_count', $rows[1]->errors[0]['code'], 'The middle row did not fail the way this test assumes.');
        $this->assertCount(23, $rows[1]->raw);

        $this->assertSame(
            ['A-001', 'A-002', 'A-003'],
            $rows->pluck('external_account_no')->all(),
            'An unparseable row staged no erasure key, so the member in it cannot be found by one.',
        );

        /*
         * And it came from `raw`, not from `normalized` — which is what makes
         * the fallback load-bearing rather than incidental. toPayload() writes
         * one null per schema column for an empty-values row, so the JSON path
         * the migration's backfill reads resolves to null here too.
         */
        $this->assertNull($rows[1]->normalized['values'][0]);
    }
}
