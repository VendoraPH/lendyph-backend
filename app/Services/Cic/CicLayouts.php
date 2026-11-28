<?php

namespace App\Services\Cic;

use InvalidArgumentException;

/**
 * The CSDF 2016 record layouts (resources/cic/2016/layouts.json, from CIC's
 * `data format.xlsx` as encoded in frontend PR #699): each record type's
 * fields in file order. Read-only: the official layouts are never edited here.
 *
 * A field is `{id, name, type, classification, maxLength, domain, fixed,
 * rule}`: type X (text), N (number) or D (date, DDMMYYYY); classification M
 * (mandatory), D (dependent: `rule` is the workbook's condition) or NM (not
 * mandatory); `domain` names a CicDomains list; `fixed` is a value the record
 * always carries (the record code, HD4's "1.0").
 *
 * @phpstan-type CicField array{id: string, name: string, type: string, classification: string, maxLength: int, domain: string|null, fixed: string|null, rule: string|null}
 */
final class CicLayouts
{
    public const PATH = 'cic/2016/layouts.json';

    /** @var list<string> every record type, in the order a file lists them */
    public const RECORD_TYPES = ['HD', 'ID', 'BD', 'SL', 'CI', 'CN', 'CC', 'NE', 'FT'];

    /** @var array<string, list<CicField>>|null */
    private static ?array $layouts = null;

    /** @var array<string, CicField>|null */
    private static ?array $byId = null;

    /** @return array<string, list<CicField>> every record type's fields */
    public static function all(): array
    {
        if (self::$layouts === null) {
            /** @var array<string, list<CicField>> $layouts */
            $layouts = json_decode((string) file_get_contents(resource_path(self::PATH)), true, flags: JSON_THROW_ON_ERROR);
            self::$layouts = $layouts;
        }

        return self::$layouts;
    }

    /** @return list<string> */
    public static function recordTypes(): array
    {
        return array_keys(self::all());
    }

    public static function hasRecordType(string $recordType): bool
    {
        return isset(self::all()[$recordType]);
    }

    /**
     * A record type's fields, in file order.
     *
     * @return list<CicField>
     */
    public static function fields(string $recordType): array
    {
        return self::all()[$recordType] ?? throw new InvalidArgumentException("There is no CIC record type {$recordType}.");
    }

    /**
     * A field by its id ("ID7", "CI128"), or null when there is none.
     *
     * @return CicField|null
     */
    public static function field(string $fieldId): ?array
    {
        if (self::$byId === null) {
            self::$byId = [];

            foreach (self::all() as $fields) {
                foreach ($fields as $field) {
                    self::$byId[$field['id']] = $field;
                }
            }
        }

        return self::$byId[$fieldId] ?? null;
    }

    /** @return list<string> a record type's field ids, in file order */
    public static function fieldIds(string $recordType): array
    {
        return array_column(self::fields($recordType), 'id');
    }

    /**
     * The fixed values a record always carries, by field id.
     *
     * @return array<string, string>
     */
    public static function fixedValues(string $recordType): array
    {
        $fixed = [];

        foreach (self::fields($recordType) as $field) {
            if ($field['fixed'] !== null) {
                $fixed[$field['id']] = $field['fixed'];
            }
        }

        return $fixed;
    }

    /**
     * How many fields a record type has of each classification.
     *
     * @return array{M: int, D: int, NM: int}
     */
    public static function classificationCounts(string $recordType): array
    {
        $counts = ['M' => 0, 'D' => 0, 'NM' => 0];

        foreach (self::fields($recordType) as $field) {
            $counts[$field['classification']]++;
        }

        return $counts;
    }

    /** Every field, all record types together. */
    public static function fieldCount(): int
    {
        return array_sum(array_map('count', self::all()));
    }
}
