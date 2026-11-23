<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportFile;
use App\Models\CsvImportRun;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\ImportTick;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An import may bring members alone: a cooperative with no loans to carry
 * over, or a batch of new members. The loans file is optional; a loans file
 * on its own is not, because its rows find their members through the members
 * file of the same import.
 */
class MembersOnlyImportTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        Storage::fake('private');
    }

    public function test_a_members_only_run_imports_its_members_and_ends_complete(): void
    {
        $members = $this->membersCsv([
            ['account_no' => 'M-001', 'first_name' => 'Ana', 'date_hired' => '2012-06-01', 'membership_date' => '2010-03-15', 'member_type' => 'Associate', 'membership_number' => 'BC-0007'],
            ['account_no' => 'M-002', 'first_name' => 'Ben'],
        ]);

        $opened = $this->postJson('/api/imports', $this->openRunPayload(['customers' => $members]))
            ->assertCreated()
            ->assertJsonPath('files.customers.total_chunks', 1);
        $this->assertArrayNotHasKey('loans', $opened->json('files'));

        $runId = (int) $opened->json('run.id');
        $this->sendChunk($runId, 'customers', 0, $members)->assertCreated();

        $assembled = $this->postJson("/api/imports/{$runId}/assemble")
            ->assertOk()
            ->assertJsonPath('run.phase', 'assembled')
            ->assertJsonPath('files.customers.assembled', true);
        $this->assertArrayNotHasKey('loans', $assembled->json('files'));

        $tick = $this->runToIdle(CsvImportRun::findOrFail($runId));

        $this->assertSame('completed', $tick->phase, 'no loans to stage, map or import, so the run simply finishes');
        $this->assertSame('completed', CsvImportRun::findOrFail($runId)->phase);

        $ana = Borrower::where('external_account_no', 'M-001')->firstOrFail();
        $this->assertSame('2012-06-01', $ana->date_hired?->toDateString());
        $this->assertSame('2010-03-15', $ana->membership_date?->toDateString());
        $this->assertSame('associate', $ana->member_type);
        $this->assertSame('BC-0007', $ana->membership_number);
        $this->assertTrue(Borrower::where('external_account_no', 'M-002')->exists());

        $status = $this->getJson("/api/imports/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.phase', 'completed')
            ->assertJsonPath('data.product_mapping_required', false)
            ->assertJsonPath('data.files.customers.counts.imported', 2);
        $this->assertArrayNotHasKey('loans', $status->json('data.files'));
    }

    public function test_a_loans_only_run_is_refused_in_words(): void
    {
        $this->postJson('/api/imports', $this->openRunPayload(['loans' => 'Account No.,Loan No.']))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['files.customers' => ['A loans file needs its members file in the same import.']]);

        $this->postJson('/api/imports', $this->openRunPayload([]))
            ->assertStatus(422)
            ->assertJsonPath('errors.files', ['Choose the members file to import.']);

        $this->assertSame(0, CsvImportRun::count());
    }

    public function test_a_two_file_run_still_opens_and_assembles_with_both_files(): void
    {
        $members = $this->membersCsv([['account_no' => 'T-001', 'first_name' => 'Cora']]);
        $loans = 'Account No.,Loan No.'."\n";

        $runId = (int) $this->postJson('/api/imports', $this->openRunPayload(['customers' => $members, 'loans' => $loans]))
            ->assertCreated()
            ->assertJsonPath('files.customers.total_chunks', 1)
            ->assertJsonPath('files.loans.total_chunks', 1)
            ->json('run.id');

        $this->sendChunk($runId, 'customers', 0, $members)->assertCreated();

        // Both declared files are still required before assembling.
        $this->postJson("/api/imports/{$runId}/assemble")
            ->assertStatus(422)
            ->assertJsonPath('missing_chunks.loans', [0]);

        $this->sendChunk($runId, 'loans', 0, $loans)->assertCreated();

        $this->postJson("/api/imports/{$runId}/assemble")
            ->assertOk()
            ->assertJsonPath('files.customers.assembled', true)
            ->assertJsonPath('files.loans.assembled', true);
    }

    public function test_a_run_without_its_members_file_is_never_assembled(): void
    {
        // Not reachable through POST /imports any more; a run written some
        // other way must still not get as far as importing loans alone.
        $run = CsvImportRun::create([
            'branch_id' => $this->branch->id,
            'as_of_date' => now()->toDateString(),
            'phase' => 'uploading',
            'initiated_by' => auth()->id(),
            'initiated_ip' => '127.0.0.1',
        ]);
        CsvImportFile::create([
            'csv_import_run_id' => $run->id,
            'kind' => 'loans',
            'original_filename' => 'loans.csv',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'chunk_size' => 1024,
            'total_chunks' => 1,
        ]);

        $this->postJson("/api/imports/{$run->id}/assemble")
            ->assertStatus(422)
            ->assertJsonPath('message', "Import run #{$run->id} has no members file. A loans file needs its members file in the same import.");
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, string>  $files  kind => contents
     * @return array<string, mixed>
     */
    private function openRunPayload(array $files): array
    {
        return [
            'branch_id' => $this->branch->id,
            'files' => array_map(static fn (string $contents): array => [
                'filename' => 'upload.csv',
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ], $files),
        ];
    }

    private function sendChunk(int $runId, string $kind, int $index, string $bytes)
    {
        return $this->post("/api/imports/{$runId}/files/{$kind}/chunks/{$index}", [
            'sha256' => hash('sha256', $bytes),
            'chunk' => UploadedFile::fake()->createWithContent("chunk{$index}.part", $bytes),
        ], ['Accept' => 'application/json']);
    }

    /**
     * A members file in today's layout, header row first.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function membersCsv(array $rows): string
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::CUSTOMERS);
        $lines = [implode(',', CsvImportSchema::labels(CsvImportSchema::CUSTOMERS))];

        foreach ($rows as $row) {
            $values = $row + [
                'last_name' => 'Dela Cruz',
                'birthdate' => '1985-04-12',
                'gender' => 'Female',
                'civil_status' => 'Single',
                'contact_number' => '09171234567',
                'street_address' => '12 Mabini Street',
                'barangay' => 'San Roque',
                'city' => 'Cabanatuan',
                'province' => 'Nueva Ecija',
            ];

            $lines[] = implode(',', array_map(static fn (string $key): string => $values[$key] ?? '', $keys));
        }

        return implode("\n", $lines)."\n";
    }

    private function runToIdle(CsvImportRun $run, int $maxTicks = 50): ImportTick
    {
        $processor = new CsvImportProcessor;

        for ($i = 0; $i < $maxTicks; $i++) {
            $tick = $processor->advance($run);

            if ($tick->idle) {
                return $tick;
            }

            $run->refresh();
        }

        $this->fail("The run did not settle within {$maxTicks} ticks.");
    }
}
