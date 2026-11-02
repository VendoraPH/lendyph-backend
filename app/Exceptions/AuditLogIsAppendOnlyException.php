<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something tried to change or delete an audit log row.
 *
 * The audit trail is the record of who did what and when; a row that can be
 * rewritten or removed proves nothing. A correction is a NEW row describing
 * it. No API path edits audit rows, so anything that reaches this is a code
 * path that should not exist: it surfaces as a 500 with a stack trace naming
 * the caller, the same way PostedJournalIsImmutableException does.
 */
class AuditLogIsAppendOnlyException extends RuntimeException
{
    public static function update(?int $id): self
    {
        return new self(
            'audit_logs.id '.($id ?? 'unknown').' cannot be updated: audit rows are append-only. '
            .'Record a correction as a new audit row instead. Nothing was written.'
        );
    }

    public static function bulk(string $operation): self
    {
        return new self(
            "audit_logs cannot be changed with {$operation}(): audit rows are append-only. Nothing was written."
        );
    }

    public static function delete(?int $id): self
    {
        return new self(
            'audit_logs.id '.($id ?? 'unknown').' cannot be deleted: audit rows are append-only. Nothing was written.'
        );
    }
}
