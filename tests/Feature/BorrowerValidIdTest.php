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

    public function test_upload_valid_id_rejects_mixing_legacy_and_new_shape(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $legacy = UploadedFile::fake()->image('legacy.jpg');
        $front = UploadedFile::fake()->image('front.jpg');

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'PhilSys ID',
            'file' => $legacy,
            'front_file' => $front,
        ])->assertUnprocessable();
    }

    public function test_upload_valid_id_persists_custom_type_name_when_others(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $front = UploadedFile::fake()->image('front.jpg');

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'others',
            'custom_type_name' => 'Company HR ID',
            'id_number' => 'HR-7',
            'front_file' => $front,
        ])->assertCreated();

        $this->assertDatabaseHas('documents', [
            'documentable_id' => $borrower->id,
            'type' => 'valid_id',
            'label' => 'others',
            'custom_type_name' => 'Company HR ID',
            'id_number' => 'HR-7',
            'side' => 'front',
        ]);
    }

    public function test_upload_valid_id_requires_custom_type_name_when_type_is_others(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $front = UploadedFile::fake()->image('front.jpg');

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'others',
            'front_file' => $front,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_type_name']);
    }

    public function test_list_valid_ids_groups_front_and_back(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'philippine_id',
            'id_number' => '1234-5678-9012',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
            'back_file' => UploadedFile::fake()->image('back.jpg'),
        ])->assertCreated();

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'drivers_license',
            'id_number' => 'N01-23-456789',
            'front_file' => UploadedFile::fake()->image('dl-front.jpg'),
        ])->assertCreated();

        $response = $this->getJson("/api/borrowers/{$borrower->id}/valid-ids")
            ->assertOk();

        $items = $response->json('data');
        $this->assertCount(2, $items);

        $phil = collect($items)->firstWhere('type', 'philippine_id');
        $this->assertSame('1234-5678-9012', $phil['id_number']);
        $this->assertNotEmpty($phil['front_url']);
        $this->assertNotEmpty($phil['back_url']);

        $dl = collect($items)->firstWhere('type', 'drivers_license');
        $this->assertNotEmpty($dl['front_url']);
        $this->assertNull($dl['back_url']);
    }

    public function test_list_valid_ids_returns_empty_data_when_none(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->getJson("/api/borrowers/{$borrower->id}/valid-ids")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_delete_valid_id_removes_both_sides(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'philippine_id',
            'id_number' => '1234-5678-9012',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
            'back_file' => UploadedFile::fake()->image('back.jpg'),
        ])->assertCreated();

        $items = $this->getJson("/api/borrowers/{$borrower->id}/valid-ids")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $items);
        $validIdId = $items[0]['id'];

        $this->deleteJson("/api/borrowers/{$borrower->id}/valid-ids/{$validIdId}")
            ->assertOk();

        $this->assertDatabaseMissing('documents', [
            'documentable_id' => $borrower->id,
            'type' => 'valid_id',
        ]);
    }

    public function test_delete_valid_id_404_for_other_borrower(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'philippine_id',
            'id_number' => '1234-5678-9012',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
        ])->assertCreated();

        $items = $this->getJson("/api/borrowers/{$borrower->id}/valid-ids")
            ->json('data');
        $validIdId = $items[0]['id'];

        $this->deleteJson("/api/borrowers/{$other->id}/valid-ids/{$validIdId}")
            ->assertNotFound();
    }
}
