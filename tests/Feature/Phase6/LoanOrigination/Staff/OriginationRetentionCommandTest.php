<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\OriginationApplication;
use App\Models\OriginationStatusHistory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: `loan-origination:retention`, the expiry and prune command
 * (origination decisions 10 and 18). A draft unsaved for 24 hours becomes
 * Incomplete; an unsubmitted application idle for the settings' draft
 * expiration days (30) expires; an expired one is erased 30 days later;
 * rejected and withdrawn ones after the settings' retention days (365).
 * Converted applications stay with their loan, and a legal hold blocks
 * erasure (skipped and listed). Each erasure writes a system audit row with
 * ids only. --dry-run writes nothing. It runs hourly, so a draft reads
 * Incomplete within the hour after its 24 hours.
 */
uses(TestCase::class);

beforeEach(function () {
    Storage::fake('private');
    Carbon::setTestNow(Carbon::parse('2026-11-19 00:40:00', 'Asia/Manila'));
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
});

afterEach(fn () => Carbon::setTestNow());

it('marks a draft unsaved for 24 hours incomplete, and leaves a fresher one', function () {
    $stale = lo3Application('draft', ['last_saved_at' => now()->subHours(25), 'submitted_at' => null]);
    $fresh = lo3Application('draft', ['last_saved_at' => now()->subHours(23), 'submitted_at' => null]);

    $this->artisan('loan-origination:retention')->assertSuccessful();

    expect($stale->fresh()->status)->toBe('incomplete')
        ->and($fresh->fresh()->status)->toBe('draft')
        ->and(OriginationStatusHistory::query()->where('application_id', $stale->id)->value('actor_type'))->toBe('system');
});

it('expires an unsubmitted application idle for the draft expiration days', function () {
    $idle = lo3Application('incomplete', ['last_saved_at' => now()->subDays(31), 'submitted_at' => null]);
    $recent = lo3Application('incomplete', ['last_saved_at' => now()->subDays(29), 'submitted_at' => null]);

    $this->artisan('loan-origination:retention')->assertSuccessful();

    expect($idle->fresh()->status)->toBe('expired')
        ->and($idle->fresh()->decided_at)->not->toBeNull()
        ->and($recent->fresh()->status)->toBe('incomplete');
});

it('erases expired applications after 30 days and rejected or withdrawn ones after the retention days, with their files', function () {
    $expired = lo3Application('expired', ['decided_at' => now()->subDays(31)]);
    lo3Document($expired);
    $recentExpired = lo3Application('expired', ['decided_at' => now()->subDays(10)]);
    $rejected = lo3Application('rejected', ['decided_at' => now()->subDays(366)]);
    $withdrawn = lo3Application('withdrawn', ['decided_at' => now()->subDays(364)]);
    $converted = lo3Application('converted', ['decided_at' => now()->subDays(900), 'loan_id' => Loan::factory()->create()->id]);
    $before = AuditLog::query()->max('id') ?? 0;

    $this->artisan('loan-origination:retention')->assertSuccessful();

    expect(OriginationApplication::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(OriginationApplication::query()->whereKey($rejected->id)->exists())->toBeFalse()
        ->and(OriginationApplication::query()->whereKey($recentExpired->id)->exists())->toBeTrue()
        ->and(OriginationApplication::query()->whereKey($withdrawn->id)->exists())->toBeTrue()
        ->and(OriginationApplication::query()->whereKey($converted->id)->exists())->toBeTrue()
        ->and(Storage::disk('private')->allFiles(OriginationApplication::filesFolder($expired->id)))->toBe([]);

    $rows = AuditLog::query()->where('id', '>', $before)->where('action', 'origination_erased')->get();
    expect($rows->pluck('auditable_id')->all())->toEqualCanonicalizing([$expired->id, $rejected->id])
        ->and($rows->pluck('user_id')->filter()->all())->toBe([])
        ->and(array_keys($rows->first()->old_values))->toEqualCanonicalizing(['status', 'branch_id', 'submitted', 'decided_on']);
});

it('skips and lists an application under a legal hold', function () {
    $held = lo3Application('rejected', ['decided_at' => now()->subDays(400)]);
    DB::table('origination_applications')->where('id', $held->id)->update(['legal_hold' => true]);

    $this->artisan('loan-origination:retention')
        ->expectsOutputToContain("Kept under a legal hold: #{$held->id}")
        ->assertSuccessful();

    expect(OriginationApplication::query()->whereKey($held->id)->exists())->toBeTrue();
});

it('writes nothing on --dry-run and says what it would do', function () {
    $stale = lo3Application('draft', ['last_saved_at' => now()->subHours(30), 'submitted_at' => null]);
    $old = lo3Application('rejected', ['decided_at' => now()->subDays(400)]);
    $before = AuditLog::query()->max('id') ?? 0;

    $this->artisan('loan-origination:retention --dry-run')
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('Would mark incomplete: 1')
        ->expectsOutputToContain('Would erase: 1')
        ->assertSuccessful();

    expect($stale->fresh()->status)->toBe('draft')
        ->and(OriginationApplication::query()->whereKey($old->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('id', '>', $before)->count())->toBe(0);
});

it('is scheduled every hour at :25, on Manila time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'loan-origination:retention'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('25 * * * *')
        ->and($event->timezone)->toBe('Asia/Manila');
});
