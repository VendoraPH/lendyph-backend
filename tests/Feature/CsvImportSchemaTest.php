<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CsvImportFile;
use App\Models\CsvImportFileChunk;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The schema the CSV migration importer stands on.
 *
 * Three of these constraints are load-bearing rather than defensive, and the
 * point of testing them here — against the migrations actually running — is
 * that a later schema change which drops one fails loudly instead of quietly
 * re-opening the bug it was written to close:
 *
 *  - `borrowers.external_account_no` unique: the join key between the two CSV
 *    files, and the backstop that stops a re-uploaded customers file from
 *    creating a second borrower for one member.
 *  - `csv_import_file_chunks (file_id, chunk_index)` unique: makes row-exists
 *    equivalent to chunk-stored, so concurrent uploads of the same index are
 *    safe by construction rather than by a racy read-modify-write.
 *  - `csv_import_files (run_id, kind)` unique: one customers file and one loans
 *    file per run, so the importer never has to guess which is authoritative.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
});

/**
 * A fresh instance of a shipped migration.
 *
 * `require`, not `require_once`, so the anonymous class is re-evaluated and each
 * call hands back a new object.
 */
function csvImportMigration(string $file): object
{
    return require database_path("migrations/{$file}");
}

/**
 * The six migrations in apply order. down() runs this list reversed, which is
 * also the only order the foreign keys permit.
 *
 * @return list<string>
 */
function csvImportMigrationFiles(): array
{
    return [
        '2026_08_29_100000_add_external_account_no_to_borrowers_table.php',
        '2026_08_29_100001_create_csv_import_runs_table.php',
        '2026_08_29_100002_create_csv_import_files_table.php',
        '2026_08_29_100003_create_csv_import_file_chunks_table.php',
        '2026_08_29_100004_create_csv_import_rows_table.php',
        '2026_08_29_100005_add_imports_process_permission.php',
    ];
}

/**
 * The comma-joined column list of an index, in key order, or null if absent.
 */
function csvImportIndexColumns(string $table, string $index): ?string
{
    return DB::table('information_schema.statistics')
        ->selectRaw('GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns')
        ->whereRaw('table_schema = schema()')
        ->where('table_name', $table)
        ->where('index_name', $index)
        ->value('columns');
}

function makeCsvImportRun(): CsvImportRun
{
    return CsvImportRun::create([
        'branch_id' => Branch::factory()->create()->id,
        'as_of_date' => '2026-08-01',
        'phase' => 'uploading',
    ]);
}

function makeCsvImportFile(CsvImportRun $run, string $kind = 'customers'): CsvImportFile
{
    return CsvImportFile::create([
        'csv_import_run_id' => $run->id,
        'kind' => $kind,
        'original_filename' => "{$kind}.csv",
        'size_bytes' => 2048,
        'sha256' => str_repeat('a', 64),
        'chunk_size' => 1024,
        'total_chunks' => 2,
    ]);
}

it('creates every table and column the importer needs', function () {
    expect(Schema::hasColumn('borrowers', 'external_account_no'))->toBeTrue();

    foreach (['csv_import_runs', 'csv_import_files', 'csv_import_file_chunks', 'csv_import_rows'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table {$table} to exist after migration.");
    }

    expect(Schema::hasColumns('csv_import_runs', [
        'branch_id', 'as_of_date', 'phase', 'product_mapping', 'cursor_row_id',
        'initiated_by', 'initiated_ip', 'started_at', 'finished_at', 'failure_reason', 'notes',
    ]))->toBeTrue();

    expect(Schema::hasColumns('csv_import_rows', [
        'csv_import_file_id', 'line_number', 'record_number', 'raw', 'normalized',
        'status', 'errors', 'result', 'result_category', 'result_message',
        'borrower_id', 'loan_id', 'attempts',
    ]))->toBeTrue();
});

/**
 * Driven through the shipped files rather than `migrate:rollback --step=6`,
 * which rolls back whatever six migrations happen to be newest and would quietly
 * start testing someone else's the moment one lands after these.
 */
it('rolls every migration back and reapplies it cleanly', function () {
    foreach (array_reverse(csvImportMigrationFiles()) as $file) {
        csvImportMigration($file)->down();
    }

    expect(Schema::hasColumn('borrowers', 'external_account_no'))->toBeFalse();
    foreach (['csv_import_rows', 'csv_import_file_chunks', 'csv_import_files', 'csv_import_runs'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} survived down().");
    }
    expect(DB::table('permissions')->where('name', 'imports:process')->exists())->toBeFalse();

    foreach (csvImportMigrationFiles() as $file) {
        csvImportMigration($file)->up();
    }

    expect(Schema::hasColumn('borrowers', 'external_account_no'))->toBeTrue();
    foreach (['csv_import_runs', 'csv_import_files', 'csv_import_file_chunks', 'csv_import_rows'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} did not come back.");
    }
    expect(DB::table('permissions')->where('name', 'imports:process')->exists())->toBeTrue();
});

/**
 * The whole reason the column can be both nullable and unique. Every borrower
 * already on file, and every future walk-in registration, has no legacy account
 * number — if NULLs collided under the index, the second such borrower could
 * never be created.
 */
it('allows many borrowers with a null external_account_no', function () {
    Borrower::factory()->count(3)->create(['external_account_no' => null]);

    expect(Borrower::whereNull('external_account_no')->count())->toBe(3);
});

it('rejects a second borrower with the same non-null external_account_no', function () {
    Borrower::factory()->create(['external_account_no' => 'CUST-000123']);

    expect(fn () => Borrower::factory()->create(['external_account_no' => 'CUST-000123']))
        ->toThrow(QueryException::class);

    expect(Borrower::where('external_account_no', 'CUST-000123')->count())->toBe(1);
});

/**
 * Two requests carrying chunk 3 of the same file cannot both be recorded. The
 * loser gets a duplicate-key error and is handled as the retry it is, rather
 * than both winning and leaving the file's chunk count wrong.
 */
it('rejects a duplicate chunk index within one file', function () {
    $file = makeCsvImportFile(makeCsvImportRun());

    $chunk = [
        'csv_import_file_id' => $file->id,
        'chunk_index' => 3,
        'size_bytes' => 1024,
        'sha256' => str_repeat('b', 64),
        'path' => 'imports/chunks/3.part',
    ];

    CsvImportFileChunk::create($chunk);

    expect(fn () => CsvImportFileChunk::create($chunk))->toThrow(QueryException::class);

    expect(CsvImportFileChunk::where('csv_import_file_id', $file->id)->count())->toBe(1);
});

/**
 * The index is scoped to the file, not global — two files being uploaded at once
 * both have a chunk 0.
 */
it('allows the same chunk index across two different files', function () {
    $run = makeCsvImportRun();
    $customers = makeCsvImportFile($run, 'customers');
    $loans = makeCsvImportFile($run, 'loans');

    foreach ([$customers, $loans] as $file) {
        CsvImportFileChunk::create([
            'csv_import_file_id' => $file->id,
            'chunk_index' => 0,
            'size_bytes' => 1024,
            'sha256' => str_repeat('c', 64),
            'path' => "imports/chunks/{$file->id}-0.part",
        ]);
    }

    expect(CsvImportFileChunk::count())->toBe(2);
});

it('rejects a second file of the same kind within one run', function () {
    $run = makeCsvImportRun();
    makeCsvImportFile($run, 'customers');

    expect(fn () => makeCsvImportFile($run, 'customers'))->toThrow(QueryException::class);

    expect(CsvImportFile::where('csv_import_run_id', $run->id)->count())->toBe(1);
});

/**
 * NULL `result` is the resume marker, so the column has to accept it — and a
 * staged row has to be insertable without one.
 */
it('stages a row with a null result and finds it as pending work', function () {
    $file = makeCsvImportFile(makeCsvImportRun());

    $pending = CsvImportRow::create([
        'csv_import_file_id' => $file->id,
        'line_number' => 2,
        'record_number' => 1,
        'raw' => ['acct' => 'CUST-000123'],
        'status' => 'valid',
    ]);

    CsvImportRow::create([
        'csv_import_file_id' => $file->id,
        'line_number' => 3,
        'record_number' => 2,
        'raw' => ['acct' => 'CUST-000124'],
        'status' => 'valid',
        'result' => 'imported',
    ]);

    expect($pending->fresh()->result)->toBeNull();
    expect(CsvImportRow::pending()->pluck('id')->all())->toBe([$pending->id]);
});

/**
 * Column ORDER is the point of both, not an implementation detail: the cursor
 * index has to put the file ahead of the id so a resume is a range scan from
 * where it stopped, and the reporting index has to put the two equality legs
 * ahead of the nullable `result` it groups on. So the assertion is on the
 * ordered column list, not on the index name.
 */
it('indexes csv_import_rows for the cursor and the resume scan', function () {
    expect(csvImportIndexColumns('csv_import_rows', 'csv_import_rows_csv_import_file_id_id_index'))
        ->toBe('csv_import_file_id,id');

    expect(csvImportIndexColumns('csv_import_rows', 'csv_import_rows_csv_import_file_id_status_result_index'))
        ->toBe('csv_import_file_id,status,result');
});
