<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An AMLA record (a log entry, a settings version, a decided change request,
 * a calendar entry or year) was asked to change or go. AMLA records are kept
 * forever: a correction is a new row, never an edit or a delete.
 */
class AmlaRecordIsAppendOnlyException extends RuntimeException
{
    public static function update(string $table, mixed $id): self
    {
        return new self("{$table}.id ".($id ?? 'unknown').' cannot be changed: AMLA records are kept as written. Record a new row instead. Nothing was written.');
    }

    public static function delete(string $table, mixed $id): self
    {
        return new self("{$table}.id ".($id ?? 'unknown').' cannot be deleted: AMLA records are kept forever. Nothing was written.');
    }

    public static function bulk(string $table, string $operation): self
    {
        return new self("{$table} cannot be changed with {$operation}(): AMLA records are kept forever. Nothing was written.");
    }
}
