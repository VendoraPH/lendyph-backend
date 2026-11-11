<?php

namespace App\Services\Portal\Read;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Whether a document type is a valid ID: lowercased, with every character
 * outside `[a-z0-9]` dropped (as the document upload's rule does), a type
 * that contains "validid" is a valid ID. So "valid_id", "Valid ID",
 * "Valid.ID", "valid/id" and "Valid ID (back)" all are, including a file
 * typed before the upload rule existed. A valid ID is never listed, streamed
 * or shared in the Member Portal.
 */
final class ValidIdType
{
    private const NORMALISED = 'validid';

    public static function is(?string $type): bool
    {
        return str_contains((string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $type)), self::NORMALISED);
    }

    /**
     * Leave valid IDs out of a documents query, by the same rule (MySQL 8,
     * REGEXP_REPLACE).
     */
    public static function exclude(Builder $query, string $column = 'type'): Builder
    {
        return $query->whereRaw(
            "REGEXP_REPLACE(LOWER(COALESCE({$column}, '')), '[^a-z0-9]', '') NOT LIKE ?",
            ['%'.self::NORMALISED.'%'],
        );
    }
}
