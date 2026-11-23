<?php

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\OriginationApplicantMessage;
use App\Models\OriginationApplication;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\LoanOrigination\Staff\ApplicantMessages;
use App\Services\LoanOrigination\Staff\OriginationStaffNotices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3 review fixes (notes/phase6-run/review-lo-b3.md):
 * - the retention sweep never erases an application that became a loan;
 * - a download needs the contacts rule (`borrowers:view` and the branch),
 *   and file names are hidden without it;
 * - an information request clears a reset document's verifier and audits
 *   each reset;
 * - the dashboard's loan figures need `loans:view`;
 * - an erased application's staff notices go with it;
 * - the applicant side of a message names no staff;
 * - every staff route answers 404 for another branch's record, and a user
 *   with no branch sees nothing.
 */
uses(TestCase::class);

beforeEach(function () {
    Storage::fake('private');
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
});

afterEach(fn () => Carbon::setTestNow());

it('never erases a rejected or withdrawn application that became a loan', function () {
    $rejected = lo3Application('rejected', ['decided_at' => now()->subDays(400), 'loan_id' => Loan::factory()->create()->id]);
    $withdrawn = lo3Application('withdrawn', ['decided_at' => now()->subDays(400), 'loan_id' => Loan::factory()->create()->id]);
    $plain = lo3Application('rejected', ['decided_at' => now()->subDays(400)]);

    $this->artisan('loan-origination:retention')->assertSuccessful();

    expect(OriginationApplication::query()->whereKey($rejected->id)->exists())->toBeTrue()
        ->and(OriginationApplication::query()->whereKey($withdrawn->id)->exists())->toBeTrue()
        ->and(OriginationApplication::query()->whereKey($plain->id)->exists())->toBeFalse();
});

it('refuses a download, in words, to a caller who sees contacts masked, and hides file names from them', function () {
    $application = lo3Application('document_verification');
    $document = lo3Document($application, 'government_id');
    $masked = lo3Staff($this->main, 'loan_origination:view');

    $this->actingAs($masked)->getJson("/api/loan-origination/documents/{$document->id}/download")
        ->assertForbidden()
        ->assertJsonPath('message', 'Documents can show an applicant\'s address and contacts, so downloading them needs the permission to view members.');

    $queue = $this->actingAs($masked)->getJson('/api/loan-origination/documents')->assertOk();
    $detail = $this->actingAs($masked)->getJson("/api/loan-origination/applications/{$application->id}")->assertOk();
    expect($queue->json('data.0.file_name'))->toBeNull()
        ->and($queue->json('data.0.versions.0.file_name'))->toBeNull()
        ->and($detail->json('data.documents.0.file_name'))->toBeNull()
        ->and($detail->json('data.documents.0.versions.0.file_name'))->toBeNull();

    $allowed = lo3Staff($this->main, 'loan_origination:view', 'borrowers:view');
    $this->actingAs($allowed)->get("/api/loan-origination/documents/{$document->id}/download", ['Accept' => 'application/json'])->assertOk();
    expect($this->actingAs($allowed)->getJson('/api/loan-origination/documents')->json('data.0.file_name'))->toBe('government_id.pdf');
});

it('clears a reset document\'s verifier and audits each reset on an information request', function () {
    $application = lo3Application('document_verification');
    $verifier = lo3Staff($this->main, 'loan_origination:update');
    $income = lo3Document($application, 'proof_of_income', 'verified');
    $income->forceFill(['verified_by' => $verifier->id, 'verified_at' => now()])->save();
    $id = lo3Document($application, 'government_id', 'uploaded');
    $before = AuditLog::query()->max('id') ?? 0;

    $this->actingAs($verifier)->postJson("/api/loan-origination/applications/{$application->id}/request-information", [
        'message' => 'Send both again.', 'document_types' => ['proof_of_income', 'government_id'], 'version' => $application->version,
    ])->assertOk();

    $income->refresh();
    expect($income->status)->toBe('replacement_requested')
        ->and($income->verified_by)->toBeNull()
        ->and($income->verified_at)->toBeNull();

    $resets = AuditLog::query()->where('id', '>', $before)->where('action', 'origination_document_replacement_requested')->get();
    expect($resets->pluck('auditable_id')->all())->toEqualCanonicalizing([$income->id, $id->id])
        ->and(json_encode($resets->first()->new_values))->not->toContain('Send both');
});

it('reads the dashboard\'s loan figures only with loans:view', function () {
    $without = $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))->getJson('/api/loan-origination/dashboard')->assertOk();
    expect($without->json('data.kpis.amount_approved'))->toBeNull()
        ->and($without->json('data.previous.amount_approved'))->toBeNull()
        ->and($without->json('data.turnaround'))->toBeNull();

    $with = $this->actingAs(lo3Staff($this->main, 'loan_origination:view', 'loans:view'))->getJson('/api/loan-origination/dashboard')->assertOk();
    expect($with->json('data.kpis.amount_approved'))->toBe('0.00')
        ->and($with->json('data.turnaround.approved_to_released.loans'))->toBe(0);
});

it('deletes an erased application\'s staff notices, and keeps the others', function () {
    $officer = lo3Staff($this->main, 'loan_origination:update');
    $erased = lo3Application('rejected', ['decided_at' => now()->subDays(400)]);
    $kept = lo3Application('submitted');

    foreach ([$erased, $kept] as $application) {
        $officer->notify(new StaffNotification(OriginationStaffNotices::ASSIGNED, 'Online application assigned to you', 'x', '/', ['application_id' => $application->id]));
    }

    $this->artisan('loan-origination:retention')->assertSuccessful();

    $left = DB::table('notifications')->where('notifiable_id', $officer->id)->pluck('data')->map(fn ($d) => json_decode($d, true)['data']['application_id']);
    expect($left->all())->toBe([$kept->id]);
});

it('shows the applicant side of a message without any staff name', function () {
    $application = lo3Application('additional_info_required');
    $author = lo3Staff($this->main, 'loan_origination:update');
    $message = OriginationApplicantMessage::query()->create([
        'application_id' => $application->id, 'kind' => 'information_request', 'channel' => 'sms', 'body' => 'Send your payslip.',
        'document_types' => ['proof_of_income'], 'delivery' => 'logged', 'user_id' => $author->id,
    ]);

    $public = ApplicantMessages::forApplicant($message->fresh());

    expect(array_keys($public))->toEqualCanonicalizing(['kind', 'kind_label', 'body', 'document_types', 'created_at'])
        ->and(json_encode($public))->not->toContain($author->full_name)
        ->and($public['body'])->toBe('Send your payslip.');
});

it('answers 404 on every staff route for another branch\'s record', function (string $method, string $path) {
    $application = lo3Application('document_verification', ['branch_id' => $this->other->id]);
    $document = lo3Document($application);
    $user = lo3Staff($this->main, 'loan_origination:view', 'loan_origination:update', 'borrowers:view', 'loans:reject');
    $url = '/api/loan-origination/'.str_replace(['{a}', '{d}'], [$application->id, $document->id], $path);
    $body = ['to' => 'withdrawn', 'version' => $application->version, 'body' => 'x', 'officer_id' => null, 'message' => 'x', 'borrower_id' => null, 'reason' => 'x'];

    $this->actingAs($user)->json($method, $url, $method === 'GET' ? [] : $body)->assertNotFound();
})->with([
    'detail' => ['GET', 'applications/{a}'],
    'transition' => ['POST', 'applications/{a}/transition'],
    'notes' => ['POST', 'applications/{a}/notes'],
    'assign' => ['POST', 'applications/{a}/assign'],
    'request information' => ['POST', 'applications/{a}/request-information'],
    'remind' => ['POST', 'applications/{a}/remind'],
    'member matches' => ['GET', 'applications/{a}/member-matches'],
    'link member' => ['POST', 'applications/{a}/link-member'],
    'verify' => ['POST', 'documents/{d}/verify'],
    'reject' => ['POST', 'documents/{d}/reject'],
    'request replacement' => ['POST', 'documents/{d}/request-replacement'],
    'download' => ['GET', 'documents/{d}/download'],
]);

it('shows a user with no branch no applications, documents or dashboard figures', function () {
    lo3Application('submitted', ['started_at' => now()->subDay(), 'submitted_at' => now()->subDay()]);
    lo3Document(lo3Application('document_verification'));
    Borrower::factory()->create(['branch_id' => $this->main->id]);
    $role = Role::query()->create(['name' => 'spec-lo3-nobranch:'.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(['loan_origination:view']);
    $user = tap(User::factory()->create(['branch_id' => null, 'status' => 'active']), fn (User $u) => $u->assignRole($role));

    $list = $this->actingAs($user)->getJson('/api/loan-origination/applications')->assertOk();
    expect($list->json('data'))->toBe([])->and($list->json('meta.stats.total'))->toBe(0);

    expect($this->actingAs($user)->getJson('/api/loan-origination/documents')->assertOk()->json('meta.stats.total'))->toBe(0);

    $dashboard = $this->actingAs($user)->getJson('/api/loan-origination/dashboard')->assertOk();
    expect($dashboard->json('data.kpis.total'))->toBe(0)
        ->and($dashboard->json('data.kpis.new'))->toBe(0)
        ->and($dashboard->json('data.recent'))->toBe([]);
});
