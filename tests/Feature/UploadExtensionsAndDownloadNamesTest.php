<?php

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * Follow-up round 3, uploads:
 * - a valid ID's file name must end in an extension the upload takes, as well
 *   as its bytes reading as one, on the member and co-maker uploads (public
 *   registration posts to the member's);
 * - a stored file opens through its signed link whatever its saved name holds,
 *   under a cleaned name, never a 500;
 * - the document refusal lists every accepted extension, and the member photo
 *   upload refuses in words.
 */

const ROUND3_PDF_BYTES = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

/**
 * A real file on disk, so the upload's type is read from its bytes, as a
 * browser's would be; a faked file reports the type its name suggests.
 */
function round3Upload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

function round3JpegNamed(string $name): UploadedFile
{
    $image = UploadedFile::fake()->image('photo.jpg');

    return round3Upload($name, (string) file_get_contents($image->getPathname()));
}

beforeEach(function () {
    $this->seedAndLogin();
    Storage::fake('private');
    $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
});

it('refuses a member valid ID whose name ends in .bat even when its bytes are a PDF, in words', function (string $field, string $message) {
    $payload = ['type' => 'PhilSys ID', 'id_number' => '1234-5678-9012', $field => round3Upload('id.bat', ROUND3_PDF_BYTES)];

    if ($field === 'back_file') {
        $payload['front_file'] = round3Upload('front.pdf', ROUND3_PDF_BYTES);
    }

    $response = $this->postJson("/api/borrowers/{$this->member->id}/valid-ids", $payload)->assertUnprocessable();

    expect($response->json('errors'))->toBe([$field => [$message]])
        ->and(Document::query()->count())->toBe(0);
})->with([
    'single file' => ['file', 'The ID\'s file name must end in .jpg, .jpeg, .jpe, .jfif, .png or .pdf.'],
    'front' => ['front_file', 'The file name of the ID\'s front must end in .jpg, .jpeg, .jpe, .jfif, .png or .pdf.'],
    'back' => ['back_file', 'The file name of the ID\'s back must end in .jpg, .jpeg, .jpe, .jfif, .png or .pdf.'],
]);

it('refuses a co-maker valid ID whose name ends in .bat even when its bytes are a PDF', function () {
    $coMaker = CoMaker::factory()->create(['borrower_id' => $this->member->id]);

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'PhilSys ID',
        'front_file' => round3Upload('id.bat', ROUND3_PDF_BYTES),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.front_file', ['The file name of the ID\'s front must end in .jpg, .jpeg, .jpe, .jfif, .png or .pdf.']);

    expect(Document::query()->count())->toBe(0);
});

it('still takes a valid ID named .pdf, .jfif or .jpe, in any letter case', function (string $name) {
    $file = str_ends_with(strtolower($name), '.pdf') ? round3Upload($name, ROUND3_PDF_BYTES) : round3JpegNamed($name);

    $this->postJson("/api/borrowers/{$this->member->id}/valid-ids", [
        'type' => 'PhilSys ID',
        'id_number' => '1234-5678-9012',
        'front_file' => $file,
    ])->assertCreated();
})->with(['front.pdf', 'FRONT.PDF', 'front.jfif', 'front.JPE', 'front.png.jpg']);

it('lists every extension a document upload takes when it refuses a name', function () {
    $this->postJson("/api/borrowers/{$this->member->id}/documents", [
        'file' => round3Upload('receipt.bat', ROUND3_PDF_BYTES),
        'type' => 'payslip',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.file', ['The file\'s name must end in .jpg, .jpeg, .jpe, .jfif, .png, .pdf, .doc or .docx.']);
});

it('refuses a member photo in words', function (array $payload, string $message) {
    $response = $this->postJson("/api/borrowers/{$this->member->id}/photo", $payload)->assertUnprocessable();

    expect($response->json('message'))->toBe($message)
        ->and($response->json('errors'))->toBe(['photo' => [$message]]);
})->with([
    'missing' => [fn () => [], 'Choose a photo to upload.'],
    'not a picture' => [fn () => ['photo' => round3Upload('me.pdf', ROUND3_PDF_BYTES)], 'The photo must be a picture, such as a JPG or PNG.'],
    'too large' => [fn () => ['photo' => UploadedFile::fake()->image('me.jpg')->size(6000)], 'The photo can be at most 5 MB.'],
    'not a file' => [fn () => ['photo' => 'me.jpg'], 'The photo must be a picture, such as a JPG or PNG.'],
]);

it('opens a stored file whose saved name holds characters a header can\'t carry, under a cleaned name', function (string $saved, string $disposition) {
    Storage::disk('private')->put('documents/payslip/borrower/1/abc123.pdf', ROUND3_PDF_BYTES);
    $document = $this->member->documents()->create([
        'type' => 'payslip',
        'file_path' => 'documents/payslip/borrower/1/abc123.pdf',
        'original_filename' => $saved,
        'mime_type' => 'application/pdf',
        'file_size' => strlen(ROUND3_PDF_BYTES),
    ]);

    expect($this->get($document->url)->assertOk()->headers->get('Content-Disposition'))->toBe($disposition);
})->with([
    'a control character' => ["scan\x01part\x1F.pdf", 'inline; filename=scanpart.pdf'],
    'a backslash' => ['C:\\Users\\scan.pdf', 'inline; filename="C:_Users_scan.pdf"'],
    'a percent sign' => ['100% paid.pdf', "inline; filename=\"100 paid.pdf\"; filename*=utf-8''100%25%20paid.pdf"],
    'nothing a fallback can spell' => ["\u{1F600}\u{1F600}", "inline; filename=abc123.pdf; filename*=utf-8''%F0%9F%98%80%F0%9F%98%80"],
    'only control characters' => ["\x01\x02", 'inline; filename=abc123.pdf'],
]);
