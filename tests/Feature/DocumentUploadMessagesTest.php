<?php

use App\Http\Requests\Document\StoreDocumentRequest;
use App\Models\Borrower;
use App\Models\Document;
use App\Services\ValidIdService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * Member, co-maker and loan document uploads, valid IDs included, refuse a
 * file in words: one clear sentence for the file, never Laravel's default
 * ("The file field must be a file of type: …") nor "(and 1 more error)".
 */

function uploadNamed(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function () {
    $this->seedAndLogin();
    Storage::fake('private');
    $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
});

it('refuses a batch file with one sentence in words', function () {
    $response = $this->postJson("/api/borrowers/{$this->member->id}/documents", [
        'file' => uploadNamed('cleanup.bat', "@echo off\r\ndel *.*\r\n"),
        'type' => 'payslip',
    ])->assertUnprocessable();

    expect($response->json('message'))->toBe('The file must be a picture (JPG or PNG), a PDF or a Word document.')
        ->and($response->json('errors'))->toBe(['file' => ['The file must be a picture (JPG or PNG), a PDF or a Word document.']])
        ->and(Document::query()->count())->toBe(0);
});

it('refuses a PDF named .bat by its name, in words', function () {
    $this->postJson("/api/loans/{$this->createReleasedLoan()->id}/documents", [
        'file' => uploadNamed('receipt.bat', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        'type' => 'policy_exception_letter',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'The file\'s name must end in .jpg, .jpeg, .jpe, .jfif, .png, .pdf, .doc or .docx.');
});

it('asks for the file and the type in words', function () {
    $this->postJson("/api/borrowers/{$this->member->id}/documents", ['type' => 'bad/type'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file', ['Choose a file to upload.'])
        ->assertJsonPath('errors.type', ['The document type may only use letters, numbers, spaces, hyphens and underscores.']);
});

it('refuses a valid ID that is not a picture or a PDF, in words', function () {
    $response = $this->postJson("/api/borrowers/{$this->member->id}/valid-ids", [
        'front_file' => uploadNamed('id.bat', "@echo off\r\n"),
        'type' => 'PhilSys ID',
        'id_number' => '1234-5678-9012',
    ])->assertUnprocessable();

    expect($response->json('message'))->toBe('The ID\'s front must be a picture (JPG or PNG) or a PDF.')
        ->and($response->json('errors'))->toBe(['front_file' => ['The ID\'s front must be a picture (JPG or PNG) or a PDF.']]);

    $this->postJson("/api/borrowers/{$this->member->id}/valid-ids", [
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'type' => 'PhilSys ID',
    ])->assertUnprocessable()->assertJsonPath('message', 'Enter the ID number.');

    $this->postJson("/api/borrowers/{$this->member->id}/valid-ids", [
        'file' => UploadedFile::fake()->image('id.jpg'),
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'type' => 'PhilSys ID',
        'id_number' => '1234-5678-9012',
    ])->assertUnprocessable()->assertJsonPath('message', 'Send one picture of the ID, or its front and back, not both.');
});

it('words every rule of the document and valid ID uploads', function (array $rules, array $messages) {
    $missing = [];

    foreach ($rules as $field => $fieldRules) {
        foreach ($fieldRules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $name = explode(':', $rule)[0];

            if (in_array($name, ['bail', 'nullable', 'sometimes'], true)) {
                continue;
            }

            foreach ($name === 'file' ? ['file', 'uploaded'] : [$name] as $key) {
                if (! array_key_exists("{$field}.{$key}", $messages)) {
                    $missing[] = "{$field}.{$key}";
                }
            }
        }
    }

    expect($missing)->toBe([]);
})->with([
    'documents' => [fn () => (new StoreDocumentRequest)->rules(), fn () => (new StoreDocumentRequest)->messages()],
    'valid IDs, a member' => [fn () => ValidIdService::rules(forMember: true), fn () => ValidIdService::MESSAGES],
    'valid IDs, a co-maker' => [fn () => ValidIdService::rules(forMember: false), fn () => ValidIdService::MESSAGES],
]);
