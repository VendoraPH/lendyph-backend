<?php

/**
 * Valid IDs for co-makers: the borrower valid-ID model, on a second owner.
 *
 * A co-maker's ID is stored exactly as a borrower's is: Document rows with
 * `type = valid_id` on the co-maker's own documents() morph, the files on the
 * PRIVATE disk under documents/valid_id/co_maker/{id}, reachable only through
 * the expiring signed link DocumentResource emits. The endpoints share their
 * implementation with the borrower ones (App\Services\ValidIdService), and
 * this file mirrors BorrowerValidIdTest so the two cannot drift apart
 * unnoticed. The frontend depends on that: it drives co-maker IDs with its
 * borrower client code, changing only the URL.
 *
 * The specs with no borrower equivalent are about there being a second owner.
 * Ownership must be decided by the morph TYPE as well as the id, because
 * borrower and co-maker ids are separate sequences and collide routinely.
 */

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);

    // Both disks faked so "it is on the private disk" and "it is NOT on the
    // public one" are separate, checkable claims.
    Storage::fake('private');
    Storage::fake('public');
});

function coMakerForValidIdTest(): CoMaker
{
    return CoMaker::factory()->create([
        'borrower_id' => Borrower::factory()->create(['branch_id' => Branch::first()->id])->id,
    ]);
}

/**
 * A document written straight to the database, with its file on the private
 * disk — for owners and shapes the co-maker endpoints must refuse to touch.
 */
function storedDocumentForValidIdTest(string $ownerType, int $ownerId, string $type = 'valid_id'): Document
{
    $segment = $ownerType === CoMaker::class ? 'co_maker' : 'borrower';
    $path = "documents/{$type}/{$segment}/{$ownerId}/".Str::random(12).'.jpg';

    Storage::disk('private')->put($path, 'id-bytes');

    return Document::create([
        'documentable_type' => $ownerType,
        'documentable_id' => $ownerId,
        'type' => $type,
        'label' => 'philippine_id',
        'id_number' => '1111-2222-3333',
        'side' => 'front',
        'file_path' => $path,
        'original_filename' => 'front.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 8,
    ]);
}

function staffForValidIdTest(string $role): User
{
    $user = User::factory()->create(['branch_id' => Branch::first()->id, 'status' => 'active']);
    $user->assignRole($role);

    return $user;
}

/**
 * A JSON body with every leaf replaced by its type: keys, nesting and types,
 * with the values (ids, signed URLs, timestamps) that legitimately differ
 * between two owners taken out.
 */
function validIdJsonShape(mixed $json): mixed
{
    return is_array($json) ? array_map(validIdJsonShape(...), $json) : get_debug_type($json);
}

it('stores a legacy single-file upload as one side-less valid ID on the private disk', function () {
    $coMaker = coMakerForValidIdTest();

    $response = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'id_number' => '1234-5678-9012',
        'file' => UploadedFile::fake()->image('philsys.jpg'),
    ])->assertCreated()
        ->assertJsonPath('data.type', 'valid_id')
        ->assertJsonPath('data.label', 'philippine_id')
        ->assertJsonPath('data.id_number', '1234-5678-9012')
        ->assertJsonPath('data.side', null)
        ->assertJsonPath('data.original_filename', 'philsys.jpg');

    $document = Document::findOrFail($response->json('data.id'));

    expect($document->documentable_type)->toBe(CoMaker::class)
        ->and($document->documentable_id)->toBe($coMaker->id)
        ->and($document->file_path)->toStartWith("documents/valid_id/co_maker/{$coMaker->id}/");

    Storage::disk('private')->assertExists($document->file_path);
    Storage::disk('public')->assertMissing($document->file_path);
});

it('stores a front and back upload as one document per side on the private disk', function () {
    $coMaker = coMakerForValidIdTest();

    $response = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'drivers_license',
        'id_number' => 'N01-23-456789',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->image('back.jpg'),
    ])->assertCreated();

    $data = $response->json('data');

    expect($data)->toHaveCount(2)
        ->and(array_column($data, 'side'))->toBe(['front', 'back'])
        ->and(array_column($data, 'label'))->toBe(['drivers_license', 'drivers_license'])
        ->and(array_column($data, 'id_number'))->toBe(['N01-23-456789', 'N01-23-456789']);

    $documents = Document::whereIn('id', array_column($data, 'id'))->get();

    expect($documents)->toHaveCount(2);

    foreach ($documents as $document) {
        expect($document->documentable_type)->toBe(CoMaker::class)
            ->and($document->documentable_id)->toBe($coMaker->id)
            ->and($document->file_path)->toStartWith("documents/valid_id/co_maker/{$coMaker->id}/");

        Storage::disk('private')->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
    }
});

it('stores a front-only upload as a single front document', function () {
    $coMaker = coMakerForValidIdTest();

    $response = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ])->assertCreated();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.side'))->toBe('front');
});

it('persists custom_type_name when the type is others', function () {
    $coMaker = coMakerForValidIdTest();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'others',
        'custom_type_name' => 'Company HR ID',
        'id_number' => 'HR-7',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ])->assertCreated();

    $this->assertDatabaseHas('documents', [
        'documentable_type' => CoMaker::class,
        'documentable_id' => $coMaker->id,
        'type' => 'valid_id',
        'label' => 'others',
        'custom_type_name' => 'Company HR ID',
        'id_number' => 'HR-7',
        'side' => 'front',
    ]);
});

dataset('invalid co-maker valid-id uploads', [
    'no type' => [fn () => ['file' => UploadedFile::fake()->image('id.jpg')], 'type'],
    'others without custom_type_name' => [fn () => [
        'type' => 'others',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ], 'custom_type_name'],
    'no file at all' => [fn () => ['type' => 'philippine_id'], 'file'],
    'unsupported file type' => [fn () => [
        'type' => 'philippine_id',
        'file' => UploadedFile::fake()->create('id.xlsx', 100, 'application/vnd.ms-excel'),
    ], 'file'],
    'unsupported back file type' => [fn () => [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->create('back.xlsx', 100, 'application/vnd.ms-excel'),
    ], 'back_file'],
    'file over 10 MB' => [fn () => [
        'type' => 'philippine_id',
        'file' => UploadedFile::fake()->create('id.jpg', 10241, 'image/jpeg'),
    ], 'file'],
    'id_number over 100 characters' => [fn () => [
        'type' => 'philippine_id',
        'id_number' => str_repeat('9', 101),
        'file' => UploadedFile::fake()->image('id.jpg'),
    ], 'id_number'],
    'legacy file and front_file together' => [fn () => [
        'type' => 'philippine_id',
        'file' => UploadedFile::fake()->image('legacy.jpg'),
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ], 'file'],
]);

it('rejects an invalid upload and writes nothing', function (array $payload, string $field) {
    $coMaker = coMakerForValidIdTest();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($coMaker->documents()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with('invalid co-maker valid-id uploads');

it('deletes the files it already wrote when the database refuses the upload', function () {
    $coMaker = coMakerForValidIdTest();

    // The front row is written, then the back row fails: the transaction rolls
    // the front row back, and the front FILE must go with it — the filesystem
    // has no rollback of its own.
    Document::creating(function (Document $document) {
        if ($document->side === 'back') {
            throw new RuntimeException('simulated insert failure');
        }
    });

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->image('back.jpg'),
    ])->assertServerError();

    expect($coMaker->documents()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('lists valid IDs grouped into front and back pairs', function () {
    $coMaker = coMakerForValidIdTest();

    $front = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'id_number' => '1234-5678-9012',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->image('back.jpg'),
    ])->assertCreated()->json('data.0.id');

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'drivers_license',
        'id_number' => 'N01-23-456789',
        'front_file' => UploadedFile::fake()->image('dl-front.jpg'),
    ])->assertCreated();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'others',
        'custom_type_name' => 'Company HR ID',
        'file' => UploadedFile::fake()->image('hr.jpg'),
    ])->assertCreated();

    $items = collect($this->getJson("/api/co-makers/{$coMaker->id}/valid-ids")->assertOk()->json('data'));

    expect($items)->toHaveCount(3);

    $philId = $items->firstWhere('type', 'philippine_id');
    expect($philId['id'])->toBe($front)
        ->and($philId['id_number'])->toBe('1234-5678-9012')
        ->and($philId['front_url'])->not->toBeEmpty()
        ->and($philId['back_url'])->not->toBeEmpty();

    $license = $items->firstWhere('type', 'drivers_license');
    expect($license['front_url'])->not->toBeEmpty()
        ->and($license['back_url'])->toBeNull();

    $legacy = $items->firstWhere('type', 'others');
    expect($legacy['custom_type_name'])->toBe('Company HR ID')
        ->and($legacy['front_url'])->not->toBeEmpty()
        ->and($legacy['back_url'])->toBeNull();
});

it('returns an empty list when the co-maker has no valid IDs', function () {
    $coMaker = coMakerForValidIdTest();

    $this->getJson("/api/co-makers/{$coMaker->id}/valid-ids")
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('keeps a borrower\'s valid IDs and their co-maker\'s valid IDs apart', function () {
    $coMaker = coMakerForValidIdTest();
    $borrower = $coMaker->borrower;

    $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
        'type' => 'umid',
        'front_file' => UploadedFile::fake()->image('borrower.jpg'),
    ])->assertCreated();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('co-maker.jpg'),
    ])->assertCreated();

    expect($this->getJson("/api/borrowers/{$borrower->id}/valid-ids")->json('data.*.type'))->toBe(['umid'])
        ->and($this->getJson("/api/co-makers/{$coMaker->id}/valid-ids")->json('data.*.type'))->toBe(['philippine_id']);
});

it('never counts a co-maker\'s ID as the borrower\'s own', function () {
    // Both are `type = valid_id` documents; only the owner's morph tells them
    // apart. The registration KYC gate and `has_valid_id` must keep reading the
    // borrower's own documents, never their co-makers'.
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);
    $coMaker = CoMaker::factory()->create(['borrower_id' => $borrower->id]);

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ])->assertCreated();

    $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['valid_id']);

    $this->getJson("/api/borrowers/{$borrower->id}")
        ->assertOk()
        ->assertJsonPath('data.has_valid_id', false)
        ->assertJsonPath('data.co_makers.0.documents.0.type', 'valid_id');
});

it('deletes both sides of a valid ID and their files, and nothing else', function () {
    $coMaker = coMakerForValidIdTest();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'id_number' => '1234-5678-9012',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->image('back.jpg'),
    ])->assertCreated();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'drivers_license',
        'front_file' => UploadedFile::fake()->image('dl.jpg'),
    ])->assertCreated();

    $pair = $coMaker->documents()->where('label', 'philippine_id')->get();
    $keep = $coMaker->documents()->where('label', 'drivers_license')->sole();

    $entry = collect($this->getJson("/api/co-makers/{$coMaker->id}/valid-ids")->json('data'))
        ->firstWhere('type', 'philippine_id');

    $this->deleteJson("/api/co-makers/{$coMaker->id}/valid-ids/{$entry['id']}")
        ->assertOk()
        ->assertExactJson(['message' => 'Valid ID deleted successfully.']);

    expect($pair)->toHaveCount(2)
        ->and(Document::whereIn('id', $pair->modelKeys())->exists())->toBeFalse()
        ->and(Document::whereKey($keep->id)->exists())->toBeTrue();

    foreach ($pair as $document) {
        Storage::disk('private')->assertMissing($document->file_path);
    }

    Storage::disk('private')->assertExists($keep->file_path);
});

dataset('ids that are not this co-maker\'s valid ID', [
    'another co-maker\'s valid ID' => [function () {
        $coMaker = coMakerForValidIdTest();
        $other = coMakerForValidIdTest();

        return [$coMaker, storedDocumentForValidIdTest(CoMaker::class, $other->id)];
    }],
    'the valid ID of the borrower this co-maker guarantees' => [function () {
        $coMaker = coMakerForValidIdTest();

        return [$coMaker, storedDocumentForValidIdTest(Borrower::class, $coMaker->borrower_id)];
    }],
    'a borrower\'s valid ID whose owner id equals this co-maker\'s id' => [function () {
        $coMaker = coMakerForValidIdTest();

        return [$coMaker, storedDocumentForValidIdTest(Borrower::class, $coMaker->id)];
    }],
    'a document of this co-maker that is not a valid ID' => [function () {
        $coMaker = coMakerForValidIdTest();

        return [$coMaker, storedDocumentForValidIdTest(CoMaker::class, $coMaker->id, 'proof_of_income')];
    }],
]);

it('will not list or delete a document that is not this co-maker\'s valid ID', function (array $scenario) {
    [$coMaker, $document] = $scenario;

    $this->getJson("/api/co-makers/{$coMaker->id}/valid-ids")
        ->assertOk()
        ->assertExactJson(['data' => []]);

    $this->deleteJson("/api/co-makers/{$coMaker->id}/valid-ids/{$document->id}")
        ->assertNotFound();

    expect(Document::whereKey($document->id)->exists())->toBeTrue();
    Storage::disk('private')->assertExists($document->file_path);
})->with('ids that are not this co-maker\'s valid ID');

it('answers a foreign id exactly as it answers a missing one, as the borrower endpoint does', function () {
    // Production renders errors without debug detail; compare those bodies.
    config(['app.debug' => false]);

    $coMaker = coMakerForValidIdTest();
    $foreign = storedDocumentForValidIdTest(CoMaker::class, coMakerForValidIdTest()->id);

    $foreignAnswer = $this->deleteJson("/api/co-makers/{$coMaker->id}/valid-ids/{$foreign->id}")->assertNotFound();
    $missingAnswer = $this->deleteJson("/api/co-makers/{$coMaker->id}/valid-ids/999999")->assertNotFound();
    $borrowerAnswer = $this->deleteJson("/api/borrowers/{$coMaker->borrower_id}/valid-ids/{$foreign->id}")->assertNotFound();

    expect($foreignAnswer->getContent())->toBe($missingAnswer->getContent())
        ->and($foreignAnswer->getContent())->toBe($borrowerAnswer->getContent());
});

it('does not let the borrower endpoint delete a co-maker\'s valid ID', function () {
    $coMaker = coMakerForValidIdTest();

    $id = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ])->assertCreated()->json('data.0.id');

    $this->deleteJson("/api/borrowers/{$coMaker->borrower_id}/valid-ids/{$id}")->assertNotFound();

    expect(Document::whereKey($id)->exists())->toBeTrue();
});

it('treats a non-numeric valid ID id as not found', function () {
    $coMaker = coMakerForValidIdTest();

    $this->deleteJson("/api/co-makers/{$coMaker->id}/valid-ids/abc")->assertNotFound();
});

it('answers in exactly the shape the borrower endpoints do', function () {
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $coMaker = coMakerForValidIdTest();

    $calls = [
        'legacy upload' => fn (string $base) => $this->postJson("{$base}/valid-ids", [
            'type' => 'philippine_id',
            'id_number' => 'A-1',
            'file' => UploadedFile::fake()->image('id.jpg'),
        ]),
        'front/back upload' => fn (string $base) => $this->postJson("{$base}/valid-ids", [
            'type' => 'drivers_license',
            'id_number' => 'B-2',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
            'back_file' => UploadedFile::fake()->image('back.jpg'),
        ]),
        'list' => fn (string $base) => $this->getJson("{$base}/valid-ids"),
        'validation failure' => fn (string $base) => $this->postJson("{$base}/valid-ids", [
            'type' => 'others',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
        ]),
    ];

    foreach ($calls as $call => $send) {
        $asBorrower = $send("/api/borrowers/{$borrower->id}");
        $asCoMaker = $send("/api/co-makers/{$coMaker->id}");

        expect($asCoMaker->status())->toBe($asBorrower->status(), "{$call}: status")
            ->and(validIdJsonShape($asCoMaker->json()))->toBe(validIdJsonShape($asBorrower->json()), "{$call}: body shape");
    }

    $delete = fn (string $base) => $this->deleteJson("{$base}/valid-ids/".$this->getJson("{$base}/valid-ids")->json('data.0.id'));

    $asBorrower = $delete("/api/borrowers/{$borrower->id}");
    $asCoMaker = $delete("/api/co-makers/{$coMaker->id}");

    expect($asCoMaker->status())->toBe(200)
        ->and($asBorrower->status())->toBe(200)
        ->and($asCoMaker->json())->toBe($asBorrower->json());
});

it('serves the uploaded file through the signed link the API hands out', function () {
    $coMaker = coMakerForValidIdTest();

    [$front, $back] = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->image('back.jpg'),
    ])->assertCreated()->json('data');

    $listed = $this->getJson("/api/co-makers/{$coMaker->id}/valid-ids")->assertOk()->json('data.0');

    $links = [
        $front['id'] => [$front['url'], $listed['front_url']],
        $back['id'] => [$back['url'], $listed['back_url']],
    ];

    foreach ($links as $documentId => $urls) {
        $bytes = Storage::disk('private')->get(Document::findOrFail($documentId)->file_path);

        foreach ($urls as $url) {
            expect($url)->toContain("/api/files/documents/{$documentId}?")->toContain('signature=');

            $served = $this->get($url)->assertOk();

            expect($served->headers->get('Cache-Control'))->toContain('no-store')
                ->and($served->streamedContent())->toBe($bytes);
        }

        // The route itself is worthless without the signature.
        $this->get("/api/files/documents/{$documentId}")->assertForbidden();
    }
});

it('includes the valid IDs in the co-maker record', function () {
    $coMaker = coMakerForValidIdTest();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ])->assertCreated();

    $this->getJson("/api/co-makers/{$coMaker->id}")
        ->assertOk()
        ->assertJsonPath('data.documents.0.type', 'valid_id')
        ->assertJsonPath('data.documents.0.label', 'philippine_id')
        ->assertJsonPath('data.documents.0.side', 'front');
});

/**
 * Each role against the three endpoints, and against the co-maker routes that
 * already existed. The valid-ID endpoints must refuse exactly whom the existing
 * routes refuse: the upload is an edit of the co-maker (UpdateCoMakerRequest,
 * borrowers:update), the list is a read (show/index, borrowers:view), and the
 * delete is a deletion (destroy, borrowers:delete — as on the borrower side).
 */
dataset('co-maker valid-id roles', [
    // role => [upload, list, delete]
    'viewer: borrowers:view only' => ['viewer', 403, 200, 403],
    'loan officer: view and update, no delete' => ['loan_officer', 201, 200, 403],
    'manager: no borrower access at all' => ['manager', 403, 403, 403],
    'admin: all of it' => ['admin', 201, 200, 200],
]);

it('refuses exactly whom the existing co-maker routes refuse', function (string $role, int $upload, int $list, int $delete) {
    $coMaker = coMakerForValidIdTest();
    $disposable = coMakerForValidIdTest();

    $existing = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ])->assertCreated()->json('data.0');

    $this->actingAs(staffForValidIdTest($role));

    $uploadAnswer = $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'drivers_license',
        'front_file' => UploadedFile::fake()->image('dl.jpg'),
    ]);
    $listAnswer = $this->getJson("/api/co-makers/{$coMaker->id}/valid-ids");
    $deleteAnswer = $this->deleteJson("/api/co-makers/{$coMaker->id}/valid-ids/{$existing['id']}");

    expect($uploadAnswer->status())->toBe($upload)
        ->and($listAnswer->status())->toBe($list)
        ->and($deleteAnswer->status())->toBe($delete);

    // Pinned to the routes that were already there, not only to a reading of
    // them: refused here if and only if refused there.
    $refused = fn (TestResponse $answer) => $answer->status() === 403;

    expect($refused($uploadAnswer))->toBe($refused($this->putJson("/api/co-makers/{$coMaker->id}", [])))
        ->and($refused($listAnswer))->toBe($refused($this->getJson("/api/co-makers/{$coMaker->id}")))
        ->and($refused($deleteAnswer))->toBe($refused($this->deleteJson("/api/co-makers/{$disposable->id}")));

    // A refusal writes nothing and deletes nothing.
    if ($upload === 403) {
        expect($coMaker->documents()->where('label', 'drivers_license')->exists())->toBeFalse();
    }

    if ($delete === 403) {
        expect(Document::whereKey($existing['id'])->exists())->toBeTrue();
        Storage::disk('private')->assertExists(Document::findOrFail($existing['id'])->file_path);
    }
})->with('co-maker valid-id roles');

it('refuses anonymous callers, even one holding a live submission token', function () {
    $this->app['auth']->forgetGuards();

    // A real public registration, and a co-maker on that very borrower: the
    // token is bound to the borrower, and must still buy nothing here.
    // Co-makers are added by staff; there is no public path to their IDs.
    $registration = $this->postJson('/api/borrowers', [
        'status' => 'pending',
        'branch_id' => $this->branch->id,
        'first_name' => 'Pat',
        'last_name' => 'Applicant',
        'email' => 'pat.applicant@example.com',
        'contact_number' => '09171000099',
    ])->assertCreated();

    $coMaker = CoMaker::factory()->create(['borrower_id' => $registration->json('data.id')]);
    $existing = storedDocumentForValidIdTest(CoMaker::class, $coMaker->id);
    $token = ['X-Submission-Token' => $registration->json('data.submission_token')];

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ], $token)->assertUnauthorized();

    $this->getJson("/api/co-makers/{$coMaker->id}/valid-ids", $token)->assertUnauthorized();
    $this->deleteJson("/api/co-makers/{$coMaker->id}/valid-ids/{$existing->id}", [], $token)->assertUnauthorized();

    expect($coMaker->documents()->count())->toBe(1);
});

it('deletes a co-maker\'s documents, their files and its ID directory with the co-maker', function () {
    $coMaker = coMakerForValidIdTest();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->image('back.jpg'),
    ])->assertCreated();

    // A document from the generic upload, which must go too, as it always has.
    $this->postJson("/api/co-makers/{$coMaker->id}/documents", [
        'type' => 'proof_of_income',
        'file' => UploadedFile::fake()->image('payslip.jpg'),
    ])->assertCreated();

    $documents = $coMaker->documents()->get();
    expect($documents)->toHaveCount(3);

    $this->deleteJson("/api/co-makers/{$coMaker->id}")->assertOk();

    expect(CoMaker::whereKey($coMaker->id)->exists())->toBeFalse()
        ->and(Document::whereIn('id', $documents->modelKeys())->exists())->toBeFalse()
        ->and(Storage::disk('private')->directoryExists("documents/valid_id/co_maker/{$coMaker->id}"))->toBeFalse();

    foreach ($documents as $document) {
        Storage::disk('private')->assertMissing($document->file_path);
    }
});

it('keeps a co-maker\'s valid IDs and files when the co-maker cannot be deleted', function () {
    $coMaker = coMakerForValidIdTest();

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
        'back_file' => UploadedFile::fake()->image('back.jpg'),
    ])->assertCreated();

    // co_maker_loan.co_maker_id is restrictOnDelete: a co-maker on a loan
    // cannot be deleted. That refusal is a 500 today, as it is for a borrower
    // with loans; what matters here is that it destroys nothing on the way.
    $loan = Loan::factory()->create(['borrower_id' => $coMaker->borrower_id, 'branch_id' => $this->branch->id]);
    $loan->coMakers()->attach($coMaker->id);

    $documents = $coMaker->documents()->get();

    $this->deleteJson("/api/co-makers/{$coMaker->id}")->assertServerError();

    expect(CoMaker::whereKey($coMaker->id)->exists())->toBeTrue()
        ->and($coMaker->documents()->count())->toBe(2);

    foreach ($documents as $document) {
        Storage::disk('private')->assertExists($document->file_path);
    }
});

it('removes a borrower\'s co-makers\' ID files and directories when the borrower is deleted', function () {
    $coMaker = coMakerForValidIdTest();
    $borrower = $coMaker->borrower;

    $this->postJson("/api/co-makers/{$coMaker->id}/valid-ids", [
        'type' => 'philippine_id',
        'front_file' => UploadedFile::fake()->image('front.jpg'),
    ])->assertCreated();

    $document = $coMaker->documents()->sole();

    $this->deleteJson("/api/borrowers/{$borrower->id}")->assertOk();

    expect(CoMaker::whereKey($coMaker->id)->exists())->toBeFalse()
        ->and(Document::whereKey($document->id)->exists())->toBeFalse()
        ->and(Storage::disk('private')->directoryExists("documents/valid_id/co_maker/{$coMaker->id}"))->toBeFalse();

    Storage::disk('private')->assertMissing($document->file_path);
});
