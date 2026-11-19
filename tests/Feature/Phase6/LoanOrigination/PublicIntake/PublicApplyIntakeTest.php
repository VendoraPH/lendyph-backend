<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OriginationApplication;
use App\Models\OriginationConsent;
use App\Models\OriginationDocument;
use App\Models\OriginationLink;
use App\Models\OriginationNote;
use App\Models\OriginationSetting;
use App\Models\User;
use App\Services\LoanOrigination\Intake\ApplicantIntake;
use App\Services\LoanService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase6\LoanOrigination\PublicIntake\PublicApplyHelpers;
use Tests\TestCase;

/*
 * LO-B2: the applicant's draft, estimate, uploads, submit and tracking
 * (origination decisions 2–5, 8, 9 and 18, audit §3.5). The draft is checked
 * against LO-B1's whitelist and stored encrypted; the server works out
 * completion and the declared totals; the estimate is the loan form's own
 * preview; uploads go through LO-B1's file store; submit is idempotent and
 * records every consent; tracking answers a whitelist only.
 */
uses(TestCase::class, PublicApplyHelpers::class);

beforeEach(function () {
    Storage::fake('private');
    $this->link = $this->enableIntake();
    $this->product = $this->linkProduct($this->link);
    $this->token = $this->startApplication($this->link);
});

afterEach(fn () => Carbon::setTestNow());

function loPubSave(TestCase $test, OriginationLink $link, string $session, array $draft): TestResponse
{
    return $test->putJson("/api/public-apply/{$link->token}/draft", ['draft' => $draft, 'last_completed_step' => 'loan'], ['X-Application-Session' => $session]);
}

function loPubUploadRequired(TestCase $test, OriginationLink $link, string $session): void
{
    foreach (['government_id' => 1, 'proof_of_income' => 2] as $type => $seed) {
        $test->post("/api/public-apply/{$link->token}/documents", ['type' => $type, 'file' => $test->png("{$type}.png", $seed)], ['X-Application-Session' => $session, 'Accept' => 'application/json'])->assertCreated();
    }
}

it('saves the draft through the whitelist, encrypted, with the server\'s completion and totals', function () {
    $draft = $this->completeDraft($this->product->id);
    $draft['personal']['is_admin'] = true;

    $data = loPubSave($this, $this->link, $this->token, $draft)->assertOk()->json('data');

    expect($data['draft']['personal'])->not->toHaveKey('is_admin')
        ->and($data['draft']['personal']['first_name'])->toBe('Juana')
        ->and($data['completion_percent'])->toBe(86)
        ->and($data['last_completed_step'])->toBe('loan')
        ->and($data['declared_totals']['monthly_income'])->toBe('25000.00')
        ->and($data['declared_totals']['monthly_expenses'])->toBe('13500.00')
        ->and($data['missing_fields'])->toBe([]);

    $application = OriginationApplication::query()->sole();
    expect($application->applicant_name)->toBe('Juana Dela Cruz')
        ->and($application->loan_product_id)->toBe($this->product->id)
        ->and((string) $application->requested_amount)->toBe('20000.00')
        ->and($application->requested_term)->toBe(6)
        ->and($application->last_saved_at)->not->toBeNull()
        ->and(DB::table('origination_applications')->value('draft'))->not->toContain('Juana');
});

it('refuses a field of the wrong shape, and a product the link doesn\'t offer', function () {
    $draft = $this->completeDraft($this->product->id);
    $draft['personal']['first_name'] = str_repeat('x', 101);
    loPubSave($this, $this->link, $this->token, $draft)->assertUnprocessable()->assertJsonValidationErrors('draft.personal.first_name');

    $other = $this->onlineProduct();
    loPubSave($this, $this->link, $this->token, $this->completeDraft($other->id))
        ->assertUnprocessable()->assertJsonValidationErrors('draft.loan.loan_product_id');
});

it('keeps the link\'s branch, and otherwise takes the applicant\'s active branch', function () {
    $north = Branch::factory()->create(['name' => 'North', 'code' => 'NB']);
    $draft = $this->completeDraft($this->product->id);
    $draft['personal']['branch_id'] = $north->id;

    loPubSave($this, $this->link, $this->token, $draft)->assertOk();
    expect(OriginationApplication::query()->sole()->branch_id)->toBe($this->link->branch_id);

    $this->link->forceFill(['branch_id' => null])->save();
    loPubSave($this, $this->link, $this->token, $draft)->assertOk();
    expect(OriginationApplication::query()->sole()->branch_id)->toBe($north->id);

    $north->forceFill(['is_active' => false])->save();
    loPubSave($this, $this->link, $this->token, $draft)->assertUnprocessable()->assertJsonValidationErrors('draft.personal.branch_id');
});

it('brings an incomplete draft back to draft when it is saved again', function () {
    $application = OriginationApplication::query()->sole();
    OriginationApplication::withinTransition(function () use ($application) {
        $application->status = 'incomplete';
        $application->save();
    });

    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertOk()->assertJsonPath('data.status', 'draft');
    expect(OriginationApplication::query()->sole()->history()->latest('id')->value('actor_type'))->toBe('applicant');
});

it('works out the estimate with the loan form\'s own preview, and saves nothing', function () {
    $before = OriginationApplication::query()->sole()->version;

    $data = $this->postJson("/api/public-apply/{$this->link->token}/estimate", [
        'loan_product_id' => $this->product->id, 'amount' => 20000, 'term' => 6, 'frequency' => 'monthly',
    ], $this->applicantSession($this->token))->assertOk()->json('data');

    $preview = app(LoanService::class)->formPreview([
        'loan_product_id' => $this->product->id, 'principal_amount' => 20000, 'interest_rate' => $this->product->interest_rate,
        'term' => 6, 'frequency' => 'monthly', 'start_date' => Carbon::now('Asia/Manila')->toDateString(),
    ]);

    expect($data['installments_count'])->toBe(count($preview['amortization']['rows']))
        ->and($data['total_interest'])->toEqual($preview['amortization']['totals']['interest_due'])
        ->and($data['total_repayment'])->toEqual(20000 + $preview['amortization']['totals']['interest_due'])
        ->and($data['total_charges'])->toEqual($preview['deductions']['total_deductions'])
        ->and($data['net_proceeds'])->toEqual($preview['deductions']['net_proceeds'])
        ->and($data['schedule'][0]['amount'])->toEqual($preview['amortization']['rows'][0]['total_due'])
        ->and($data['frequency'])->toBe('monthly')
        ->and($data['assumptions'])->not->toBeEmpty();

    expect(OriginationApplication::query()->sole()->version)->toBe($before);
});

it('refuses an estimate outside the product or for a product the link doesn\'t offer', function () {
    $url = "/api/public-apply/{$this->link->token}/estimate";
    $base = ['loan_product_id' => $this->product->id, 'amount' => 20000, 'term' => 6, 'frequency' => 'monthly'];

    $this->postJson($url, [...$base, 'term' => 40], $this->applicantSession($this->token))->assertUnprocessable()->assertJsonValidationErrors('term');
    $this->postJson($url, [...$base, 'amount' => 900000], $this->applicantSession($this->token))->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson($url, [...$base, 'frequency' => 'weekly'], $this->applicantSession($this->token))->assertUnprocessable()->assertJsonValidationErrors('frequency');
    $this->postJson($url, [...$base, 'loan_product_id' => $this->onlineProduct()->id], $this->applicantSession($this->token))->assertUnprocessable()->assertJsonValidationErrors('loan_product_id');
});

it('uploads a file through the file store, as a document with a random id', function () {
    $response = $this->post("/api/public-apply/{$this->link->token}/documents", ['type' => 'government_id', 'file' => $this->png()], [...$this->applicantSession($this->token), 'Accept' => 'application/json'])
        ->assertCreated();

    expect($response->json('data.id'))->toBeString()->toMatch('/^[0-9a-z]{26}$/')
        ->and($response->json('data.type'))->toBe('government_id')
        ->and($response->json('data.label'))->toBe('Valid government ID')
        ->and($response->json('data.status'))->toBe('uploaded')
        ->and($response->json('data.preview_url'))->toBeNull()
        ->and($response->json('data.file_name'))->toEndWith('.png');

    $state = $this->getJson("/api/public-apply/{$this->link->token}/draft", $this->applicantSession($this->token))->json('data.documents');
    expect(collect($state)->firstWhere('type', 'government_id')['status'])->toBe('uploaded')
        ->and(collect($state)->firstWhere('type', 'proof_of_income')['status'])->toBe('not_submitted');

    expect(AuditLog::query()->where('action', 'document_uploaded')->sole()->actor_type)->toBe('applicant');
});

it('refuses an unknown document type, a disguised file and a missing file', function () {
    $headers = [...$this->applicantSession($this->token), 'Accept' => 'application/json'];
    $url = "/api/public-apply/{$this->link->token}/documents";

    $this->post($url, ['type' => 'selfie', 'file' => $this->png()], $headers)->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->post($url, ['type' => 'government_id', 'file' => UploadedFile::fake()->createWithContent('id.png', 'not a picture')], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post($url, ['type' => 'government_id'], $headers)->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('removes the applicant\'s own file while the draft is open, and nobody else\'s', function () {
    $headers = [...$this->applicantSession($this->token), 'Accept' => 'application/json'];
    $id = $this->post("/api/public-apply/{$this->link->token}/documents", ['type' => 'government_id', 'file' => $this->png()], $headers)->json('data.id');

    $this->deleteJson("/api/public-apply/{$this->link->token}/documents/nope", [], $this->applicantSession($this->token))->assertNotFound();
    $this->deleteJson("/api/public-apply/{$this->link->token}/documents/{$id}", [], $this->applicantSession($this->token))->assertNoContent();

    expect(OriginationDocument::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('submits once: checks the draft, documents and notices, records the consents and gives a reference', function () {
    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertOk();
    $url = "/api/public-apply/{$this->link->token}/submit";

    $this->postJson($url, $this->consents(), $this->applicantSession($this->token))->assertUnprocessable()->assertJsonValidationErrors('documents');
    loPubUploadRequired($this, $this->link, $this->token);
    $this->postJson($url, $this->consents(['terms_version' => '2025.1']), $this->applicantSession($this->token))
        ->assertUnprocessable()->assertJsonValidationErrors('terms_version');

    $first = $this->postJson($url, $this->consents(['marketing_opt_in' => true]), $this->applicantSession($this->token))->assertOk()->json('data');
    $again = app(ApplicantIntake::class)->submit(OriginationApplication::query()->sole(), $this->link, $this->consents(), request());

    expect($first['reference_number'])->toMatch('/^OA-\d{4}-000001$/')
        ->and($again['reference_number'])->toBe($first['reference_number']);

    $application = OriginationApplication::query()->sole();
    expect($application->status)->toBe('submitted')
        ->and($application->submitted_at)->not->toBeNull()
        ->and($this->link->fresh()->submitted)->toBe(1);

    $submitConsents = OriginationConsent::query()->where('stage', 'submit')->orderBy('id')->get();
    expect($submitConsents->pluck('type')->all())->toBe(['privacy_notice', 'terms', 'declaration', 'marketing'])
        ->and($submitConsents->firstWhere('type', 'marketing')->accepted)->toBeTrue()
        ->and($submitConsents->firstWhere('type', 'terms')->text_hash)->toBe(hash('sha256', 'Draft terms text'));

    expect(AuditLog::query()->where('action', 'submitted')->where('auditable_type', OriginationApplication::class)->count())->toBe(1);
});

it('lists what blocks a submit in words', function () {
    $draft = $this->completeDraft($this->product->id);
    $draft['personal']['gender'] = '';
    loPubSave($this, $this->link, $this->token, $draft)->assertOk();
    loPubUploadRequired($this, $this->link, $this->token);

    $this->postJson("/api/public-apply/{$this->link->token}/submit", $this->consents(), $this->applicantSession($this->token))
        ->assertUnprocessable()->assertJsonPath('errors', ['draft.personal.gender' => ['Choose your gender.']]);
});

it('ends the intake session at submit and freezes the draft', function () {
    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertOk();
    loPubUploadRequired($this, $this->link, $this->token);
    $this->postJson("/api/public-apply/{$this->link->token}/submit", $this->consents(), $this->applicantSession($this->token))->assertOk();

    $this->getJson("/api/public-apply/{$this->link->token}/draft", $this->applicantSession($this->token))->assertUnauthorized()
        ->assertJsonPath('code', 'session_expired');
    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertUnauthorized();
    $this->post("/api/public-apply/{$this->link->token}/documents", ['type' => 'proof_of_address', 'file' => $this->png()], [...$this->applicantSession($this->token), 'Accept' => 'application/json'])->assertUnauthorized();

    expect(fn () => app(ApplicantIntake::class)->saveDraft(OriginationApplication::query()->sole(), $this->link, $this->completeDraft($this->product->id)))
        ->toThrow(HttpResponseException::class);
    expect(OriginationApplication::query()->sole()->status)->toBe('submitted');
});

it('signs the applicant out, ending the session', function () {
    $this->postJson("/api/public-apply/{$this->link->token}/logout", [], $this->applicantSession($this->token))->assertNoContent();

    $this->getJson("/api/public-apply/{$this->link->token}/draft", $this->applicantSession($this->token))->assertUnauthorized();
    expect(DB::table('origination_sessions')->whereNull('ended_at')->count())->toBe(0);
});

it('refuses a submit once the link stops taking applications', function () {
    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertOk();
    loPubUploadRequired($this, $this->link, $this->token);
    OriginationSetting::current()->forceFill(['online_applications_enabled' => false])->save();

    $this->postJson("/api/public-apply/{$this->link->token}/submit", $this->consents(), $this->applicantSession($this->token))->assertStatus(409);
    expect(OriginationApplication::query()->sole()->status)->toBe('draft');
});

it('tracks with a code and the reference, answering only the whitelist', function () {
    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertOk();
    loPubUploadRequired($this, $this->link, $this->token);
    $reference = $this->postJson("/api/public-apply/{$this->link->token}/submit", $this->consents(), $this->applicantSession($this->token))->json('data.reference_number');

    $application = OriginationApplication::query()->sole();
    OriginationNote::query()->create(['application_id' => $application->id, 'user_id' => User::factory()->create()->id, 'body' => 'Internal: call employer']);
    OriginationApplication::withinTransition(function () use ($application) {
        $application->status = 'additional_info_required';
        $application->save();
    });
    $application->documents()->where('type', 'proof_of_income')->update(['status' => 'replacement_requested']);

    $this->getJson("/api/public-apply/{$this->link->token}/track", $this->applicantSession($this->token))->assertUnauthorized();

    $this->travel(61)->seconds();
    $track = $this->startApplication($this->link, 'track', ['reference_number' => $reference]);
    $response = $this->getJson("/api/public-apply/{$this->link->token}/track", $this->applicantSession($track))->assertOk();
    $data = $response->json('data');

    expect(array_keys($data))->toBe([
        'reference_number', 'submitted_at', 'status', 'status_message', 'outstanding_requirements',
        'requested_actions', 'replaceable_documents', 'decision_notice', 'contact',
    ])
        ->and($data['reference_number'])->toBe($reference)
        ->and($data['status'])->toBe('additional_info_required')
        ->and(array_column($data['replaceable_documents'], 'type'))->toBe(['proof_of_income'])
        ->and($data['requested_actions'])->toBe(['Upload a new copy of your Proof of income.'])
        ->and($data['contact'])->toBe(['phone' => '0281234567', 'email' => 'loans@example.test'])
        ->and($response->getContent())->not->toContain('Internal')->not->toContain('Juana');

    $this->putJson("/api/public-apply/{$this->link->token}/draft", ['draft' => []], $this->applicantSession($track))->assertUnauthorized();

    $this->post("/api/public-apply/{$this->link->token}/documents", ['type' => 'government_id', 'file' => $this->png('x.png', 9)], [...$this->applicantSession($track), 'Accept' => 'application/json'])->assertStatus(409);
    $this->post("/api/public-apply/{$this->link->token}/documents", ['type' => 'proof_of_income', 'file' => $this->png('y.png', 9)], [...$this->applicantSession($track), 'Accept' => 'application/json'])->assertCreated();
});

it('reads statuses to the applicant in their own words', function (string $status, string $facing) {
    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertOk();
    loPubUploadRequired($this, $this->link, $this->token);
    $reference = $this->postJson("/api/public-apply/{$this->link->token}/submit", $this->consents(), $this->applicantSession($this->token))->json('data.reference_number');
    $application = OriginationApplication::query()->sole();
    OriginationApplication::withinTransition(function () use ($application, $status) {
        $application->status = $status;
        $application->applicant_message = $status === 'rejected' ? 'We can\'t offer a loan at this time.' : null;
        $application->save();
    });

    $this->travel(61)->seconds();
    $track = $this->startApplication($this->link, 'track', ['reference_number' => $reference]);
    $data = $this->getJson("/api/public-apply/{$this->link->token}/track", $this->applicantSession($track))->json('data');

    expect($data['status'])->toBe($facing);
    if ($status === 'rejected') {
        expect($data['decision_notice'])->toBe('We can\'t offer a loan at this time.');
    }
})->with([
    ['submitted', 'received'],
    ['document_verification', 'under_review'],
    ['under_assessment', 'under_review'],
    ['pending_approval', 'under_review'],
    ['approved', 'approved'],
    ['converted', 'approved'],
    ['rejected', 'not_approved'],
    ['withdrawn', 'withdrawn'],
]);

it('never lets one applicant\'s session reach another application', function () {
    $mine = OriginationApplication::query()->sole();
    $headers = fn (string $session) => [...$this->applicantSession($session), 'Accept' => 'application/json'];

    $this->travel(61)->seconds();
    $theirs = $this->startApplication($this->link, 'apply', [], $this->secondStaffMobile);
    $draft = $this->completeDraft($this->product->id);
    $draft['personal']['first_name'] = 'Pedro';
    loPubSave($this, $this->link, $theirs, $draft)->assertOk();
    $theirDocument = $this->post("/api/public-apply/{$this->link->token}/documents", ['type' => 'government_id', 'file' => $this->png('t.png', 7)], $headers($theirs))->json('data.id');
    $other = OriginationApplication::query()->whereKeyNot($mine->id)->sole();

    $response = $this->getJson("/api/public-apply/{$this->link->token}/draft", $this->applicantSession($this->token))->assertOk()
        ->assertJsonPath('data.verified_contact.mobile', '63917*****67');
    expect($response->getContent())->not->toContain('Pedro')->not->toContain((string) $theirDocument);

    $this->deleteJson("/api/public-apply/{$this->link->token}/documents/{$theirDocument}", [], $this->applicantSession($this->token))->assertNotFound();
    loPubSave($this, $this->link, $this->token, $this->completeDraft($this->product->id))->assertOk();

    expect($other->fresh()->draft['personal']['first_name'])->toBe('Pedro')
        ->and($other->documents()->count())->toBe(1)
        ->and($mine->fresh()->draft['personal']['first_name'])->toBe('Juana')
        ->and(OriginationApplication::query()->count())->toBe(2);
});
