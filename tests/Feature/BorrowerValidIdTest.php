<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BorrowerValidIdTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('public');
    }

    public function test_upload_valid_id_success(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $file = UploadedFile::fake()->image('philsys.jpg');

        $response = $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'file' => $file,
            'type' => 'PhilSys ID',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'valid_id')
            ->assertJsonPath('data.label', 'PhilSys ID');

        $this->assertDatabaseHas('documents', [
            'documentable_type' => Borrower::class,
            'documentable_id' => $borrower->id,
            'type' => 'valid_id',
            'label' => 'PhilSys ID',
        ]);
    }

    public function test_upload_valid_id_requires_file(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'PhilSys ID',
        ])->assertUnprocessable();
    }

    public function test_upload_valid_id_requires_type(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $file = UploadedFile::fake()->image('philsys.jpg');

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'file' => $file,
        ])->assertUnprocessable();
    }

    public function test_upload_valid_id_rejects_invalid_mime(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $file = UploadedFile::fake()->create('doc.xlsx', 100, 'application/vnd.ms-excel');

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'file' => $file,
            'type' => 'PhilSys ID',
        ])->assertUnprocessable();
    }
}
