<?php

use App\Models\CicCapture;
use App\Models\CicSetting;
use App\Services\Cic\CicLayouts;
use App\Services\Cic\File\CicFileBuilder;
use App\Services\Cic\Submissions\CicSubmissionRefusal;
use Illuminate\Support\Carbon;
use Tests\Feature\Cic\Submissions\CicSubmissionsTestHelpers;
use Tests\TestCase;

uses(TestCase::class, CicSubmissionsTestHelpers::class);

/*
 * The CSDF TXT (prompt §11): HD, ID, CI, CN, NE, FT in that order, each record
 * in its layout's field order with every position kept, fields joined by `|`,
 * CRLF line ends, UTF-8 with no BOM, the FT count of data records, and the
 * filename from the generation time in Manila. Deterministic.
 */

beforeEach(function () {
    $this->setUpCic();
});

function cicGoldenCapture(object $test): CicCapture
{
    return $test->captureWith([
        ['NE', 'BRW-00000001', ['NE1' => 'NE', 'NE4' => '31102026', 'NE5' => 'BRW-00000001']],
        ['CN', 'LN-000002', ['CN1' => 'CN', 'CN4' => '31102026', 'CN5' => 'BRW-00000002']],
        ['ID', 'BRW-00000002', ['ID1' => 'XX', 'ID2' => 'WRONG', 'ID4' => '31102026', 'ID5' => 'BRW-00000002', 'ID123' => 'Ñ']],
        ['CI', 'LN-000001', ['CI1' => 'CI', 'CI4' => '31102026', 'CI5' => 'BRW-00000001']],
        ['ID', 'BRW-00000001', ['ID1' => 'ID', 'ID4' => '31102026', 'ID5' => 'BRW-00000001']],
    ]);
}

it('builds the exact TXT bytes, CRLF and the FT count included', function () {
    $capture = cicGoldenCapture($this);
    $file = app(CicFileBuilder::class)->build($capture, 0, 'Month-end file', Carbon::parse('2026-11-25 06:30:10', 'UTC'));

    $expected = implode("\r\n", [
        'HD|TEST1234|31102026|1.0|0|Month-end file',
        'ID|TEST1234||31102026|BRW-00000001'.str_repeat('|', 118),
        'ID|TEST1234||31102026|BRW-00000002'.str_repeat('|', 118).'Ñ',
        'CI|TEST1234||31102026|BRW-00000001'.str_repeat('|', 138),
        'CN|TEST1234||31102026|BRW-00000002'.str_repeat('|', 122),
        'NE|TEST1234||31102026|BRW-00000001'.str_repeat('|', 5),
        'FT|TEST1234|31102026|5',
    ])."\r\n";

    expect($file->contents)->toBe($expected)
        ->and($file->filename)->toBe('TEST1234_CSDF_20261125143010.txt')
        ->and($file->recordCount)->toBe(5)
        ->and($file->countsByType)->toBe(['ID' => 2, 'CI' => 1, 'CN' => 1, 'NE' => 1])
        ->and($file->sha256())->toBe(hash('sha256', $expected))
        ->and(substr($file->contents, 0, 3))->not->toBe("\xEF\xBB\xBF")
        ->and(mb_check_encoding($file->contents, 'UTF-8'))->toBeTrue()
        ->and(preg_match("/(?<!\r)\n/", $file->contents))->toBe(0);

    foreach (explode("\r\n", rtrim($file->contents, "\r\n")) as $line) {
        $type = substr($line, 0, 2);
        expect(substr_count($line, '|') + 1)->toBe(count(CicLayouts::fields($type)), $type);
    }
});

it('writes a correction header with type 1 and no comments as an empty last field', function () {
    $file = app(CicFileBuilder::class)->build($this->simpleCapture(), 1, null, Carbon::parse('2026-11-25 14:30:10'));

    expect(explode("\r\n", $file->contents)[0])->toBe('HD|TEST1234|31102026|1.0|1|');
});

it('is deterministic: the same inputs give the same bytes, another time another name', function () {
    $capture = cicGoldenCapture($this);
    $builder = app(CicFileBuilder::class);
    $at = Carbon::parse('2026-11-25 14:30:10');

    $first = $builder->build($capture, 0, 'Month-end file', $at);
    $second = $builder->build($capture->fresh(), 0, 'Month-end file', $at->copy());
    $later = $builder->build($capture, 0, 'Month-end file', $at->copy()->addSecond());

    expect($second->sha256())->toBe($first->sha256())
        ->and($later->filename)->toBe('TEST1234_CSDF_20261125143011.txt')
        ->and($later->contents)->toBe($first->contents);
});

it('refuses a provider code that is not 8 letters or digits', function (?string $code) {
    CicSetting::query()->orderBy('id')->firstOrFail()->forceFill(['provider_code' => $code])->save();

    expect(fn () => app(CicFileBuilder::class)->build($this->simpleCapture(), 0, null, now()))
        ->toThrow(CicSubmissionRefusal::class, CicFileBuilder::PROVIDER_CODE_REFUSAL);
})->with([null, 'ABC123', 'ABCD-123', 'ABCD12345']);

it('refuses a capture that is not complete', function () {
    $capture = $this->captureWith([['ID', 'BRW-00000001']], complete: false);

    expect(fn () => app(CicFileBuilder::class)->build($capture, 0, null, now()))
        ->toThrow(CicSubmissionRefusal::class, 'isn\'t complete');
});

it('finds values that would break the file, naming the field and key only', function () {
    $capture = $this->captureWith([
        ['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID7' => 'SANTOS|CRUZ']],
        ['CI', 'LN-000001', ['CI5' => 'BRW-00000001', 'CI9' => "two\r\nlines"]],
    ]);
    $builder = app(CicFileBuilder::class);

    $issues = $builder->unsafeValues($capture);

    expect($issues)->toHaveCount(2)
        ->and(array_column($issues, 'field_id'))->toBe(['ID7', 'CI9'])
        ->and(json_encode($issues))->not->toContain('SANTOS')
        ->and(fn () => $builder->build($capture, 0, null, now()))->toThrow(CicSubmissionRefusal::class);
});
