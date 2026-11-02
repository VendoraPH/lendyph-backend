<?php

use App\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * Invalid UTF-8 anywhere in the request input answers 422, app-wide, instead
 * of reaching MySQL (or a /u pattern, or json_encode) and coming back 500.
 * Valid multibyte text, uploaded files and raw bodies are untouched.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

it('refuses invalid UTF-8 in a form-encoded field with 422', function () {
    $role = Role::create(['name' => 'bytes_desc', 'guard_name' => 'web', 'is_system' => false]);

    $this->put("/api/roles/{$role->id}", ['description' => "Front desk\xFF"], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description' => 'valid UTF-8']);

    expect($role->fresh()->description)->toBeNull();
});

it('refuses invalid UTF-8 in a query string with 422', function () {
    $this->get('/api/borrowers?search=Jos%C3%28', ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['search' => 'valid UTF-8']);
});

it('refuses invalid UTF-8 nested in an array field and in a key', function () {
    $this->post('/api/roles', ['name' => 'nested_bytes', 'permissions' => ["loans:view\xFE"]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions.0' => 'valid UTF-8']);

    $this->post('/api/roles', ['name' => 'key_bytes', "note\xFF" => 'x'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);

    expect(Role::whereIn('name', ['nested_bytes', 'key_bytes'])->exists())->toBeFalse();
});

it('answers 422 as JSON even without an Accept header', function () {
    $this->post('/api/auth/login', ['login' => "admin\xC0", 'password' => 'x'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login' => 'valid UTF-8']);
});

it('answers 422 on a multipart upload with an invalid text field, and stores nothing', function () {
    Storage::fake('private');
    $loan = $this->createReleasedLoan();

    $this->post("/api/loans/{$loan->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('letter.pdf', "%PDF-1.4\n\xFF\xFE\x00binary"),
        'type' => 'policy_exception_letter',
        'label' => "Letter\xFF",
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['label' => 'valid UTF-8']);

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

it('still accepts valid multibyte text: accents, the peso sign and emoji', function () {
    $role = Role::create(['name' => 'multibyte_desc', 'guard_name' => 'web', 'is_system' => false]);
    $text = "Cajera de Jos\u{00E9}, \u{20B1}1,500.00 \u{1F389}";

    $this->put("/api/roles/{$role->id}", ['description' => $text], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.description', $text);

    expect($role->fresh()->description)->toBe($text);

    $this->getJson('/api/borrowers?search='.rawurlencode("Jos\u{00E9} \u{20B1} \u{1F389}"))
        ->assertOk();
});

it('leaves binary uploads alone: a file whose bytes are not UTF-8 uploads with a multibyte label', function () {
    Storage::fake('private');
    $loan = $this->createReleasedLoan();

    $this->post("/api/loans/{$loan->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('letter.pdf', "%PDF-1.4\n\xFF\xFE\x00\xC3\x28binary"),
        'type' => 'policy_exception_letter',
        'label' => "Carta de Jos\u{00E9} \u{20B1}",
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.label', "Carta de Jos\u{00E9} \u{20B1}");
});

it('does not read a raw non-form body as input', function () {
    $this->call('GET', '/api/roles', [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
    ], "\xFF\xFE\x00\xC3\x28")->assertOk();
});

it('refuses invalid UTF-8 on a public route before it reaches the database', function () {
    $this->post('/api/auth/login', ['login' => 'super_admin', 'password' => "pass\xFF"], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password' => 'valid UTF-8']);
});

it('sends nosniff and hex-escapes markup in the 422 body', function () {
    $response = $this->post('/api/auth/login', ['login' => 'x', 'password' => 'y', "<b>'&\"\xFF" => 'z'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())
        ->not->toContain('<b>')
        ->toContain('\u003Cb\u003E')
        ->toContain('\u0027')
        ->toContain('\u0026')
        ->toContain('\u0022');
});
