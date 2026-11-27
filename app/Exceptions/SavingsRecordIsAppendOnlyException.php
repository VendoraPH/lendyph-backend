<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A savings history row (a rate, a tax rule, a ledger transaction) was asked
 * to change. Those rows are append-only: a correction is a new row.
 */
class SavingsRecordIsAppendOnlyException extends RuntimeException
{
    public static function update(string $table, mixed $id): static
    {
        return new static("{$table}.id ".($id ?? 'unknown').' cannot be updated: the row is append-only. Record a new row instead. Nothing was written.');
    }

    public static function delete(string $table, mixed $id): static
    {
        return new static("{$table}.id ".($id ?? 'unknown').' cannot be deleted: the row is append-only. Nothing was written.');
    }

    public static function bulk(string $table, string $operation): static
    {
        return new static("{$table} cannot be changed with {$operation}(): its rows are append-only. Nothing was written.");
    }
}
