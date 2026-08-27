<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    Storage::fake('private');

    // Rate-limiter counters are cache entries and the app's cache store
    // outlives nothing but this example. Flushing keeps one spec's spent quota
    // from failing the next one and reading as flake.
    Cache::flush();

    // Deliberately NOT calling actingAs(): an authenticated caller is exempt
    // from every limiter under test here.
});

/**
 * A borrower payload the public form would actually produce.
 *
 * Callers that create several applicants in one spec must pass genuinely
 * different names. BorrowerDuplicateDetector matches fuzzily, so serialised
 * names like Flood1/Flood2 collide and come back 422 — which has nothing to do
 * with rate limiting and reads as a baffling failure.
 *
 * @return array<string, mixed>
 */
function publicApplicant(string $firstName, int $serial, string $lastName = 'Applicant'): array
{
    return [
        'status' => 'pending',
        'branch_id' => Branch::first()->id,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'birthdate' => '1990-05-12',
        'contact_number' => '0917'.str_pad((string) $serial, 7, '0', STR_PAD_LEFT),
        'email' => Str::lower($firstName).$serial.'@example.com',
        'address' => '123 Mango St',
    ];
}

/**
 * Re-evaluates config/trustedproxy.php against a given TRUSTED_PROXIES.
 *
 * The derivation is what is being asserted, so this has to run the real
 * expression from the config file. Overriding the resolved value with config()
 * would skip the explode entirely and pass against the very bug it guards.
 *
 * @return array<int, string>
 */
function trustedProxiesFor(?string $env): array
{
    $previous = $_ENV['TRUSTED_PROXIES'] ?? null;

    if ($env === null) {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
    } else {
        $_ENV['TRUSTED_PROXIES'] = $env;
        $_SERVER['TRUSTED_PROXIES'] = $env;
        putenv("TRUSTED_PROXIES={$env}");
    }

    try {
        return (require __DIR__.'/../../config/trustedproxy.php')['proxies'];
    } finally {
        if ($previous === null) {
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');
        } else {
            $_ENV['TRUSTED_PROXIES'] = $previous;
            $_SERVER['TRUSTED_PROXIES'] = $previous;
            putenv("TRUSTED_PROXIES={$previous}");
        }
    }
}

/**
 * The reported production bug, in full.
 *
 * Every browser JSON call reaches this API through the frontend's server-side
 * proxy, so two people registering from opposite ends of the province arrive
 * from the same address. One registration is a create, a photo and however
 * many valid IDs the coop asks for; against a single 5-per-10-minute bucket
 * that meant the second applicant of any ten-minute window was refused
 * partway through their own form. This fails at request six without the split.
 */
it('lets two applicants register back to back from the same address', function () {
    $serial = 0;

    foreach (['Ana', 'Ben'] as $firstName) {
        $created = $this->postJson('/api/borrowers', publicApplicant($firstName, ++$serial))
            ->assertCreated();

        $borrowerId = $created->json('data.id');
        $token = $created->json('data.submission_token');

        $this->postJson(
            "/api/borrowers/{$borrowerId}/photo",
            ['photo' => UploadedFile::fake()->image('me.jpg')],
            ['X-Submission-Token' => $token],
        )->assertOk();

        foreach (['umid', 'sss', 'drivers_license'] as $type) {
            $this->postJson(
                "/api/borrowers/{$borrowerId}/valid-ids",
                [
                    'type' => $type,
                    'front_file' => UploadedFile::fake()->image("{$type}-front.jpg"),
                    'back_file' => UploadedFile::fake()->image("{$type}-back.jpg"),
                ],
                ['X-Submission-Token' => $token],
            )->assertCreated();
        }
    }

    expect(Borrower::where('status', 'pending')->count())->toBe(2);
});

/**
 * With the frontend trusted, the limiter must meter the applicant's browser
 * rather than the proxy hop — otherwise trusting the proxy has bought nothing.
 */
it('keys the registration limiter on the forwarded address behind a trusted proxy', function () {
    config()->set('trustedproxy.proxies', ['10.0.0.9']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9']);

    // Spend the burst tier from one browser. An empty body is a 422, and a 422
    // still costs a slot — the throttle runs ahead of validation.
    for ($i = 0; $i < 8; $i++) {
        $this->postJson('/api/borrowers', [], ['X-Forwarded-For' => '203.0.113.10'])
            ->assertUnprocessable();
    }

    $this->postJson('/api/borrowers', [], ['X-Forwarded-For' => '203.0.113.10'])
        ->assertTooManyRequests();

    // The next applicant through the same proxy is a different person and
    // gets their own budget.
    $this->postJson('/api/borrowers', [], ['X-Forwarded-For' => '198.51.100.20'])
        ->assertUnprocessable();
});

/**
 * The other half of that bargain: X-Forwarded-For is only worth reading when
 * the hop that set it is one we put in TRUSTED_PROXIES ourselves. This is the
 * spec that fails if someone widens the list to '*' — the framework expands
 * that to 0.0.0.0/0 and every caller gets to name their own bucket.
 */
it('ignores a forwarded address that did not arrive from a trusted proxy', function () {
    config()->set('trustedproxy.proxies', ['10.0.0.9']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);

    for ($i = 0; $i < 8; $i++) {
        $this->postJson('/api/borrowers', [], ['X-Forwarded-For' => '198.51.100.'.$i])
            ->assertUnprocessable();
    }

    $this->postJson('/api/borrowers', [], ['X-Forwarded-For' => '198.51.100.200'])
        ->assertTooManyRequests();
});

/**
 * Uploads are metered per submission token, so one applicant burning through
 * their own budget — a retried photo, a re-shot ID — cannot touch anyone
 * else's, even though both are on the same office wifi.
 */
it('gives each submission token its own upload budget', function () {
    $first = $this->postJson('/api/borrowers', publicApplicant('Carmen', 1))->assertCreated();
    $second = $this->postJson('/api/borrowers', publicApplicant('Dante', 2))->assertCreated();

    $firstId = $first->json('data.id');
    $firstToken = $first->json('data.submission_token');
    $secondId = $second->json('data.id');
    $secondToken = $second->json('data.submission_token');

    for ($i = 0; $i < 24; $i++) {
        $this->postJson(
            "/api/borrowers/{$firstId}/photo",
            ['photo' => UploadedFile::fake()->image('retry.jpg')],
            ['X-Submission-Token' => $firstToken],
        )->assertOk();
    }

    $this->postJson(
        "/api/borrowers/{$firstId}/photo",
        ['photo' => UploadedFile::fake()->image('one-too-many.jpg')],
        ['X-Submission-Token' => $firstToken],
    )->assertTooManyRequests();

    $this->postJson(
        "/api/borrowers/{$secondId}/photo",
        ['photo' => UploadedFile::fake()->image('me.jpg')],
        ['X-Submission-Token' => $secondToken],
    )->assertOk();
});

/**
 * A coop member filling in a registration form is not a developer reading a
 * stack trace. The frontend also filters the framework's stock "Too Many
 * Attempts." as boilerplate, so shipping that phrase shows the applicant an
 * empty error and they simply retry into the same wall.
 */
it('answers a throttled registration with copy written for a person', function () {
    for ($i = 0; $i < 8; $i++) {
        $this->postJson('/api/borrowers', [])->assertUnprocessable();
    }

    $response = $this->postJson('/api/borrowers', [])->assertTooManyRequests();

    $message = (string) $response->json('message');

    expect(Str::lower($message))->not->toContain('too many attempts')
        ->and($message)->toContain('few minutes')
        ->and($response->json('retry_after'))->toBeInt()
        ->and($response->json('retry_after'))->toBeGreaterThan(0)
        ->and($response->headers->get('Retry-After'))->not->toBeNull();
});

/**
 * Operators onboard members in batches from one office address. They are
 * already authorised, so they must not queue behind the public form's budget.
 */
it('does not throttle an authenticated operator creating borrowers in bulk', function () {
    $this->actingAs(User::where('username', 'super_admin')->first());

    $branchId = Branch::first()->id;

    // Twelve is past the anonymous burst tier of eight.
    for ($i = 1; $i <= 12; $i++) {
        $this->postJson('/api/borrowers', [
            'branch_id' => $branchId,
            'first_name' => 'Bulk'.$i,
            'last_name' => 'Operator',
            'contact_number' => '0917'.str_pad((string) (100 + $i), 7, '0', STR_PAD_LEFT),
            'email' => "bulk{$i}@example.com",
        ])->assertCreated();
    }
});

/**
 * A borrower list renders one <img> per row and each of those is a separate
 * signed request that carries no Authorization header, so they all key by
 * address. Production logged 613 from one admin; against the shared 60/min
 * `api` bucket the page half-rendered and the rest came back 429. Fails at
 * request 61 without the carve-out.
 */
it('serves a hundred signed photo links without tripping the api limiter', function () {
    $branchId = Branch::first()->id;

    $borrowers = collect(range(1, 5))->map(function (int $i) use ($branchId): Borrower {
        $path = "borrowers/photos/{$i}/photo.jpg";
        Storage::disk('private')->put($path, 'photo-bytes');

        return Borrower::factory()->create([
            'branch_id' => $branchId,
            'photo_path' => $path,
        ]);
    });

    for ($page = 0; $page < 20; $page++) {
        foreach ($borrowers as $borrower) {
            $this->get($borrower->photo_url)->assertOk();
        }
    }
});

/**
 * The carve-out gives signed file reads their own bucket; it must not have
 * quietly lifted the ceiling on everything else. Limit::none() on files.*
 * would leave this spec green while removing the cap the API actually needs.
 */
it('still throttles ordinary api traffic at sixty a minute', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/branches/public')->assertOk();
    }

    $this->getJson('/api/branches/public')->assertTooManyRequests();
});

/**
 * An unset TRUSTED_PROXIES has to mean "trust nobody", which is the behaviour
 * every box had before this key existed. A bare explode would hand
 * TrustProxies a one-entry list containing '' instead.
 */
it('resolves an unset TRUSTED_PROXIES to an empty list, not [\'\']', function () {
    expect(trustedProxiesFor(null))->toBe([])
        ->and(trustedProxiesFor(''))->toBe([])
        ->and(trustedProxiesFor('192.0.2.10,,'))->toBe(['192.0.2.10'])
        ->and(trustedProxiesFor(' 192.0.2.10 , 192.0.2.11 '))->toBe(['192.0.2.10', '192.0.2.11']);
});

/**
 * Trusting the proxy is scoped to X-Forwarded-For and nothing else.
 *
 * Next stamps x-forwarded-host on every request it proxies. The framework's
 * default header set trusts it, and that would move $request->root() onto the
 * frontend host — which has no /api/files/* route to serve, and which is not
 * the host the signature in Borrower::photoUrl() was computed over. Every
 * borrower photo in the admin UI would 403. The signed link below still opens
 * with a forged x-forwarded-host present, which is the whole assertion.
 */
it('does not let a forwarded host move the app host and break signed links', function () {
    config()->set('trustedproxy.proxies', ['10.0.0.9']);

    Storage::disk('private')->put('borrowers/photos/1/photo.jpg', 'photo-bytes');

    $borrower = Borrower::factory()->create([
        'branch_id' => Branch::first()->id,
        'photo_path' => 'borrowers/photos/1/photo.jpg',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get($borrower->photo_url, [
            'X-Forwarded-For' => '203.0.113.10',
            'X-Forwarded-Host' => 'binhs-coop.lendyph.com',
        ])
        ->assertOk();
});

/**
 * Tokens are free — every POST /borrowers mints another one — so a per-token
 * upload budget is not a budget. Without an address tier a single anonymous
 * caller can walk 30 tokens an hour past 24 uploads each and push double-digit
 * GB of files onto the private KYC volume without ever meeting a limiter.
 * Before the split that was capped only by the shared-bucket collapse, so
 * fixing the collapse is what makes this tier load-bearing.
 */
it('backstops anonymous uploads per address across separate submission tokens', function () {
    $names = [
        ['Rosa', 'Mendoza'],
        ['Teodoro', 'Villanueva'],
        ['Imelda', 'Bautista'],
        ['Nestor', 'Panganiban'],
        ['Corazon', 'Dimaculangan'],
        ['Ferdinand', 'Ocampo'],
    ];

    $applicants = [];

    foreach ($names as $serial => [$firstName, $lastName]) {
        $created = $this->postJson('/api/borrowers', publicApplicant($firstName, $serial + 1, $lastName))
            ->assertCreated();

        $applicants[] = [$created->json('data.id'), $created->json('data.submission_token')];
    }

    // Six creates already spent. The global `api` limiter is 60/min and is not
    // what this spec is about, so step past its window rather than raising it;
    // the address tier here decays in an hour and survives the jump.
    $sent = 6;

    foreach (array_slice($applicants, 0, 5) as [$borrowerId, $token]) {
        for ($i = 0; $i < 24; $i++) {
            if (++$sent % 50 === 0) {
                $this->travel(61)->seconds();
            }

            $this->postJson(
                "/api/borrowers/{$borrowerId}/photo",
                ['photo' => UploadedFile::fake()->image('retry.jpg')],
                ['X-Submission-Token' => $token],
            )->assertOk();
        }
    }

    // A sixth token with its full 24 untouched. Only the address tier can
    // refuse this, which is the whole assertion.
    [$freshId, $freshToken] = $applicants[5];

    $this->postJson(
        "/api/borrowers/{$freshId}/photo",
        ['photo' => UploadedFile::fake()->image('one-too-many.jpg')],
        ['X-Submission-Token' => $freshToken],
    )->assertTooManyRequests();
});

/**
 * The `auth` limiter reads `login` raw, upstream of LoginRequest, so the value
 * can be any JSON type. Casting a non-string with (string) raises an
 * E_WARNING that HandleExceptions rethrows from inside the limiter closure —
 * before hit() runs, so the attempt is never even counted, and every one of
 * them appends a stack trace to an unrotated debug log. That is a disk-fill
 * vector at 60 requests a minute, dressed up as a 500.
 */
it('rejects a non-string login field without a server error', function () {
    $shapes = [
        'empty array' => [],
        'list' => ['not', 'a', 'string'],
        'object' => ['nested' => 'value'],
    ];

    foreach ($shapes as $login) {
        $this->postJson('/api/auth/login', ['login' => $login, 'password' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login']);
    }
});
