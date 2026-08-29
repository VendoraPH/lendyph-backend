<?php

namespace Tests\Traits;

use App\Models\Branch;
use App\Models\CsvImportFile;
use App\Models\CsvImportRun;
use App\Models\User;
use App\Services\CsvImport\CsvImportSchema;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Fixture builder for the import runner's tests.
 *
 * Builds real files on a FAKED `private` disk rather than mocking the reader:
 * the storage traps this feature is full of — the disk the PII may live on, the
 * JSON column's treatment of lists and of whole-number floats — only exist on
 * the round trip, so a test that stubs the round trip would prove nothing about
 * the thing it is testing.
 */
trait BuildsCsvImports
{
    protected Branch $branch;

    protected User $admin;

    /**
     * Seed the app and point the private disk at a temporary directory.
     *
     * Deliberately NOT actingAs(): the runner is a scheduled command with no
     * authenticated user and no request, and half of what these tests hold to
     * account depends on that being genuinely true.
     */
    protected function seedForImport(): void
    {
        Artisan::call('migrate:fresh');
        $this->seed(DatabaseSeeder::class);

        $this->branch = Branch::first();
        $this->admin = User::where('username', 'super_admin')->first();

        Storage::fake('private');
    }

    protected function makeRun(array $overrides = []): CsvImportRun
    {
        return CsvImportRun::create(array_merge([
            'branch_id' => $this->branch->id,
            'as_of_date' => '2026-06-30',
            'phase' => 'assembled',
            'initiated_by' => $this->admin->id,
            'initiated_ip' => '203.0.113.9',
        ], $overrides));
    }

    /**
     * Write a CSV to the private disk and register it on the run, exactly as the
     * upload service would leave it once the chunks were reassembled and hashed.
     *
     * @param  list<array<string, string>>  $rows  Keyed by CsvImportSchema field key.
     */
    protected function makeFile(CsvImportRun $run, string $kind, array $rows, bool $withHeader = true): CsvImportFile
    {
        $shape = $kind === 'loans' ? CsvImportSchema::LOANS : CsvImportSchema::CUSTOMERS;
        $lines = [];

        if ($withHeader) {
            $lines[] = $this->csvLine(CsvImportSchema::labels($shape));
        }

        foreach ($rows as $row) {
            $cells = array_fill(0, CsvImportSchema::width($shape), '');

            foreach ($row as $key => $value) {
                $cells[CsvImportSchema::indexOf($shape, $key)] = $value;
            }

            $lines[] = $this->csvLine($cells);
        }

        $contents = implode("\n", $lines)."\n";
        $path = "csv-imports/{$run->id}/{$kind}.csv";

        Storage::disk('private')->put($path, $contents);

        return CsvImportFile::create([
            'csv_import_run_id' => $run->id,
            'kind' => $kind,
            'original_filename' => "{$kind}.csv",
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'chunk_size' => 1024 * 1024,
            'total_chunks' => 1,
            'assembled_path' => $path,
            'assembled_sha256' => hash('sha256', $contents),
        ]);
    }

    /**
     * One customer row with every required cell filled, so a test only has to
     * state what it is actually about.
     *
     * The given name is derived from the account number by default, and that is
     * not cosmetic: BorrowerMatcher resolves identity on first + last +
     * birthdate, so two rows sharing all three are correctly reported as an
     * `account_no_conflict` and the second is NOT imported. A fixture that
     * repeated one name would quietly test that path in every multi-row test
     * instead of the one it meant to.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function customerRow(string $accountNo, array $overrides = []): array
    {
        return array_merge([
            'account_no' => $accountNo,
            'last_name' => 'Dela Cruz',
            'first_name' => 'Member'.preg_replace('/[^A-Za-z0-9]/', '', $accountNo),
            'birthdate' => '1985-04-12',
            'gender' => 'Female',
            'civil_status' => 'Married',
            'contact_number' => '09171234567',
            'email' => 'juana@example.com',
            'street_address' => '12 Mabini Street',
            'barangay' => 'San Roque',
            'city' => 'Cabanatuan',
            'province' => 'Nueva Ecija',
            'employer_or_business' => 'Cabanatuan Public Market',
            'monthly_income' => '18,500.00',
            'pledge_amount' => '12,500.00',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function loanRow(string $accountNo, string $loanNo, array $overrides = []): array
    {
        return array_merge([
            'account_no' => $accountNo,
            'loan_no' => $loanNo,
            'loan_amount' => '60,000.00',
            'loan_balance' => '40,000.00',
            'interest_rate' => '3.0',
            'interest_amount' => '10,800.00',
            'interest_balance' => '7,200.00',
            'purpose' => 'Additional capital',
            'loan_product' => 'Salary Loan',
            'term_in_months' => '6',
            'payment_frequency' => 'Monthly',
            'interest_type' => 'Straight',
            'date_released' => '2026-01-15',
            'maturity_date' => '2026-07-15',
            'processing_fee' => '1,200.00',
            'service_fee' => '600.00',
            'other_fee_detail' => '',
            'other_fee_amount' => '',
        ], $overrides);
    }

    /**
     * @param  list<string>  $cells
     */
    private function csvLine(array $cells): string
    {
        return implode(',', array_map(
            static fn (string $cell): string => str_contains($cell, ',') || str_contains($cell, '"')
                ? '"'.str_replace('"', '""', $cell).'"'
                : $cell,
            $cells,
        ));
    }
}
