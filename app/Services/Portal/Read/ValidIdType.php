<?php

namespace App\Services\Portal\Read;

use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

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

    /** The columns a caller may filter on; the name goes into raw SQL, so nothing else is accepted. */
    private const COLUMNS = ['type', 'documents.type'];

    public static function is(?string $type): bool
    {
        return str_contains((string) preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $type, 'UTF-8')), self::NORMALISED);
    }

    /**
     * Leave valid IDs out of a documents query, by the same rule (MySQL 8,
     * REGEXP_REPLACE; LOWER folds non-ASCII letters as mb_strtolower does).
     * Only a column in COLUMNS is accepted.
     */
    public static function exclude(Builder $query, string $column = 'type'): Builder
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("ValidIdType can't filter on the column {$column}.");
        }

        return $query->whereRaw(
            "REGEXP_REPLACE(LOWER(COALESCE({$column}, '')), '[^a-z0-9]', '') NOT LIKE ?",
            ['%'.self::NORMALISED.'%'],
        );
    }
}
