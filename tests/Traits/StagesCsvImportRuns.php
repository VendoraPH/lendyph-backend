<?php

namespace Tests\Traits;

use App\Models\Branch;
use App\Models\CsvImportFile;
use App\Models\CsvImportFileChunk;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Role;
use App\Models\User;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\NormalizedRow;
use App\Services\CsvImport\RowNote;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Builds staged import runs for the operator-surface tests.
 *
 * Staged rows are produced through the real NormalizedRow::toPayload() rather
 * than by hand-writing JSON. That is the point: the mapping, status and error
 * endpoints all read positionally out of `normalized.values`, and a fixture
 * that invented its own shape would pass while the endpoints read the wrong
 * column off a real file.
 */
trait StagesCsvImportRuns
{
    protected User $importAdmin;

    protected Branch $importBranch;

    protected function seedAndLoginAsImportAdmin(): void
    {
        Artisan::call('migrate:fresh');
        $this->seed(DatabaseSeeder::class);

        $this->importBranch = Branch::first();
        $this->importAdmin = User::where('username', 'super_admin')->firstOrFail();

        $this->actingAs($this->importAdmin);
    }

    protected function userWithRoleNamed(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeImportRun(array $attributes = []): CsvImportRun
    {
        return CsvImportRun::create(array_merge([
            'branch_id' => $this->importBranch->id,
            'as_of_date' => '2026-06-30',
            'phase' => 'awaiting_mapping',
            'initiated_by' => $this->importAdmin->id,
            'initiated_ip' => '127.0.0.1',
            'started_at' => now()->subMinutes(10),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeImportFile(CsvImportRun $run, string $kind, array $attributes = []): CsvImportFile
    {
        return CsvImportFile::create(array_merge([
            'csv_import_run_id' => $run->id,
            'kind' => $kind,
            'original_filename' => $kind.'.csv',
            'size_bytes' => 4096,
            'sha256' => str_repeat('a', 64),
            'chunk_size' => 1024,
            'total_chunks' => 4,
            'assembled_path' => "imports/{$run->id}/{$kind}.csv",
            'assembled_sha256' => str_repeat('a', 64),
            'delimiter' => ',',
            'header_skipped' => true,
        ], $attributes));
    }

    /**
     * @param  list<int>  $indexes
     */
    protected function receiveChunks(CsvImportFile $file, array $indexes): void
    {
        foreach ($indexes as $index) {
            CsvImportFileChunk::create([
                'csv_import_file_id' => $file->id,
                'chunk_index' => $index,
                'size_bytes' => 1024,
                'sha256' => str_repeat('b', 64),
                'path' => "imports/chunks/{$file->id}/{$index}",
            ]);
        }
    }

    /**
     * Stage one row exactly the way the staging pass would.
     *
     * `$lineNumber` is the PHYSICAL line, header included — the number the
     * error report has to print. `record_number` is derived one behind it,
     * which is only true for a single-header file and is precisely why the two
     * are separate columns in the schema rather than one derived from the other.
     *
     * @param  array<string, mixed>  $values  keyed by CsvImportSchema field key
     * @param  array{status?: string, result?: string|null, result_category?: string|null, result_message?: string|null, warnings?: list<array{0: string, 1: string, 2: string}>, errors?: list<array{0: string, 1: string, 2: string}>, raw?: array<string, string>}  $options
     */
    protected function stageRow(CsvImportFile $file, int $lineNumber, array $values, array $options = []): CsvImportRow
    {
        $shape = $file->kind === 'loans' ? CsvImportSchema::LOANS : CsvImportSchema::CUSTOMERS;
        $recordNumber = $lineNumber - 1;

        $toNotes = static fn (array $notes): array => array_map(
            static fn (array $n): RowNote => new RowNote($n[0], $n[1], $n[2]),
            $notes,
        );

        $normalized = new NormalizedRow(
            shape: $shape,
            rowNumber: $recordNumber,
            values: $values,
            warnings: $toNotes($options['warnings'] ?? []),
            errors: $toNotes($options['errors'] ?? []),
        );

        $rawSource = $options['raw'] ?? array_map(
            static fn (mixed $v): string => $v === null ? '' : (string) $v,
            $values,
        );

        return CsvImportRow::create([
            'csv_import_file_id' => $file->id,
            'line_number' => $lineNumber,
            'record_number' => $recordNumber,
            'raw' => $this->rawCells($shape, $rawSource),
            'normalized' => ($options['normalized_null'] ?? false) ? null : $normalized->toPayload(),
            'status' => $options['status'] ?? (($options['errors'] ?? []) === [] ? 'valid' : 'invalid'),
            'errors' => ($options['errors'] ?? []) === [] ? null : $normalized->errorsToArray(),
            'result' => $options['result'] ?? null,
            'result_category' => $options['result_category'] ?? null,
            'result_message' => $options['result_message'] ?? null,
        ]);
    }

    /**
     * A full-width positional cell list, which is what the reader produces and
     * what `raw` holds.
     *
     * @param  array<string, string>  $keyed
     * @return list<string>
     */
    protected function rawCells(string $shape, array $keyed): array
    {
        $cells = array_fill(0, CsvImportSchema::width($shape), '');

        foreach ($keyed as $key => $value) {
            $cells[CsvImportSchema::indexOf($shape, $key)] = (string) $value;
        }

        return $cells;
    }

    /**
     * A realistic loan row: ₱50,000 over 12 months at 3%, straight.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function loanValues(array $overrides = []): array
    {
        return array_merge([
            'account_no' => 'A-001',
            'loan_no' => '2023-0041',
            'loan_amount' => 5000000,
            'loan_balance' => 2500000,
            'interest_rate' => '3',
            'interest_amount' => 180000,
            'interest_balance' => 90000,
            'purpose' => 'Capital',
            'loan_product' => 'Regular Loan',
            'term_in_months' => 12,
            'payment_frequency' => 'monthly',
            'interest_type' => 'straight',
            'date_released' => '2023-01-15',
            'maturity_date' => '2024-01-15',
        ], $overrides);
    }
}
