<?php

namespace App\Services\Cic\Records;

use App\Models\Borrower;
use App\Models\CicCodeMapping;
use App\Services\Cic\Settings\CicCodeMappingService;
use App\Services\Cic\Settings\CicIdKinds;
use App\Services\ValidIdService;
use Illuminate\Support\Facades\DB;

/**
 * A member's valid IDs as CSDF identifications (ID54–ID59, IdentificationTypeDomain)
 * and IDs (ID60–ID77, IDTypeDomain), read from their valid-ID documents.
 *
 * - A document's kind is read with CicIdKinds::valueOf(), the value the
 *   settings screen maps: a standard kind, or the "others" name typed on it
 *   (never one holding a digit, never a bare "others").
 * - Staff's `id_kind` code mapping for that kind decides the list by its
 *   `cic_domain`: IdentificationTypeDomain goes to ID54–ID59, IDTypeDomain to
 *   ID60–ID77. A kind with no mapping takes the built-in defaults:
 *   identifications tin_id 10, sss 11, umid 15; IDs philippine_id 32,
 *   drivers_license 10, passport 12, prc_id 13, postal_id 16, voters_id 11.
 *   Any other unmapped kind is left out: never a guess.
 * - TIN comes first among identifications, then the rest in upload order, one
 *   per code, at most 3 in each list.
 * - The front and back of one ID share a number, so a kind counts once. A
 *   document with no number is left out.
 */
final class CicIdentifications
{
    /** @var array<string, string> */
    public const IDENTIFICATION_DEFAULTS = ['tin_id' => '10', 'sss' => '11', 'umid' => '15'];

    /** @var array<string, string> */
    public const ID_TYPE_DEFAULTS = [
        'philippine_id' => '32',
        'drivers_license' => '10',
        'passport' => '12',
        'prc_id' => '13',
        'postal_id' => '16',
        'voters_id' => '11',
    ];

    /** CIC's TIN code, sent first. */
    private const TIN = '10';

    /** @var array<string, array{list: string, code: string}>|null kind, lower case → its mapped code list and code */
    private ?array $mappedCodes = null;

    /**
     * Each member's identifications and IDs, in one query for the whole list.
     *
     * @param  list<int>  $borrowerIds
     * @return array<int, array{identifications: list<array{code: string, number: string}>, ids: list<array{code: string, number: string}>}>
     */
    public function forBorrowers(array $borrowerIds): array
    {
        if ($borrowerIds === []) {
            return [];
        }

        $documents = DB::table('documents')
            ->where('documentable_type', Borrower::class)
            ->whereIn('documentable_id', $borrowerIds)
            ->where('type', ValidIdService::DOCUMENT_TYPE)
            ->whereNotNull('id_number')
            ->orderBy('id')
            ->get(['documentable_id', 'label', 'custom_type_name', 'id_number']);

        $result = [];

        foreach ($documents->groupBy('documentable_id') as $borrowerId => $rows) {
            $result[(int) $borrowerId] = $this->classify($rows->all());
        }

        return $result;
    }

    /**
     * @param  list<object{label: string|null, custom_type_name: string|null, id_number: string|null}>  $documents
     * @return array{identifications: list<array{code: string, number: string}>, ids: list<array{code: string, number: string}>}
     */
    private function classify(array $documents): array
    {
        $identifications = [];
        $ids = [];

        foreach ($documents as $document) {
            $number = CicFormat::text($document->id_number);
            $kind = CicIdKinds::valueOf($document->label, $document->custom_type_name);

            if ($number === '' || $kind === null) {
                continue;
            }

            [$list, $code] = $this->codeFor($kind);

            if ($list === CicCodeMappingService::IDENTIFICATION_TYPES) {
                $identifications[$code] ??= $number;
            } elseif ($list === CicCodeMappingService::ID_TYPES) {
                $ids[$code] ??= $number;
            }
        }

        uksort($identifications, fn (int|string $a, int|string $b) => ((string) $b === self::TIN) <=> ((string) $a === self::TIN));

        $pairs = fn (array $byCode) => array_slice(array_map(
            fn (string $code, string $number) => ['code' => $code, 'number' => $number],
            array_map('strval', array_keys($byCode)),
            array_values($byCode),
        ), 0, 3);

        return ['identifications' => $pairs($identifications), 'ids' => $pairs($ids)];
    }

    /**
     * The code list and code a kind reports under: staff's mapping, else the
     * built-in defaults, else none.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function codeFor(string $kind): array
    {
        $mapped = $this->mappedCodes()[mb_strtolower($kind)] ?? null;

        if ($mapped !== null) {
            return [$mapped['list'], $mapped['code']];
        }

        if (array_key_exists($kind, self::IDENTIFICATION_DEFAULTS)) {
            return [CicCodeMappingService::IDENTIFICATION_TYPES, self::IDENTIFICATION_DEFAULTS[$kind]];
        }

        if (array_key_exists($kind, self::ID_TYPE_DEFAULTS)) {
            return [CicCodeMappingService::ID_TYPES, self::ID_TYPE_DEFAULTS[$kind]];
        }

        return [null, null];
    }

    /** @return array<string, array{list: string, code: string}> */
    private function mappedCodes(): array
    {
        return $this->mappedCodes ??= CicCodeMapping::query()
            ->where('domain', CicCodeMapping::DOMAIN_ID_KIND)
            ->whereIn('cic_domain', CicCodeMappingService::CODE_LISTS[CicCodeMapping::DOMAIN_ID_KIND])
            ->get(['lendy_value', 'cic_domain', 'cic_code'])
            ->mapWithKeys(fn (CicCodeMapping $mapping) => [
                mb_strtolower(trim((string) $mapping->lendy_value)) => ['list' => (string) $mapping->cic_domain, 'code' => (string) $mapping->cic_code],
            ])
            ->all();
    }
}
