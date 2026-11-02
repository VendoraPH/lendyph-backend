<?php

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Document;
use App\Models\Role;
use App\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * Client-supplied text that lands in the database outside the request input:
 * the User-Agent header on every audit row, and an upload's filename. Both
 * are stored as valid UTF-8 within their column instead of failing the
 * insert. And the audit CSV export neutralises spreadsheet formulas.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

it('stores an invalid UTF-8 User-Agent scrubbed, so the audited change still succeeds', function () {
    $role = Role::create(['name' => 'agent_probe', 'guard_name' => 'web', 'is_system' => false]);

    $this->withHeader('User-Agent', "Mozilla\xFF/5.0")
        ->putJson("/api/roles/{$role->id}", ['description' => 'Front desk'])
        ->assertSuccessful();

    $row = AuditLog::where('action', 'updated')->where('auditable_id', $role->id)->where('auditable_type', $role->getMorphClass())->latest('id')->firstOrFail();

    expect(mb_check_encoding($row->user_agent, 'UTF-8'))->toBeTrue()
        ->and($row->user_agent)->toStartWith('Mozilla')->toEndWith('/5.0')
        ->and($role->fresh()->description)->toBe('Front desk');
});

it('caps a long User-Agent', function () {
    $role = Role::create(['name' => 'long_agent_probe', 'guard_name' => 'web', 'is_system' => false]);

    $this->withHeader('User-Agent', str_repeat("\u{20B1}", 2000))
        ->putJson("/api/roles/{$role->id}", ['description' => 'Teller'])
        ->assertSuccessful();

    $row = AuditLog::where('action', 'updated')->where('auditable_id', $role->id)->where('auditable_type', $role->getMorphClass())->latest('id')->firstOrFail();

    expect($row->user_agent)->toBe(str_repeat("\u{20B1}", AuditLogService::HEADER_MAX_LENGTH));
});

it('stores an invalid UTF-8 upload filename scrubbed on a document upload', function () {
    Storage::fake('private');
    $loan = $this->createReleasedLoan();

    $this->post("/api/loans/{$loan->id}/documents", [
        'file' => UploadedFile::fake()->create("letter\xFF.pdf", 100, 'application/pdf'),
        'type' => 'policy_exception_letter',
    ], ['Accept' => 'application/json'])->assertCreated();

    $name = Document::query()->latest('id')->value('original_filename');

    expect(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
        ->and($name)->toStartWith('letter')->toEndWith('.pdf');
});

it('stores an invalid UTF-8 upload filename scrubbed on a valid ID upload', function () {
    Storage::fake('private');
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

    $this->post("/api/borrowers/{$borrower->id}/valid-ids", [
        'file' => UploadedFile::fake()->image("phil\xC3\x28sys.jpg"),
        'type' => 'PhilSys ID',
    ], ['Accept' => 'application/json'])->assertCreated();

    $name = Document::query()->latest('id')->value('original_filename');

    expect(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
        ->and($name)->toStartWith('phil')->toEndWith('sys.jpg');
});

it('removes the stored file when the document row cannot be saved', function () {
    Storage::fake('private');
    $loan = $this->createReleasedLoan();

    Document::creating(fn () => throw new RuntimeException('insert failed'));

    $this->post("/api/loans/{$loan->id}/documents", [
        'file' => UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf'),
        'type' => 'policy_exception_letter',
    ], ['Accept' => 'application/json'])->assertServerError();

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

it('neutralises spreadsheet formulas in the audit CSV export', function () {
    AuditLogService::log('probe_formula', null, null, ['note' => 'x'], '=HYPERLINK("http://evil.example","click")');
    AuditLogService::log('probe_formula', null, null, null, '@SUM(1+1)');

    $csv = $this->get('/api/audit-logs/export?action=probe_formula', ['Accept' => 'application/json'])
        ->assertOk()
        ->streamedContent();

    $descriptions = collect(explode("\n", trim($csv)))
        ->slice(1)
        ->map(fn (string $line) => str_getcsv($line, escape: '')[5] ?? null)
        ->values()
        ->all();

    expect($descriptions)->toEqualCanonicalizing([
        "'\t=HYPERLINK(\"http://evil.example\",\"click\")",
        "'\t@SUM(1+1)",
    ]);
});
