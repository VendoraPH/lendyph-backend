<?php

namespace App\Services\Cic\Settings;

use App\Models\Borrower;
use App\Models\Document;
use App\Services\Amla\Cdd\IdTypes;

/**
 * The valid-ID kinds Lendy stores on members' documents, as CIC code mappings
 * name them (`id_kind`): the standard kinds (philippine_id, tin_id and so on)
 * and each distinct "others" name typed on a member's valid ID. The value is
 * read the AMLA way (IdTypes::fromDocument): an "others" name holding a digit
 * is never used, since it could carry an ID number, and a bare "others" has no
 * kind to map.
 *
 * The records slice reads a document's kind with valueOf(), so the value it
 * looks up is the value mapped here.
 */
final class CicIdKinds
{
    /** The kind a member document's valid ID maps by, or null when it has none to map. */
    public static function valueOf(?string $label, ?string $customTypeName): ?string
    {
        $value = IdTypes::fromDocument($label, $customTypeName);

        return $value === IdTypes::OTHERS ? null : $value;
    }

    /** @return array<string, string> the standard kinds and their words */
    public static function standard(): array
    {
        return array_diff_key(IdTypes::TYPES, [IdTypes::OTHERS => true]);
    }

    /**
     * Every kind: the standard ones first, then the "others" names found on
     * member documents, in alphabetical order.
     *
     * @return list<array{value: string, name: string, standard: bool}>
     */
    public static function all(): array
    {
        $kinds = [];

        foreach (self::standard() as $value => $words) {
            $kinds[] = ['value' => $value, 'name' => $words, 'standard' => true];
        }

        $others = Document::query()
            ->where('documentable_type', (new Borrower)->getMorphClass())
            ->where('type', 'valid_id')
            ->where('label', IdTypes::OTHERS)
            ->whereNotNull('custom_type_name')
            ->distinct()
            ->pluck('custom_type_name')
            ->map(fn ($name) => self::valueOf(IdTypes::OTHERS, (string) $name))
            ->filter(fn (?string $value) => $value !== null && ! array_key_exists($value, self::standard()))
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        foreach ($others as $value) {
            $kinds[] = ['value' => $value, 'name' => $value, 'standard' => false];
        }

        return $kinds;
    }
}
