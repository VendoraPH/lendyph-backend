<?php

use App\Models\Borrower;
use App\Models\BorrowerSubmissionToken;
use App\Models\Branch;
use App\Models\User;
use App\Services\BorrowerSubmissionTokenService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A lost response must not cost the applicant their registration.
 *
 * A coop member submitted the public form from a Facebook in-app browser, hit a
 * 30-second timeout, never saw the 201, and their retry was refused as a
 * duplicate of the row their own first attempt had already written. Production
 * logged two POST /api/borrowers -> 422 from an `FB_IAB` user agent. The
 * frontend caches the created borrower, but only once it has SEEN a response,
 * so the key has to travel with the request instead.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    Storage::fake('private');

    // Rate-limiter counters are cache entries; a spec that spends quota must
    // not fail the next one and read as flake.
    Cache::flush();

    // Deliberately NOT calling actingAs(): this is the anonymous flow.
});

/**
 * The payload the public registration form actually posts.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function idempotentApplicantPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'pending',
        'branch_id' => Branch::first()->id,
        'first_name' => 'Marites',
        'middle_name' => 'Reyes',
        'last_name' => 'Bautista',
        'birthdate' => '1988-03-04',
        'gender' => 'female',
        'civil_status' => 'married',
        'contact_number' => '09171239876',
        'email' => 'marites.bautista@example.com',
        'address' => '14 Narra St',
        'city' => 'Butuan',
        'province' => 'Agusan del Norte',
        'employer_or_business' => 'Bautista Sari-Sari',
        'monthly_income' => 18000,
        'pledge_amount' => 500,
    ], $overrides);
}

it('returns the same borrower when a submission is retried with the same key', function () {
    $key = (string) Str::uuid();
    $payload = idempotentApplicantPayload(['registration_uuid' => $key]);

    $first = $this->postJson('/api/borrowers', $payload)->assertCreated();
    $countAfterFirst = Borrower::count();

    // The applicant never saw the response above — this is the retry.
    $retry = $this->postJson('/api/borrowers', $payload)->assertCreated();

    expect($retry->json('data.id'))->toBe($first->json('data.id'));
    expect(Borrower::count())->toBe($countAfterFirst);
    expect(Borrower::where('registration_uuid', $key)->count())->toBe(1);

    // Same 201 shape as a first-time submission, not a special "already exists"
    // envelope the frontend would have to learn.
    $retry->assertJsonStructure(['success', 'data' => ['id', 'submission_token', 'expires_at']])
        ->assertJsonPath('success', true);
});

it('issues a working upload token on the retry', function () {
    // The retry has to be genuinely usable, not merely non-fatal: the applicant
    // still has a photo and valid IDs to attach to that borrower id.
    $payload = idempotentApplicantPayload(['registration_uuid' => (string) Str::uuid()]);

    $this->postJson('/api/borrowers', $payload)->assertCreated();
    $retry = $this->postJson('/api/borrowers', $payload)->assertCreated();

    $borrowerId = $retry->json('data.id');

    $this->postJson(
        "/api/borrowers/{$borrowerId}/photo",
        ['photo' => UploadedFile::fake()->image('me.jpg')],
        ['X-Submission-Token' => $retry->json('data.submission_token')],
    )->assertOk();

    expect(Borrower::find($borrowerId)->photo_path)->not->toBeNull();
});

it('still rejects a genuine duplicate applicant that carries no key', function () {
    // The guard that proves idempotency did not quietly defang
    // NoDuplicateBorrower: without a key there is nothing to ignore, so a
    // second person with the same name is still refused.
    $this->postJson('/api/borrowers', idempotentApplicantPayload())->assertCreated();

    $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'email' => 'someone.else@example.com',
        'contact_number' => '09171239877',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name']);

    expect(Borrower::where('last_name', 'Bautista')->count())->toBe(1);
});

it('honours a key right up to the edge of the submission window', function () {
    $key = (string) Str::uuid();
    $payload = idempotentApplicantPayload(['registration_uuid' => $key]);

    $first = $this->postJson('/api/borrowers', $payload)->assertCreated();

    DB::table('borrowers')
        ->where('id', $first->json('data.id'))
        ->update(['created_at' => now()->subMinutes(BorrowerSubmissionTokenService::TTL_MINUTES - 1)]);

    $this->postJson('/api/borrowers', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'));
});

it('ignores a key whose submission has aged out of the window', function () {
    // The window is the security boundary: the response mints a submission
    // token, and a token is write access to that borrower's photo and valid-ID
    // uploads. A leaked key must buy no more than a leaked token does.
    $key = (string) Str::uuid();
    $payload = idempotentApplicantPayload(['registration_uuid' => $key]);

    $first = $this->postJson('/api/borrowers', $payload)->assertCreated();
    $borrowerId = $first->json('data.id');

    DB::table('borrowers')
        ->where('id', $borrowerId)
        ->update(['created_at' => now()->subMinutes(BorrowerSubmissionTokenService::TTL_MINUTES + 5)]);

    BorrowerSubmissionToken::query()->delete();

    // Normal validation applies again, so the applicant's own stale row is now
    // just a duplicate.
    $this->postJson('/api/borrowers', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name']);

    expect(BorrowerSubmissionToken::where('borrower_id', $borrowerId)->count())->toBe(0);
    expect(Borrower::where('registration_uuid', $key)->count())->toBe(1);
});

it('never hands back an approved member for a key that points at one', function () {
    // The enumeration guard. If a key ever resolved to an approved member, the
    // 201 would carry a live submission token for that member — standing write
    // access to somebody else's KYC uploads.
    $key = (string) Str::uuid();

    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'registration_uuid' => $key,
    ]);

    // The unusable key is discarded and this is handled as what it is: a new
    // registration. No error names `registration_uuid`, because saying so would
    // confirm the key exists.
    $response = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $key,
    ]))->assertCreated();

    expect($response->json('data.id'))->not->toBe($member->id);
    expect(Borrower::find($response->json('data.id'))->registration_uuid)->toBeNull();

    // The member keeps the key, keeps their details, and gains no upload token.
    expect(BorrowerSubmissionToken::where('borrower_id', $member->id)->count())->toBe(0);
    expect($member->fresh()->status)->toBe('active');
    expect($member->fresh()->registration_uuid)->toBe($key);
    expect($member->fresh()->first_name)->not->toBe('Marites');
});

it('applies normal duplicate validation when a key points at an approved member', function () {
    // Same probe, but with the member's own details. The key is not honoured,
    // so NoDuplicateBorrower runs with nothing to ignore and refuses — and the
    // anonymous message still names nobody.
    $key = (string) Str::uuid();

    $member = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'registration_uuid' => $key,
        'first_name' => 'Marites',
        'middle_name' => 'Reyes',
        'last_name' => 'Bautista',
        'birthdate' => '1988-03-04',
    ]);

    $response = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $key,
        'email' => 'probe@example.com',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name']);

    expect($response->json('errors.first_name.0'))->not->toContain($member->borrower_code);
    expect(BorrowerSubmissionToken::where('borrower_id', $member->id)->count())->toBe(0);
});

it('does not let an authenticated operator claim a pending applicant with their key', function () {
    // existingRegistration() is anonymous-only. An operator create is never
    // short-circuited into someone else's row, whatever key it carries.
    $key = (string) Str::uuid();

    $applicant = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $key,
    ]))->assertCreated();

    $applicantId = $applicant->json('data.id');

    $response = $this->actingAs($this->admin)->postJson('/api/borrowers', [
        'status' => 'active',
        'branch_id' => $this->branch->id,
        'first_name' => 'Operator',
        'last_name' => 'Created',
        'contact_number' => '09170000111',
        'email' => 'operator.created@example.com',
        'registration_uuid' => $key,
    ])->assertCreated();

    expect($response->json('data.id'))->not->toBe($applicantId);
    expect(Borrower::find($applicantId)->status)->toBe('pending');
    expect(Borrower::find($applicantId)->registration_uuid)->toBe($key);
    expect(Borrower::find($response->json('data.id'))->registration_uuid)->toBeNull();
});

it('leaves a submission without a key working exactly as before', function () {
    $this->postJson('/api/borrowers', idempotentApplicantPayload())
        ->assertCreated()
        ->assertJsonStructure(['success', 'data' => ['id', 'submission_token', 'expires_at']]);

    expect(Borrower::whereNotNull('registration_uuid')->count())->toBe(0);
});

it('rejects a registration_uuid that is not a version 4 uuid', function (string $candidate) {
    // A bare `uuid` rule accepts every version plus the nil UUID. The nil UUID
    // is a constant every caller would collide on, and v1 encodes a MAC address
    // and a timestamp — neither is unguessable, which is the key's only defence.
    $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $candidate,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['registration_uuid']);
})->with([
    'not a uuid at all' => 'not-a-uuid',
    'the nil uuid' => '00000000-0000-0000-0000-000000000000',
    'a version 1 uuid' => '2c9d1a80-8c1a-11ee-b9d1-0242ac120002',
]);

it('accepts the version 4 uuid a browser would generate', function () {
    // crypto.randomUUID() emits v4; this is the shape the frontend will send.
    $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => '9f1c1a5e-6f2a-4a2e-9f1c-1a5e6f2a4a2e',
    ]))->assertCreated();
});

// ── Identity binding ─────────────────────────────────────────────────────────
//
// The key alone is a bearer credential, and honouring one mints a submission
// token — write access to that borrower's photo and valid-ID uploads. Binding
// it to the applicant's own identity is what makes a guessed or leaked key
// worthless on its own.

it('ignores a key whose stored submission describes a different person', function () {
    $key = (string) Str::uuid();

    $applicant = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'pending',
        'registration_uuid' => $key,
        'first_name' => 'Anastacia',
        'middle_name' => 'Duterte',
        'last_name' => 'Villanueva',
        'birthdate' => '1979-11-22',
    ]);

    // In-window and still pending — the ONLY thing refusing this key is that
    // the payload describes somebody else.
    $response = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $key,
    ]))->assertCreated();

    expect($response->json('data.id'))->not->toBe($applicant->id);
    expect(Borrower::find($response->json('data.id'))->registration_uuid)->toBeNull();

    expect(BorrowerSubmissionToken::where('borrower_id', $applicant->id)->count())->toBe(0);
    expect($applicant->fresh()->first_name)->toBe('Anastacia');
});

it('will not honour a key when any single identity field differs', function (array $override) {
    $key = (string) Str::uuid();

    $applicant = Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'pending',
        'registration_uuid' => $key,
        'first_name' => 'Marites',
        'middle_name' => 'Reyes',
        'last_name' => 'Bautista',
        'birthdate' => '1988-03-04',
    ]);

    $response = $this->postJson('/api/borrowers', idempotentApplicantPayload(
        array_merge(['registration_uuid' => $key], $override),
    ));

    // The outcome varies — a changed birthdate still trips NoDuplicateBorrower
    // on the identical name, a changed name does not. What must never vary is
    // that the stored applicant is neither returned nor handed a token.
    expect($response->json('data.id'))->not->toBe($applicant->id);
    expect(BorrowerSubmissionToken::where('borrower_id', $applicant->id)->count())->toBe(0);
})->with([
    'different first name' => [['first_name' => 'Corazon']],
    'different last name' => [['last_name' => 'Mercado']],
    'different birthdate' => [['birthdate' => '1990-07-19']],
]);

it('still honours a retry that re-types the name with different casing and spacing', function () {
    // A retry is a human re-typing a form. Binding the key to the identity must
    // not make the mechanism so brittle that it fails the submissions it exists
    // to rescue.
    $key = (string) Str::uuid();

    $first = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $key,
    ]))->assertCreated();

    $retry = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $key,
        'first_name' => '  MARITES  ',
        'last_name' => 'bautista',
    ]))->assertCreated();

    expect($retry->json('data.id'))->toBe($first->json('data.id'));
    expect(Borrower::where('registration_uuid', $key)->count())->toBe(1);
});

// ── Existence oracle ─────────────────────────────────────────────────────────

it('makes an unusable key indistinguishable from a fresh one', function () {
    // Three branches used to be tellable apart, and the third — 422 naming
    // `registration_uuid` — confirmed to an anonymous caller that a key exists.
    // Infeasible to exploit at 122 bits, but it stops being infeasible the day
    // a client generates keys weakly, so the branch should not exist at all.
    $taken = (string) Str::uuid();

    Borrower::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'registration_uuid' => $taken,
    ]);

    $probeWithTakenKey = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => $taken,
        'first_name' => 'Probe',
        'middle_name' => 'Alpha',
        'last_name' => 'Onething',
        'email' => 'probe-a@example.com',
        'contact_number' => '09170000001',
    ]));

    $probeWithFreshKey = $this->postJson('/api/borrowers', idempotentApplicantPayload([
        'registration_uuid' => (string) Str::uuid(),
        'first_name' => 'Sentinel',
        'middle_name' => 'Bravo',
        'last_name' => 'Twothing',
        'email' => 'probe-b@example.com',
        'contact_number' => '09170000002',
    ]));

    expect($probeWithTakenKey->status())->toBe($probeWithFreshKey->status())
        ->and($probeWithTakenKey->json('success'))->toBe($probeWithFreshKey->json('success'))
        ->and(array_keys($probeWithTakenKey->json('data')))->toBe(array_keys($probeWithFreshKey->json('data')));

    // Each got its own new row; only the fresh key was actually kept.
    expect(Borrower::find($probeWithTakenKey->json('data.id'))->registration_uuid)->toBeNull();
    expect(Borrower::find($probeWithFreshKey->json('data.id'))->registration_uuid)->not->toBeNull();
});
