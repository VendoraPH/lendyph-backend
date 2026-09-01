<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CsvImportFile;
use App\Models\CsvImportFileChunk;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\User;
use App\Traits\Auditable;
use Carbon\CarbonInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The four importer models: casts, relations, and one thing they must NOT have.
 *
 * The casts are worth a spec because every one of these columns is read back by
 * code that never wrote it — a resumed run, an error report rendered days later.
 * A `raw` payload that comes back as a JSON string instead of an array fails at
 * whatever line first subscripts it, thousands of rows into an import, rather
 * than here.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
});

function freshImportRun(array $overrides = []): CsvImportRun
{
    return CsvImportRun::create(array_merge([
        'branch_id' => test()->branch->id,
        'as_of_date' => '2026-08-01',
        'phase' => 'staging',
    ], $overrides));
}

function freshImportFile(CsvImportRun $run, string $kind = 'customers', array $overrides = []): CsvImportFile
{
    return CsvImportFile::create(array_merge([
        'csv_import_run_id' => $run->id,
        'kind' => $kind,
        'original_filename' => "{$kind}.csv",
        'size_bytes' => 4096,
        'sha256' => str_repeat('a', 64),
        'chunk_size' => 2048,
        'total_chunks' => 2,
    ], $overrides));
}

it('round-trips the run json columns and its date casts', function () {
    $mapping = ['LEGACY-SAL' => 12, 'LEGACY-EMG' => 7];
    $notes = ['delimiter' => ';', 'warnings' => ['3 rows had a blank branch column']];

    $run = freshImportRun([
        'product_mapping' => $mapping,
        'notes' => $notes,
        'cursor_row_id' => 998877,
        'initiated_by' => $this->admin->id,
        'initiated_ip' => '203.0.113.9',
        'started_at' => '2026-08-29 09:15:00',
        'finished_at' => '2026-08-29 09:41:12',
    ]);

    $stored = CsvImportRun::findOrFail($run->id);

    // toEqual, not toBe: MySQL's native JSON type sorts object keys and does
    // not hand them back in insertion order. See the dedicated spec below —
    // key order is not something anything reading these columns may rely on.
    expect($stored->product_mapping)->toEqual($mapping);
    expect($stored->notes)->toEqual($notes);
    expect($stored->cursor_row_id)->toBe(998877);
    expect($stored->as_of_date)->toBeInstanceOf(CarbonInterface::class);
    expect($stored->as_of_date->toDateString())->toBe('2026-08-01');
    expect($stored->started_at)->toBeInstanceOf(CarbonInterface::class);
    expect($stored->started_at->format('Y-m-d H:i:s'))->toBe('2026-08-29 09:15:00');
    expect($stored->finished_at->format('Y-m-d H:i:s'))->toBe('2026-08-29 09:41:12');

    // Written as JSON, not as a PHP-serialised or string-escaped blob.
    expect(json_decode(DB::table('csv_import_runs')->where('id', $run->id)->value('product_mapping'), true))
        ->toEqual($mapping);
});

it('leaves the run json columns null when nothing was recorded', function () {
    $stored = CsvImportRun::findOrFail(freshImportRun()->id);

    expect($stored->product_mapping)->toBeNull();
    expect($stored->notes)->toBeNull();
    expect($stored->cursor_row_id)->toBeNull();
    expect($stored->started_at)->toBeNull();
    expect($stored->finished_at)->toBeNull();
});

/**
 * `header_skipped` is the one that would bite silently: MySQL hands a tinyint
 * back as the string "0", which is truthy in PHP, so an uncast column would make
 * the importer skip a header it had never skipped and drop the first member.
 */
it('casts the file counters and flags to native types', function () {
    $file = freshImportFile(freshImportRun(), 'loans', [
        'header_skipped' => true,
        'record_count' => 9008,
        'column_count' => 17,
        'delimiter' => ';',
        'encoding_note' => 'stripped utf-8 bom',
        'assembled_path' => 'imports/1/loans.csv',
        'assembled_sha256' => str_repeat('d', 64),
    ]);

    $stored = CsvImportFile::findOrFail($file->id);

    expect($stored->header_skipped)->toBeTrue();
    expect($stored->size_bytes)->toBe(4096);
    expect($stored->chunk_size)->toBe(2048);
    expect($stored->total_chunks)->toBe(2);
    expect($stored->record_count)->toBe(9008);
    expect($stored->column_count)->toBe(17);
    expect($stored->delimiter)->toBe(';');

    $unset = CsvImportFile::findOrFail(freshImportFile(freshImportRun())->id);
    expect($unset->header_skipped)->toBeFalse();
    expect($unset->record_count)->toBeNull();
    expect($unset->column_count)->toBeNull();
});

it('casts the chunk index and size to integers', function () {
    $chunk = CsvImportFileChunk::create([
        'csv_import_file_id' => freshImportFile(freshImportRun())->id,
        'chunk_index' => 0,
        'size_bytes' => 2048,
        'sha256' => str_repeat('e', 64),
        'path' => 'imports/chunks/0.part',
    ]);

    $stored = CsvImportFileChunk::findOrFail($chunk->id);

    // Chunk 0 is the one an uncast column loses: "0" from MySQL is a truthy
    // string, so a `if (! $chunk->chunk_index)` style guard misreads it.
    expect($stored->chunk_index)->toBe(0);
    expect($stored->size_bytes)->toBe(2048);
});

it('round-trips every json column on a staged row', function () {
    $raw = ['0' => 'CUST-000123', '1' => 'DELA CRUZ, JUAN', '2' => '12,500.00'];
    $normalized = ['external_account_no' => 'CUST-000123', 'last_name' => 'Dela Cruz', 'principal' => '12500.00'];
    $errors = ['principal' => ['Not a number: "12,500.00.-"'], 'birthdate' => ['Unparsable date: "00/00/0000"']];

    $file = freshImportFile(freshImportRun());
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $row = CsvImportRow::create([
        'csv_import_file_id' => $file->id,
        'line_number' => 4813,
        'record_number' => 4812,
        'raw' => $raw,
        'normalized' => $normalized,
        'status' => 'invalid',
        'errors' => $errors,
        'result' => 'failed',
        'result_category' => 'validation',
        'result_message' => 'Row rejected before any write.',
        'borrower_id' => $borrower->id,
        'attempts' => 2,
    ]);

    $stored = CsvImportRow::findOrFail($row->id);

    // `raw` is a LIST, and toBe holds for it: MySQL preserves JSON array order,
    // so the CSV's column order survives. The two associative columns get
    // toEqual, because their key order does not.
    expect($stored->raw)->toBe($raw);
    expect($stored->normalized)->toEqual($normalized);
    expect($stored->errors)->toEqual($errors);
    expect($stored->attempts)->toBe(2);
    expect($stored->loan_id)->toBeNull();

    // The two line counters differ by one here on purpose — that is the whole
    // reason they are separate columns, and neither may be derived from the
    // other.
    expect($stored->line_number)->toBe(4813);
    expect($stored->record_number)->toBe(4812);
});

it('wires up the relations between run, file, chunk and row', function () {
    $run = freshImportRun(['initiated_by' => $this->admin->id]);
    $customers = freshImportFile($run, 'customers');
    $loans = freshImportFile($run, 'loans');

    $chunk = CsvImportFileChunk::create([
        'csv_import_file_id' => $customers->id,
        'chunk_index' => 0,
        'size_bytes' => 2048,
        'sha256' => str_repeat('f', 64),
        'path' => 'imports/chunks/0.part',
    ]);

    $row = CsvImportRow::create([
        'csv_import_file_id' => $customers->id,
        'line_number' => 2,
        'record_number' => 1,
        'raw' => ['acct' => 'CUST-000123'],
        'status' => 'valid',
    ]);

    expect($run->files()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$customers->id, $loans->id])->sort()->values()->all());
    expect($run->customersFile->id)->toBe($customers->id);
    expect($run->loansFile->id)->toBe($loans->id);
    expect($run->branch->id)->toBe($this->branch->id);
    expect($run->initiatedBy->id)->toBe($this->admin->id);

    expect($customers->run->id)->toBe($run->id);
    expect($customers->chunks()->pluck('id')->all())->toBe([$chunk->id]);
    expect($customers->rows()->pluck('id')->all())->toBe([$row->id]);

    expect($chunk->file->id)->toBe($customers->id);
    expect($row->file->id)->toBe($customers->id);
});

/**
 * None of the four may be Auditable.
 *
 * A staging table is written once per CSV line and a run's phase and cursor are
 * updated continually while it works, so the trait would write an audit row per
 * line plus thousands of phase updates — doubling the write cost of the exact
 * operation this schema exists to make cheap, and burying the audit log the
 * moment the first real migration runs. The importer writes one summary row
 * when the run finishes instead.
 */
it('does not audit-log the importer bookkeeping tables', function (string $model) {
    expect(in_array(Auditable::class, class_uses_recursive($model), true))
        ->toBeFalse("{$model} must not use the Auditable trait — see this spec's docblock.");
})->with([
    CsvImportRun::class,
    CsvImportFile::class,
    CsvImportFileChunk::class,
    CsvImportRow::class,
]);

it('writes no audit rows while staging an import', function () {
    $before = DB::table('audit_logs')->count();

    $file = freshImportFile(freshImportRun());
    CsvImportFileChunk::create([
        'csv_import_file_id' => $file->id,
        'chunk_index' => 0,
        'size_bytes' => 2048,
        'sha256' => str_repeat('a', 64),
        'path' => 'imports/chunks/0.part',
    ]);
    CsvImportRow::create([
        'csv_import_file_id' => $file->id,
        'line_number' => 2,
        'record_number' => 1,
        'raw' => ['acct' => 'CUST-000123'],
        'status' => 'valid',
    ]);

    expect(DB::table('audit_logs')->count())->toBe($before);
});

/**
 * What a MySQL JSON column does NOT preserve — pinned here so the parsing code
 * is written against the real behaviour rather than against json_encode's.
 *
 * MySQL does not store the bytes it was given. It parses the document into a
 * binary form that sorts object keys by length and then lexicographically, and
 * that normalises numbers. Two consequences, both of which would land in the
 * middle of an import rather than at its start:
 *
 *  1. Object key order is destroyed. A `raw` column holding a CSV line as an
 *     object keyed by header name therefore cannot be used to reconstruct the
 *     line's column order. Storing the line as a LIST — as this spec's `$raw`
 *     is — keeps the order, because JSON arrays are preserved verbatim.
 *  2. A whole-number float loses its type: 12500.0 goes in and 12500 comes back
 *     as an int. Money must not travel through `normalized` as a float, or a
 *     principal will silently change type between the staging pass and the
 *     import pass. Keep it a string and cast it at the point of use.
 */
it('does not preserve json object key order or whole-number float types', function () {
    $run = freshImportRun([
        'notes' => ['z' => 1, 'aaaa' => 2, 'LEGACY-EMG' => 7, 'LEGACY-SAL' => 12],
    ]);

    $file = freshImportFile($run);
    $row = CsvImportRow::create([
        'csv_import_file_id' => $file->id,
        'line_number' => 2,
        'record_number' => 1,
        'raw' => ['CUST-000123', 'DELA CRUZ, JUAN', '12,500.00'],
        'normalized' => ['whole' => 12500.0, 'fractional' => 12500.5, 'as_string' => '12500.00'],
        'status' => 'valid',
    ]);

    // Keys come back sorted by length, then lexicographically — not as written.
    expect(array_keys(CsvImportRun::findOrFail($run->id)->notes))
        ->toBe(['z', 'aaaa', 'LEGACY-EMG', 'LEGACY-SAL']);

    $stored = CsvImportRow::findOrFail($row->id);

    // A list survives exactly, which is why the CSV line is stored as one.
    expect($stored->raw)->toBe(['CUST-000123', 'DELA CRUZ, JUAN', '12,500.00']);

    expect($stored->normalized['whole'])->toBe(12500);
    expect($stored->normalized['fractional'])->toBe(12500.5);
    expect($stored->normalized['as_string'])->toBe('12500.00');
});

it('lets a borrower carry and expose its legacy account number', function () {
    $borrower = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'external_account_no' => 'CUST-000123',
    ]);

    // Reached $fillable rather than being dropped by mass-assignment guarding.
    expect($borrower->fresh()->external_account_no)->toBe('CUST-000123');

    $this->actingAs($this->admin)
        ->getJson("/api/borrowers/{$borrower->id}")
        ->assertOk()
        ->assertJsonPath('data.external_account_no', 'CUST-000123');
});
