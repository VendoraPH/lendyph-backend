<?php

namespace App\Services\Cic\Records;

/**
 * The records one build made, by record type, each list sorted by key, in
 * the file's order (ID, CI, CN, NE).
 */
final class CicRecordSet
{
    /** @var array<string, list<CicRecord>> */
    public readonly array $byType;

    /**
     * @param  array<string, list<CicRecord>>  $byType
     * @param  list<array{record_type: string, record_key: string, field_id: string|null, severity: string, code: string, message: string}>  $leftOut
     *                                                                                                                                         the loans the build left out of the file, as issues in words
     */
    public function __construct(public readonly CicBuildContext $context, array $byType, public readonly array $leftOut = [])
    {
        $sorted = [];

        foreach (['ID', 'CI', 'CN', 'NE'] as $type) {
            $records = $byType[$type] ?? [];
            usort($records, fn (CicRecord $a, CicRecord $b) => strcmp($a->key, $b->key));
            $sorted[$type] = $records;
        }

        $this->byType = $sorted;
    }

    /** @return list<CicRecord> every record, in the file's order */
    public function all(): array
    {
        return array_merge(...array_values($this->byType));
    }

    /** @return list<CicRecord> */
    public function ofType(string $type): array
    {
        return $this->byType[$type] ?? [];
    }

    public function count(?string $type = null): int
    {
        return $type === null ? array_sum(array_map('count', $this->byType)) : count($this->ofType($type));
    }
}
