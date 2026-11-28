<?php

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicSetting;
use App\Services\Cic\CicValidator;
use App\Services\Cic\Contracts\ValidatesCicCapture;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The server's CSDF rules (CicValidator, ported from #699's validate.ts and
 * validate-rules.ts): field, dependent, cross-record and financial checks,
 * with messages in words, M/NM/D respected, and decision 4's warning.
 */

const CIC_CONTEXT = ['mfi' => false, 'provider_code' => 'LENDY001', 'reference_date' => '30112026'];

/** @return array<string, string> */
function cicSubjectValues(array $overrides = []): array
{
    return array_filter([
        'ID1' => 'ID', 'ID2' => 'LENDY001', 'ID4' => '30112026', 'ID5' => 'BRW-000001',
        'ID7' => 'Maria', 'ID8' => 'Cruz', 'ID13' => 'F', 'ID14' => '12041985',
        'ID32' => 'MI', 'ID34' => '12 Rizal St', 'ID38' => 'Naga', 'ID39' => 'Camarines Sur',
        'ID43' => 'AI', 'ID45' => '12 Rizal St', 'ID49' => 'Naga', 'ID50' => 'Camarines Sur',
        'ID54' => '10', 'ID55' => '123-456-789', 'ID78' => '3', 'ID79' => '09171234567',
        ...$overrides,
    ], fn (?string $v) => $v !== null);
}

/** @return array<string, string> */
function cicContractValues(array $overrides = []): array
{
    return array_filter([
        'CI1' => 'CI', 'CI2' => 'LENDY001', 'CI4' => '30112026', 'CI5' => 'BRW-000001', 'CI6' => 'B', 'CI7' => 'LN-000001',
        'CI8' => '12', 'CI9' => 'AC', 'CI11' => 'PHP', 'CI12' => 'PHP', 'CI13' => '15092026', 'CI15' => '15122026',
        'CI18' => '0', 'CI20' => '30000', 'CI21' => '3', 'CI22' => 'NA', 'CI24' => 'M', 'CI26' => '10300', 'CI27' => '15102026',
        'CI28' => '0', 'CI31' => '3', 'CI32' => '30900', 'CI33' => '1', 'CI34' => '10300', 'CI35' => '2',
        ...$overrides,
    ], fn (?string $v) => $v !== null);
}

/**
 * @param  list<array{0: string, 1: string, 2: array<string, string>}>  $records
 * @return list<array<string, mixed>>
 */
function cicValidate(array $records, array $context = CIC_CONTEXT, array $buildIssues = []): array
{
    $rows = array_map(fn (array $r) => ['record_type' => $r[0], 'record_key' => $r[1], 'values' => $r[2], 'build_issues' => $buildIssues[$r[1]] ?? []], $records);
    $subjects = array_column(array_filter($rows, fn (array $r) => $r['record_type'] === 'ID'), 'record_key');

    return app(CicValidator::class)->validate($rows, $subjects, $context);
}

/** @param  list<array<string, mixed>>  $issues */
function cicCodes(array $issues, ?string $field = null): array
{
    return array_values(array_map(fn (array $i) => $i['code'], array_filter($issues, fn (array $i) => $field === null || $i['field_id'] === $field)));
}

it('is the bound capture validator', function () {
    expect(app(ValidatesCicCapture::class))->toBeInstanceOf(CicValidator::class);
});

it('passes a complete subject and contract with no issue', function () {
    expect(cicValidate([['ID', 'BRW-000001', cicSubjectValues()], ['CI', 'LN-000001', cicContractValues()]]))->toBe([]);
});

it('makes a missing Mandatory field an error, and never a missing Not Mandatory field', function () {
    $issues = cicValidate([['ID', 'BRW-000001', cicSubjectValues(['ID14' => null, 'ID9' => null, 'ID19' => null])]]);

    expect(cicCodes($issues))->toBe(['MISSING_MANDATORY'])
        ->and($issues[0])->toMatchArray(['record_type' => 'ID', 'record_key' => 'BRW-000001', 'field_id' => 'ID14', 'severity' => 'error', 'message' => 'Date of Birth is mandatory.']);
});

it('checks type, length, code list and fixed value', function () {
    $issues = cicValidate([['ID', 'BRW-000001', cicSubjectValues(['ID1' => 'XX', 'ID13' => 'Q', 'ID14' => '31022026', 'ID7' => str_repeat('a', 71)])], ['CI', 'LN-000001', cicContractValues(['CI20' => '30000.5', 'CI32' => '-5'])]]);

    expect(cicCodes($issues, 'ID1'))->toBe(['FIXED_VALUE'])
        ->and(cicCodes($issues, 'ID13'))->toBe(['NOT_IN_DOMAIN'])
        ->and(cicCodes($issues, 'ID14'))->toBe(['INVALID_DATE'])
        ->and(cicCodes($issues, 'ID7'))->toBe(['TOO_LONG'])
        ->and(cicCodes($issues, 'CI20'))->toBe(['INVALID_NUMBER'])
        ->and(cicCodes($issues, 'CI32'))->toBe(['NEGATIVE_NUMBER']);
});

it('requires Dependent fields only while their condition applies', function () {
    $closed = cicContractValues(['CI9' => 'CL', 'CI31' => null, 'CI32' => null]);
    $closedWithOutstanding = cicContractValues(['CI9' => 'CL', 'CI16' => '20112026']);
    $noCity = cicSubjectValues(['ID38' => null, 'ID49' => null]);
    $fullAddress = cicSubjectValues(['ID34' => null, 'ID38' => null, 'ID39' => null, 'ID33' => '12 Rizal St, Naga', 'ID45' => null, 'ID49' => null, 'ID50' => null, 'ID44' => '12 Rizal St, Naga']);
    $pair = cicSubjectValues(['ID57' => '12-3']);

    $issues = cicValidate([['ID', 'BRW-000001', $noCity], ['ID', 'BRW-000002', ['ID5' => 'BRW-000002'] + $fullAddress], ['ID', 'BRW-000003', ['ID5' => 'BRW-000003'] + $pair], ['CI', 'LN-1', $closed], ['CI', 'LN-2', ['CI7' => 'LN-2'] + $closedWithOutstanding]]);
    $of = fn (string $key) => array_values(array_filter($issues, fn (array $i) => $i['record_key'] === $key));

    expect(array_column($of('BRW-000001'), 'field_id'))->toEqualCanonicalizing(['ID38', 'ID49'])
        ->and($of('BRW-000001')[0]['message'])->toContain('is required because Address')
        ->and($of('BRW-000002'))->toBe([])
        ->and(array_column($of('BRW-000003'), 'field_id'))->toBe(['ID56'])
        ->and(array_column($of('LN-1'), 'field_id'))->toBe(['CI16'])
        ->and(cicCodes($of('LN-2')))->toBe(['MUST_BE_EMPTY', 'MUST_BE_EMPTY']);
});

it('warns, never blocks, on a subject with no identification (plan decision 4), and requires a contact for a non-MFI', function () {
    $issues = cicValidate([['ID', 'BRW-000001', cicSubjectValues(['ID54' => null, 'ID55' => null, 'ID78' => null, 'ID79' => null])]]);

    expect($issues)->toHaveCount(2)
        ->and($issues[0])->toMatchArray(['field_id' => 'ID78', 'severity' => 'error', 'code' => 'MISSING_DEPENDENT'])
        ->and($issues[1])->toMatchArray(['field_id' => 'ID54', 'severity' => 'warning', 'code' => 'NO_IDENTIFICATION']);
});

it('checks the records against each other: subjects in the file, unique numbers', function () {
    $issues = cicValidate([
        ['ID', 'BRW-000001', cicSubjectValues()],
        ['ID', 'BRW-000001', cicSubjectValues()],
        ['CI', 'LN-000001', cicContractValues(['CI5' => 'BRW-000009', 'CI126' => 'BRW-000008', 'CI127' => 'G', 'CI43' => 'BRW-000007', 'CI45' => '100', 'CI46' => 'PHP', 'CI49' => '201'])],
        ['CN', 'LN-000001', ['CN1' => 'CN', 'CN4' => '30112026', 'CN5' => 'BRW-000001', 'CN6' => 'B', 'CN7' => 'LN-000001', 'CN8' => '48', 'CN9' => 'AC', 'CN11' => 'PHP', 'CN12' => 'PHP', 'CN13' => '15092026', 'CN15' => '15122026', 'CN20' => '1', 'CN21' => 'NA', 'CN23' => '1']],
        ['NE', 'BRW-000005', ['NE1' => 'NE', 'NE4' => '30112026', 'NE5' => 'BRW-000005', 'NE6' => 'U']],
    ]);

    expect(cicCodes($issues))->toContain('DUPLICATE_RECORD', 'DUPLICATE_SUBJECT', 'SUBJECT_NOT_IN_FILE', 'LINKED_SUBJECT_NOT_IN_FILE', 'GUARANTOR_NOT_IN_FILE', 'DUPLICATE_CONTRACT')
        ->and(array_column(array_filter($issues, fn (array $i) => $i['code'] === 'SUBJECT_NOT_IN_FILE'), 'record_type'))->toEqualCanonicalizing(['CI', 'NE']);
});

it('checks contract dates and overdue figures, and warns on an open loan with nothing owed', function () {
    $issues = cicValidate([
        ['ID', 'BRW-000001', cicSubjectValues()],
        ['CI', 'LN-1', cicContractValues(['CI13' => '30112026', 'CI15' => '29112026'])],
        ['CI', 'LN-2', cicContractValues(['CI7' => 'LN-2', 'CI9' => 'CA', 'CI16' => '14092026', 'CI31' => null, 'CI32' => null])],
        ['CI', 'LN-3', cicContractValues(['CI7' => 'LN-3', 'CI9' => 'CA', 'CI16' => '15092026', 'CI31' => null, 'CI32' => null])],
        ['CI', 'LN-4', cicContractValues(['CI7' => 'LN-4', 'CI33' => '2', 'CI34' => '0', 'CI35' => '0'])],
        ['CI', 'LN-5', cicContractValues(['CI7' => 'LN-5', 'CI32' => '0', 'CI33' => '0', 'CI34' => '0', 'CI35' => '0'])],
    ]);
    $of = fn (string $key) => cicCodes(array_filter($issues, fn (array $i) => $i['record_key'] === $key));

    expect($of('LN-1'))->toBe(['DATE_ORDER', 'DATE_ORDER'])
        ->and($of('LN-2'))->toBe(['DATE_ORDER'])
        ->and($of('LN-3'))->toBe([])
        ->and($of('LN-4'))->toBe(['OVERDUE_INCONSISTENT', 'OVERDUE_DAYS_INCONSISTENT'])
        ->and($of('LN-5'))->toBe(['OPEN_NOTHING_OWED']);
});

it('checks the provider code once on the header, puts errors first, and reads an empty file as a warning', function () {
    $issues = cicValidate([], ['mfi' => false, 'provider_code' => 'ABC', 'reference_date' => '30112026']);

    expect($issues)->toBe([
        ['record_type' => 'HD', 'record_key' => '', 'field_id' => 'HD2', 'severity' => 'error', 'code' => 'PROVIDER_CODE', 'message' => 'The CIC provider code must be exactly 8 letters or digits. Set it in CIC Settings.'],
        ['record_type' => 'FT', 'record_key' => '', 'field_id' => null, 'severity' => 'warning', 'code' => 'EMPTY_FILE', 'message' => 'The file has no records.'],
    ]);
});

it('reports the builder\'s issues once, in place of the generic check on their field', function () {
    $issues = cicValidate([['ID', 'BRW-000001', cicSubjectValues()], ['CI', 'LN-000001', cicContractValues(['CI8' => null])]], buildIssues: [
        'LN-000001' => [['field_id' => 'CI8', 'severity' => 'error', 'code' => 'UNMAPPED_PRODUCT', 'message' => 'The loan product "X" has no CIC mapping.']],
    ]);

    expect(cicCodes($issues))->toBe(['UNMAPPED_PRODUCT']);
});

it('validates a stored capture through the interface', function () {
    CicSetting::current()->forceFill(['provider_code' => 'LENDY001'])->save();
    $capture = CicCapture::factory()->monthEnd('2026-11-30')->create();
    foreach ([['ID', 'BRW-000001', cicSubjectValues(['ID14' => null])], ['CI', 'LN-000001', cicContractValues()]] as [$type, $key, $values]) {
        $record = new CicCaptureRecord;
        $record->forceFill(['capture_id' => $capture->id, 'record_type' => $type, 'record_key' => $key, 'values' => $values, 'checksum' => CicCaptureRecord::checksumOf($values)])->save();
    }

    expect(app(ValidatesCicCapture::class)->issues($capture))->toBe([
        ['record_type' => 'ID', 'record_key' => 'BRW-000001', 'field_id' => 'ID14', 'severity' => 'error', 'code' => 'MISSING_MANDATORY', 'message' => 'Date of Birth is mandatory.'],
    ]);
});

it('never names a permission or a stored key in a message', function () {
    $issues = cicValidate([['ID', 'BRW-000001', cicSubjectValues(['ID14' => null, 'ID38' => null, 'ID54' => null, 'ID55' => null])]]);

    foreach ($issues as $issue) {
        expect($issue['message'])->not->toContain('cic:')->not->toContain('borrowers:')->not->toContain('_');
    }
});
