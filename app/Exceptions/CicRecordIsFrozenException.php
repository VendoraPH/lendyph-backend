<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A CIC record that must stay as it was was asked to change: a complete
 * capture or its records, a subject's number, a public key's material or a
 * second active key, a submission's history. A correction is a new capture or
 * submission, never an edit. Nothing was written.
 */
class CicRecordIsFrozenException extends RuntimeException
{
    public static function because(string $table, mixed $id, string $reason): static
    {
        return new static("{$table}.id ".($id ?? 'new').": {$reason} Nothing was written.");
    }

    public static function bulk(string $table, string $operation): static
    {
        return new static("{$table} cannot be changed with {$operation}(): write each row through its model, which checks it. Nothing was written.");
    }
}
