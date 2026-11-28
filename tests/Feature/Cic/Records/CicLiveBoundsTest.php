<?php

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Records\CicRecordsReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * Security re-review SN1 and SN4: live views read only today or a recent
 * month end (422 in words otherwise), under one cache key per date, never
 * caching what the store can't hold; the hourly cic:clear-temp deletes the
 * database store's expired cic: rows and fails captures stuck for over an
 * hour, deleting their records.
 */

beforeEach(function () {
    $this->cicOn();
    $this->reader = $this->cicUser([CicAccess::VIEW, 'branches:view_all', 'borrowers:view']);
});

it('builds live only for today or a month end in the last 92 days, and reads any capture', function () {
    $this->cicLoan();
    $refusal = 'Choose today, or the last day of a month within the last 92 days. Older months are read from their captures.';

    foreach (['2026-11-30', '2026-10-31', '2026-08-31'] as $date) {
        $this->actingAs($this->reader)->getJson("/api/cic/readiness?reference_date={$date}")->assertOk();
    }

    foreach (['/api/cic/readiness', '/api/cic/records', '/api/cic/issues', '/api/cic/issues/export', '/api/cic/records/ID/BRW-1'] as $url) {
        $this->actingAs($this->reader)->getJson("{$url}?reference_date=2026-11-15")
            ->assertUnprocessable()->assertJsonPath('errors.reference_date.0', $refusal);
    }

    $this->actingAs($this->reader)->getJson('/api/cic/readiness?reference_date=2026-07-31')
        ->assertUnprocessable()->assertJsonPath('errors.reference_date.0', $refusal);

    $old = CicCapture::factory()->monthEnd('2026-05-31')->completeWithRecords(1, 1)->create();
    $this->actingAs($this->reader)->getJson("/api/cic/issues?capture_id={$old->id}&reference_date=2026-05-31")->assertOk();
});

it('keeps one cache key per date, and caches nothing the store can\'t hold', function () {
    $this->cicLoan();
    $key = CicRecordsReader::liveCacheKey(Carbon::parse('2026-11-30'));

    config(['cic.cache_max_bytes' => 1000]);
    $this->actingAs($this->reader)->getJson('/api/cic/readiness')->assertOk();
    expect(Cache::has($key))->toBeFalse();

    config(['cic.cache_max_bytes' => 8 * 1024 * 1024]);
    $this->actingAs($this->reader)->getJson('/api/cic/readiness')->assertOk();
    expect(Cache::has($key))->toBeTrue()->and($key)->toBe('cic:live:2026-11-30');
});

it('deletes the database cache store\'s expired cic rows every hour', function () {
    config(['cache.default' => 'database']);
    Cache::put('cic:live:2026-10-31', 'x', 60);
    Cache::put('cic:validation:1:abc:def', 'x', 600);
    Cache::put('other:key', 'x', 60);
    $this->travel(2)->minutes();

    $this->artisan('cic:clear-temp')->expectsOutput('Removed 1 expired CIC cache entry.')->assertSuccessful();

    expect(DB::table('cache')->pluck('key')->map(fn (string $key) => str($key)->after(config('cache.prefix'))->value())->sort()->values()->all())
        ->toBe(['cic:validation:1:abc:def', 'other:key']);
});

it('fails a capture stuck for over an hour and deletes its records, leaving a running one alone', function () {
    $stuck = CicCapture::factory()->create(['reference_date' => '2026-11-30']);
    $stuck->forceFill(['started_at' => now()->subMinutes(61)])->save();
    CicCaptureRecord::factory()->for($stuck, 'capture')->create();
    $running = CicCapture::factory()->create(['reference_date' => '2026-11-29']);
    $running->forceFill(['started_at' => now()->subMinutes(10)])->save();

    $this->artisan('cic:clear-temp')->expectsOutput('Marked 1 stuck CIC capture failed.')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe('failed')
        ->and(CicCaptureRecord::query()->where('capture_id', $stuck->id)->count())->toBe(0)
        ->and($running->fresh()->status)->toBe('capturing')
        ->and(app(CicCaptureService::class)->failStuck())->toBe(0);
});

it('refuses a capture on readiness, which is always live, and never caches a refused date', function () {
    $this->cicLoan();
    $capture = CicCapture::factory()->monthEnd('2026-05-31')->completeWithRecords(1, 1)->create();

    $this->actingAs($this->reader)->getJson("/api/cic/readiness?capture_id={$capture->id}&reference_date=2026-05-31")
        ->assertUnprocessable()
        ->assertJsonPath('errors.capture_id.0', 'Readiness is always worked out live for today or a recent month end, so it takes no capture. Open a capture\'s records or issues to read it.');
    expect(Cache::has(CicRecordsReader::liveCacheKey(Carbon::parse('2026-05-31'))))->toBeFalse();

    app(CicRecordsReader::class)->live(Carbon::parse('2026-05-31'));
    app(CicRecordsReader::class)->live(Carbon::parse('2026-11-15'));
    app(CicRecordsReader::class)->live(Carbon::parse('2026-10-31'));

    expect(Cache::has(CicRecordsReader::liveCacheKey(Carbon::parse('2026-05-31'))))->toBeFalse()
        ->and(Cache::has(CicRecordsReader::liveCacheKey(Carbon::parse('2026-11-15'))))->toBeFalse()
        ->and(Cache::has(CicRecordsReader::liveCacheKey(Carbon::parse('2026-10-31'))))->toBeTrue();
});
