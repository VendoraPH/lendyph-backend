<?php

namespace App\Services\Portal\Read;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Whether a document type is a valid ID, matched the way the document upload
 * refuses one (StoreDocumentRequest): case ignored, spaces, `_` and `-`
 * dropped, so "valid_id", "Valid ID" and "valid-id" are all a valid ID. A
 * valid ID is never listed, streamed or shared in the Member Portal.
 */
final class ValidIdType
{
    private const NORMALISED = 'validid';

    public static function is(?string $type): bool
    {
        return str_replace([' ', '_', '-'], '', strtolower((string) $type)) === self::NORMALISED;
    }

    /**
     * Leave valid IDs out of a documents query, by the same rule. The type
     * column holds letters, digits, spaces, `_` and `-` only.
     */
    public static function exclude(Builder $query, string $column = 'type'): Builder
    {
        return $query->whereRaw(
            "LOWER(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '_', ''), '-', '')) <> ?",
            [self::NORMALISED],
        );
    }
}
