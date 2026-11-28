<?php

use App\Models\CicCapture;
use App\Models\CicProductMapping;
use App\Models\CicSetting;
use App\Models\CicSubmission;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicCache;
use App\Services\Cic\CicValidator;
use App\Services\Cic\Records\CicRecordsReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * Security review S-M2: the `cic` limiter (30 a minute per
 * user) on the screens that build a whole book, a 60-second encrypted cache
 * of the live build per reference date, cached capture validation, and a
 * single-record build for one record's live view.
 */

beforeEach(function () {
    $this->cicOn();
    $this->reader = $this->cicUser([CicAccess::VIEW, 'branches:view_all', 'borrowers:view']);
});

it('allows 30 CIC screen reads a minute per user, across the screens and submissions, then answers 429 in words', function () {
    config(['cic.test_captures' => true]);
    $this->cicLoan();
    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    $submission = CicSubmission::factory()->create(['capture_id' => $capture->id, 'reference_date' => '2026-11-30']);
    $other = $this->cicUser([CicAccess::VIEW, 'branches:view_all']);

    foreach (['/api/cic/readiness', '/api/cic/records', '/api/cic/issues', '/api/cic/submissions', "/api/cic/submissions/{$submission->id}"] as $url) {
        foreach (range(1, 6) as $ignored) {
            $this->actingAs($this->reader)->getJson($url)->assertOk();
        }
    }

    $this->actingAs($this->reader)->getJson('/api/cic/submissions')
        ->assertTooManyRequests()->assertJsonPath('message', 'Too many CIC requests. Wait a minute and try again.');
    $this->actingAs($other)->getJson('/api/cic/readiness')->assertOk();

    $this->travel(61)->seconds();
    $this->actingAs($this->reader)->getJson('/api/cic/readiness')->assertOk();
});

it('caches the live build for 60 seconds, encrypted, and reads afresh when a mapping changes', function () {
    $member = $this->cicMember(['first_name' => 'Rosalinda']);
    $loan = $this->cicLoan($member);
    $key = CicRecordsReader::liveCacheKey(Carbon::parse('2026-11-30'));
    $names = fn () => collect($this->actingAs($this->reader)->getJson('/api/cic/records?record_type=ID&reference_date=2026-11-30')->assertOk()->json('data'))->pluck('member.name')->all();

    expect($names())->toBe(['Rosalinda Santos Cruz'])
        ->and(Cache::get($key))->toBeString()->not->toContain('Rosalinda');

    // A new loan within the minute isn't seen yet ...
    $this->cicLoan($this->cicMember(['first_name' => 'Teodora']), $loan->loanProduct);
    expect($names())->toBe(['Rosalinda Santos Cruz']);

    // ... but a mapping change is, and so is everything once the minute is up.
    CicProductMapping::query()->where('loan_product_id', $loan->loan_product_id)->sole()->forceFill(['contract_type' => '15'])->save();
    expect(CicRecordsReader::liveCacheKey(Carbon::parse('2026-11-30')))->not->toBe($key)
        ->and($names())->toHaveCount(2);
});

it('builds one record\'s live view from its own loans, with the same values and issues as the full build', function () {
    $member = $this->cicMember(['birthdate' => null]);
    $loan = $this->cicLoan($member);
    $this->cicLoan();

    $one = $this->actingAs($this->reader)->getJson("/api/cic/records/ID/{$member->borrower_code}?reference_date=2026-11-30")->assertOk()->json('data');
    $contract = $this->actingAs($this->reader)->getJson("/api/cic/records/CI/{$loan->loan_account_number}?reference_date=2026-11-30")->assertOk()->json('data');

    expect(Cache::has(CicRecordsReader::liveCacheKey(Carbon::parse('2026-11-30'))))->toBeFalse();

    $this->actingAs($this->reader)->getJson('/api/cic/readiness?reference_date=2026-11-30')->assertOk();
    expect(Cache::has(CicRecordsReader::liveCacheKey(Carbon::parse('2026-11-30'))))->toBeTrue();

    $this->actingAs($this->reader)->getJson("/api/cic/records/ID/{$member->borrower_code}?reference_date=2026-11-30")->assertOk()
        ->assertJsonPath('data.fields', $one['fields'])->assertJsonPath('data.issues', $one['issues']);
    $this->actingAs($this->reader)->getJson("/api/cic/records/CI/{$loan->loan_account_number}?reference_date=2026-11-30")->assertOk()
        ->assertJsonPath('data.fields', $contract['fields'])->assertJsonPath('data.issues', $contract['issues']);

    expect(collect($one['issues'])->pluck('code')->all())->toContain('MISSING_MANDATORY')
        ->and($contract['errors'])->toBe(0);

    $this->actingAs($this->reader)->getJson('/api/cic/records/CI/LN-NONE?reference_date=2026-11-30')->assertNotFound();
});

it('caches a complete capture\'s validation for the screens by its id, checksum, settings and rules version', function () {
    config(['cic.test_captures' => true]);
    $this->cicLoan();
    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    $validator = app(CicValidator::class);

    $first = $validator->cachedIssues($capture);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $again = $validator->cachedIssues($capture);

    expect($again)->toBe($first)->and($queries)->toBeLessThanOrEqual(2)
        ->and(Cache::has(sprintf('cic:validation:%d:%s:%s', $capture->id, $capture->checksum, CicValidator::cacheFingerprint())))->toBeTrue();

    CicSetting::current()->forceFill(['provider_code' => 'BAD'])->save();
    expect(collect($validator->cachedIssues($capture))->pluck('code')->all())->toContain('PROVIDER_CODE');
});

it('never validates generate or encrypt from the cache', function () {
    config(['cic.test_captures' => true]);
    $this->cicLoan($this->cicMember(['birthdate' => null]));
    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);

    // A cached copy that wrongly says the capture is clean.
    Cache::put(sprintf('cic:validation:%d:%s:%s', $capture->id, $capture->checksum, CicValidator::cacheFingerprint()), [], 600);

    expect(app(CicValidator::class)->cachedIssues($capture))->toBe([])
        ->and(collect(app(CicValidator::class)->issues($capture))->where('severity', 'error')->isNotEmpty())->toBeTrue();

    $preparer = $this->cicUser([CicAccess::VIEW, CicAccess::PREPARE, 'branches:view_all']);
    $this->actingAs($preparer)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0, 'confirm_warnings' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_errors');
});

it('works out a value uncached when the cache fails, logging no values', function () {
    Log::spy();
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    expect(CicCache::remember('cic:test', 60, fn () => ['answer' => 42]))->toBe(['answer' => 42]);
    Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message, array $context) => $context === ['exception' => RuntimeException::class, 'sqlstate' => null]);
});

it('never caches a value bigger than the limit', function () {
    config(['cic.cache_max_bytes' => 100]);

    expect(CicCache::put('cic:big', str_repeat('x', 101), 60))->toBeFalse()
        ->and(Cache::has('cic:big'))->toBeFalse()
        ->and(CicCache::put('cic:small', str_repeat('x', 100), 60))->toBeTrue();
});
