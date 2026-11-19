<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OriginationApplicantMessage;
use App\Models\OriginationDocument;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: the documents queue and downloads (origination decision 4). The
 * queue lists submitted applications' files in the caller's branches, with
 * per-status counts. Verify, reject and request-replacement are staff writes
 * (`loan_origination:update`) on an application still under review. A
 * download (which also needs `borrowers:view` and the branch) streams the
 * stored file as an attachment, never cached, audited by ids only. Another
 * branch's document answers 404.
 */
uses(TestCase::class);

beforeEach(function () {
    Storage::fake('private');
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
});

it('lists the caller\'s branches\' submitted documents with per-status counts', function () {
    $mine = lo3Application('document_verification');
    lo3Document($mine, 'government_id', 'uploaded');
    lo3Document($mine, 'proof_of_income', 'verified');
    lo3Document(lo3Application('draft'), 'government_id');
    lo3Document(lo3Application('submitted', ['branch_id' => $this->other->id]), 'government_id');

    $response = $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))
        ->getJson('/api/loan-origination/documents?status=uploaded')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.application_id'))->toBe($mine->id)
        ->and($response->json('data.0.reference_number'))->toBe($mine->reference_number)
        ->and($response->json('data.0.label'))->toBe('Valid government ID')
        ->and($response->json('data.0.status_label'))->toBe('Uploaded')
        ->and($response->json('data.0.scan_label'))->toBe('Not scanned')
        ->and($response->json('meta.stats.uploaded'))->toBe(1)
        ->and($response->json('meta.stats.verified'))->toBe(1)
        ->and($response->json('meta.stats.total'))->toBe(2);
});

it('verifies, rejects and asks for a replacement, each audited and moving the application\'s version', function () {
    $application = lo3Application('document_verification');
    $id = lo3Document($application, 'government_id', 'uploaded');
    $income = lo3Document($application, 'proof_of_income', 'uploaded');
    $address = lo3Document($application, 'proof_of_address', 'uploaded');
    $user = lo3Staff($this->main, 'loan_origination:update');

    $this->actingAs($user)->postJson("/api/loan-origination/documents/{$id->id}/verify", ['notes' => 'Matches the applicant.'])
        ->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.verified_by_name', $user->full_name);

    $this->actingAs($user)->postJson("/api/loan-origination/documents/{$income->id}/reject", ['reason' => 'Blurry.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Blurry.');

    $this->actingAs($user)->postJson("/api/loan-origination/documents/{$address->id}/request-replacement", ['reason' => 'It is older than 3 months.'])
        ->assertOk()->assertJsonPath('data.status', 'replacement_requested');

    expect(OriginationApplicantMessage::query()->where('kind', 'document_request')->where('application_id', $application->id)->count())->toBe(1)
        ->and($application->fresh()->version)->toBeGreaterThan($application->version)
        ->and(AuditLog::query()->where('auditable_type', OriginationDocument::class)->whereIn('action', ['origination_document_verified', 'origination_document_rejected', 'origination_document_replacement_requested'])->count())->toBe(3);

    $this->actingAs($user)->postJson("/api/loan-origination/documents/{$id->id}/verify")->assertUnprocessable();
    $this->actingAs($user)->postJson("/api/loan-origination/documents/{$income->id}/reject", ['reason' => ''])->assertUnprocessable();
});

it('refuses document decisions without loan_origination:update, on a closed application, and on another branch\'s file', function () {
    $user = lo3Staff($this->main, 'loan_origination:update');
    $document = lo3Document(lo3Application('document_verification'));

    $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))->postJson("/api/loan-origination/documents/{$document->id}/verify")->assertForbidden();

    $closed = lo3Document(lo3Application('withdrawn'));
    $this->actingAs($user)->postJson("/api/loan-origination/documents/{$closed->id}/verify")->assertUnprocessable();

    $elsewhere = lo3Document(lo3Application('submitted', ['branch_id' => $this->other->id]));
    $this->actingAs($user)->postJson("/api/loan-origination/documents/{$elsewhere->id}/verify")->assertNotFound();
    $this->actingAs(lo3Staff($this->main, 'loan_origination:view', 'borrowers:view'))->getJson("/api/loan-origination/documents/{$elsewhere->id}/download")->assertNotFound();
});

it('downloads the current file as an attachment, never cached, audited by ids only', function () {
    $application = lo3Application('document_verification');
    $document = lo3Document($application, 'government_id', 'uploaded', "%PDF-1.4\n% the id\n");
    $before = AuditLog::query()->max('id') ?? 0;

    $response = $this->actingAs(lo3Staff($this->main, 'loan_origination:view', 'borrowers:view'))
        ->get("/api/loan-origination/documents/{$document->id}/download", ['Accept' => 'application/json'])->assertOk();

    expect($response->streamedContent())->toBe("%PDF-1.4\n% the id\n")
        ->and($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $audit = AuditLog::query()->where('id', '>', $before)->where('action', 'origination_document_downloaded')->sole();
    expect($audit->auditable_id)->toBe($document->id)
        ->and(json_encode($audit->new_values))->not->toContain('government_id.pdf');
});

it('answers 404 for a document without a stored file', function () {
    $application = lo3Application('document_verification');
    $document = lo3Document($application);
    Storage::disk('private')->delete($document->currentVersion->path);

    $this->actingAs(lo3Staff($this->main, 'loan_origination:view', 'borrowers:view'))
        ->getJson("/api/loan-origination/documents/{$document->id}/download")->assertNotFound();
});
