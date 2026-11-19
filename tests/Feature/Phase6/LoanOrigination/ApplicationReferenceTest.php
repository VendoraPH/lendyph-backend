<?php

use App\Models\OriginationApplication;
use App\Models\OriginationSetting;
use App\Services\GeneratedNumberWrite;
use App\Services\LoanOrigination\ApplicationReference;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * LO-B1: reference numbers `OA-YYYY-NNNNNN` (origination decision 18), by
 * submission year in Manila, through GeneratedNumberWrite so two submits at
 * the same moment retry instead of failing. The prefix is a setting, never
 * a lending prefix such as `LA`.
 */
uses(TestCase::class);

afterEach(fn () => Carbon::setTestNow());

function loSubmitted(string $at): OriginationApplication
{
    return OriginationApplication::query()->create(['status' => 'submitted', 'submitted_at' => Carbon::parse($at, 'Asia/Manila')]);
}

it('numbers applications per submission year, six digits, starting at 1', function () {
    $reference = app(ApplicationReference::class);

    expect($reference->assign(loSubmitted('2026-11-19 10:00')))->toBe('OA-2026-000001')
        ->and($reference->assign(loSubmitted('2026-12-31 23:59')))->toBe('OA-2026-000002')
        ->and($reference->assign(loSubmitted('2027-01-01 00:01')))->toBe('OA-2027-000001');

    expect(OriginationApplication::query()->whereNotNull('reference_number')->pluck('reference_number')->all())
        ->toEqualCanonicalizing(['OA-2026-000001', 'OA-2026-000002', 'OA-2027-000001']);
});

it('keeps an application\'s reference once it has one', function () {
    $reference = app(ApplicationReference::class);
    $application = loSubmitted('2026-11-19 10:00');

    $first = $reference->assign($application);

    expect($reference->assign($application->fresh()))->toBe($first)
        ->and(OriginationApplication::query()->whereNotNull('reference_number')->count())->toBe(1);
});

it('takes the prefix from the settings and continues each prefix on its own', function () {
    $reference = app(ApplicationReference::class);
    $reference->assign(loSubmitted('2026-11-19 10:00'));

    DB::table('origination_settings')->update(['reference_prefix' => 'APP']);

    expect($reference->assign(loSubmitted('2026-11-19 11:00')))->toBe('APP-2026-000001')
        ->and(OriginationSetting::current()->referenceFormat())->toBe('APP-YYYY-NNNNNN');
});

it('treats two applications given the same number as a collision to retry', function () {
    $duplicate = (new UniqueConstraintViolationException('mysql', 'insert', [], new Exception('duplicate')))
        ->setIndex(GeneratedNumberWrite::ORIGINATION_REFERENCE_INDEX);

    expect(GeneratedNumberWrite::isCollision($duplicate))->toBeTrue()
        ->and(GeneratedNumberWrite::ORIGINATION_REFERENCE_INDEX)->toBe('origination_applications_reference_number_unique');

    expect(ApplicationReference::RESERVED_PREFIXES)->toContain('LA', 'LN', 'RCP', 'BRW', 'ADJ', 'REQ', 'MC', 'JE');
});

it('assigns through GeneratedNumberWrite when called outside a transaction', function () {
    // A rolled-back test transaction is open, so run() just runs the write:
    // the point is that assign() goes through it and returns the number.
    $application = loSubmitted('2026-11-19 10:00');

    $number = GeneratedNumberWrite::run(
        fn () => app(ApplicationReference::class)->assign($application),
        ApplicationReference::CONFLICT,
    );

    expect($number)->toBe('OA-2026-000001')
        ->and(ApplicationReference::CONFLICT)->toBe('Another application was submitted at the same moment. Submit again.');
});
