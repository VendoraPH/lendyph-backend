<?php

use App\Models\Borrower;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * Member and loan document uploads: the file's own name must end in one of the
 * extensions the upload takes, as well as its bytes reading as one of those
 * types, and a name holding control characters is stored without them, so its
 * signed link still opens.
 */

const UPLOAD_TEST_PDF_BYTES = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

/**
 * A real file on disk, so the upload's type is read from its bytes, as a
 * browser's would be; a faked file reports the type its name suggests.
 */
function uploadedPdfNamed(string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, UPLOAD_TEST_PDF_BYTES);

    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function () {
    $this->seedAndLogin();
    Storage::fake('private');
    $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
});

it('refuses a member upload named .bat even when its bytes are a PDF', function () {
    $this->postJson("/api/borrowers/{$this->member->id}/documents", [
        'file' => uploadedPdfNamed('receipt.bat'),
        'type' => 'payslip',
    ])->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(Document::query()->count())->toBe(0);
});

it('refuses a loan upload named .bat even when its bytes are a PDF', function () {
    $loan = $this->createReleasedLoan();

    $this->postJson("/api/loans/{$loan->id}/documents", [
        'file' => uploadedPdfNamed('receipt.bat'),
        'type' => 'policy_exception_letter',
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('still takes the same PDF named .pdf, in any letter case', function (string $name) {
    $this->postJson("/api/borrowers/{$this->member->id}/documents", [
        'file' => uploadedPdfNamed($name),
        'type' => 'payslip',
    ])->assertCreated();
})->with(['receipt.pdf', 'RECEIPT.PDF']);

it('opens a document whose uploaded name held control characters through its signed link', function () {
    $this->postJson("/api/borrowers/{$this->member->id}/documents", [
        'file' => uploadedPdfNamed("scan\x10part\x13.pdf"),
        'type' => 'payslip',
    ])->assertCreated();

    $document = Document::query()->latest('id')->firstOrFail();

    $this->get($document->url)->assertOk();

    expect($document->original_filename)->toBe('scanpart.pdf');
});
