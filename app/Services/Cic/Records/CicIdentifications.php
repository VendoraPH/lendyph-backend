<?php

namespace App\Services\Cic\Records;

use App\Models\Borrower;
use App\Models\CicCodeMapping;
use App\Services\ValidIdService;
use Illuminate\Support\Facades\DB;

/**
 * A member's valid IDs as CSDF identifications (ID54–ID59, IdentificationTypeDomain)
 * and IDs (ID60–ID77, IDTypeDomain), read from their valid-ID documents.
 *
 * - Identifications: tin_id 10, sss 11, umid 15 by default. Staff's `id_kind`
 *   code mappings add or replace kinds, matched on the document's kind, or on
 *   its own type name for "others" (GSIS, PhilHealth, Senior Citizen): never a
 *   guess. A mapping's code is an IdentificationTypeDomain code. TIN comes
 *   first, then the rest in upload order, one per type, at most 3.
 * - IDs: philippine_id 32, drivers_license 10, passport 12, prc_id 13,
 *   postal_id 16, voters_id 11 (the mapping's defaults), one per type, at
 *   most 3. A kind used as an identification isn't repeated as an ID.
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

    /** @var array<string, string>|null kind or "others" name, lower case → identification code */
    private ?array $identificationCodes = null;

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

            if ($number === '') {
                continue;
            }

            $kind = mb_strtolower(trim((string) $document->label));
            $name = mb_strtolower(CicFormat::text($document->custom_type_name));
            $identification = $this->identificationCode($kind, $name);

            if ($identification !== null) {
                $identifications[$identification] ??= $number;

                continue;
            }

            $idType = self::ID_TYPE_DEFAULTS[$kind] ?? null;

            if ($idType !== null) {
                $ids[$idType] ??= $number;
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

    private function identificationCode(string $kind, string $customName): ?string
    {
        $codes = $this->identificationCodes();

        if ($kind === 'others') {
            return $customName === '' ? null : ($codes[$customName] ?? null);
        }

        return $codes[$kind] ?? null;
    }

    /** @return array<string, string> */
    private function identificationCodes(): array
    {
        if ($this->identificationCodes === null) {
            $mapped = CicCodeMapping::query()
                ->where('domain', CicCodeMapping::DOMAIN_ID_KIND)
                ->get(['lendy_value', 'cic_code'])
                ->mapWithKeys(fn (CicCodeMapping $mapping) => [mb_strtolower(CicFormat::text($mapping->lendy_value)) => (string) $mapping->cic_code])
                ->all();

            $this->identificationCodes = [...self::IDENTIFICATION_DEFAULTS, ...$mapped];
        }

        return $this->identificationCodes;
    }
}
