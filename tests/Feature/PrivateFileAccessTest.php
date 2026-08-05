<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class PrivateFileAccessTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function makeBorrowerWithDocument(): array
    {
        Storage::fake('private');

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'photo_path' => 'borrowers/photos/1/photo.jpg',
        ]);

        Storage::disk('private')->put('borrowers/photos/1/photo.jpg', 'photo-bytes');
        Storage::disk('private')->put('documents/valid_id/borrower/1/id.jpg', 'id-bytes');

        $document = Document::create([
            'documentable_type' => Borrower::class,
            'documentable_id' => $borrower->id,
            'type' => 'valid_id',
            'file_path' => 'documents/valid_id/borrower/1/id.jpg',
            'original_filename' => 'id.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
        ]);

        return [$borrower, $document];
    }

    public function test_a_signed_link_serves_the_document(): void
    {
        [, $document] = $this->makeBorrowerWithDocument();

        $response = $this->get($document->url)->assertOk();

        // Identity documents must never sit in a shared cache. Asserting the
        // directive rather than the exact header string, since the framework
        // composes and orders Cache-Control itself.
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_an_unsigned_url_is_rejected(): void
    {
        // This is the whole point: the raw route must be useless without the
        // signature. Previously the equivalent /storage/... path was served
        // straight off disk by nginx to anyone who asked.
        [, $document] = $this->makeBorrowerWithDocument();

        $this->get("/api/files/documents/{$document->id}")->assertForbidden();
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        [, $document] = $this->makeBorrowerWithDocument();

        $this->get($document->url.'x')->assertForbidden();
    }

    public function test_an_expired_link_is_rejected(): void
    {
        [, $document] = $this->makeBorrowerWithDocument();

        $expired = URL::temporarySignedRoute(
            'files.document',
            now()->subMinute(),
            ['document' => $document->id],
        );

        $this->get($expired)->assertForbidden();
    }

    public function test_a_signed_link_for_one_document_does_not_open_another(): void
    {
        [$borrower] = $this->makeBorrowerWithDocument();

        $other = Document::create([
            'documentable_type' => Borrower::class,
            'documentable_id' => $borrower->id,
            'type' => 'valid_id',
            'file_path' => 'documents/valid_id/borrower/1/other.jpg',
            'original_filename' => 'other.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 5,
        ]);

        $signedForOther = $other->url;

        // Swapping the id invalidates the signature rather than serving the
        // other file — the id is part of what is signed.
        $this->get(str_replace("/{$other->id}?", '/999999?', $signedForOther))
            ->assertForbidden();
    }

    public function test_borrower_photo_is_served_through_a_signed_link(): void
    {
        [$borrower] = $this->makeBorrowerWithDocument();

        $this->get($borrower->photo_url)->assertOk();
        $this->get("/api/files/borrowers/{$borrower->id}/photo")->assertForbidden();
    }

    public function test_photo_url_is_null_when_the_borrower_has_no_photo(): void
    {
        Storage::fake('private');

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'photo_path' => null,
        ]);

        $this->assertNull($borrower->photo_url);
    }

    public function test_a_missing_file_is_a_404_not_a_500(): void
    {
        Storage::fake('private');

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $document = Document::create([
            'documentable_type' => Borrower::class,
            'documentable_id' => $borrower->id,
            'type' => 'valid_id',
            'file_path' => 'documents/valid_id/borrower/1/gone.jpg',
            'original_filename' => 'gone.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 0,
        ]);

        $this->get($document->url)->assertNotFound();
    }

    public function test_uploaded_documents_land_on_the_private_disk_not_the_public_one(): void
    {
        Storage::fake('private');
        Storage::fake('public');

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/borrowers/{$borrower->id}/photo", [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $path = $borrower->fresh()->photo_path;

        $this->assertNotNull($path);
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
}
