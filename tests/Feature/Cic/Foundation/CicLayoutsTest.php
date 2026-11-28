<?php

use App\Services\Cic\CicDomains;
use App\Services\Cic\CicLayouts;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The CSDF 2016 layouts and code lists (resources/cic/2016/), generated from
 * CIC's workbooks as encoded in frontend PR #699. The counts are pinned to the
 * CIC task prompt's §1.2 table: 612 fields, M/D/NM per record.
 */

it('holds the workbook\'s fields per record, as the prompt\'s table counts them', function () {
    $counts = [];

    foreach (CicLayouts::RECORD_TYPES as $type) {
        $counts[$type] = CicLayouts::classificationCounts($type);
    }

    expect(CicLayouts::recordTypes())->toBe(CicLayouts::RECORD_TYPES)
        ->and($counts)->toBe([
            'HD' => ['M' => 5, 'D' => 0, 'NM' => 1],
            'ID' => ['M' => 9, 'D' => 47, 'NM' => 67],
            'BD' => ['M' => 7, 'D' => 18, 'NM' => 24],
            'SL' => ['M' => 6, 'D' => 0, 'NM' => 1],
            'CI' => ['M' => 14, 'D' => 58, 'NM' => 71],
            'CN' => ['M' => 12, 'D' => 52, 'NM' => 63],
            'CC' => ['M' => 13, 'D' => 57, 'NM' => 73],
            'NE' => ['M' => 5, 'D' => 0, 'NM' => 5],
            'FT' => ['M' => 4, 'D' => 0, 'NM' => 0],
        ])
        ->and(CicLayouts::fieldCount())->toBe(612);
});

it('numbers each record\'s fields in file order, with every key on every field', function () {
    foreach (CicLayouts::RECORD_TYPES as $type) {
        foreach (CicLayouts::fields($type) as $position => $field) {
            expect($field['id'])->toBe($type.($position + 1))
                ->and(array_keys($field))->toBe(['id', 'name', 'type', 'classification', 'maxLength', 'domain', 'fixed', 'rule'])
                ->and($field['type'])->toBeIn(['X', 'N', 'D'])
                ->and($field['maxLength'])->toBeGreaterThan(0);
        }

        expect(CicLayouts::fixedValues($type)[$type.'1'] ?? null)->toBe($type);
    }
});

it('looks fields up by id', function () {
    expect(CicLayouts::field('HD4'))->toMatchArray(['name' => 'Version', 'fixed' => '1.0', 'classification' => 'M'])
        ->and(CicLayouts::field('ID5'))->toMatchArray(['name' => 'Provider Subject No', 'maxLength' => 38])
        ->and(CicLayouts::field('ID13')['domain'])->toBe('GenderDomain')
        ->and(CicLayouts::field('ZZ1'))->toBeNull()
        ->and(CicLayouts::fieldIds('FT'))->toBe(['FT1', 'FT2', 'FT3', 'FT4'])
        ->and(fn () => CicLayouts::fields('ZZ'))->toThrow(InvalidArgumentException::class);
});

it('holds every code list a field names', function () {
    foreach (CicLayouts::all() as $fields) {
        foreach ($fields as $field) {
            if ($field['domain'] !== null) {
                expect(CicDomains::has($field['domain']))->toBeTrue("{$field['id']} names {$field['domain']}, which isn't bundled.");
            }
        }
    }

    expect(count(CicDomains::names()))->toBe(38)
        ->and(array_sum(array_map('count', CicDomains::all())))->toBe(801)
        ->and(CicDomains::contains('AddressTypeDomain', 'MI'))->toBeTrue()
        ->and(CicDomains::contains('AddressTypeDomain', 'mi'))->toBeFalse()
        ->and(CicDomains::label('AddressTypeDomain', 'AI'))->toBe('Individual - Additional Address (Mailing)')
        ->and(CicDomains::contains('CountryDomain', 'PH'))->toBeTrue()
        ->and(fn () => CicDomains::codes('NoSuchDomain'))->toThrow(InvalidArgumentException::class);
});
