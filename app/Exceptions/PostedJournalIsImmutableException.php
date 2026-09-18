<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something tried to change or delete an entry that is already in the books.
 *
 * A posted journal is a historical fact. Correcting one is done by posting a
 * REVERSAL — a second entry that mirrors the first — so both halves stay
 * visible and the trail of what was believed, when, survives. Editing the
 * original instead would re-report a figure that has already been published,
 * silently, with nothing left to say it ever changed.
 *
 * This is deliberately a RuntimeException rather than a ValidationException.
 * The API layers refuse these attempts with a 422 BEFORE reaching a model — see
 * JournalPoster::updateDraft() — so anything that gets this far is a code path
 * that bypassed the gate, not a user who typed the wrong thing. It should
 * surface as a 500 and a stack trace naming the caller, not as a tidy field
 * error on a form.
 */
class PostedJournalIsImmutableException extends RuntimeException
{
    public static function update(int $journalId, string $status, array $fields): self
    {
        $list = $fields === [] ? 'nothing' : implode(', ', $fields);

        return new self(
            "accounting_journals.id {$journalId} is {$status} and cannot be updated (attempted: {$list}). "
            .'Post a reversal instead: reversing writes a mirror entry and leaves both on the record, '
            .'which is what makes the books auditable. Nothing was written.'
        );
    }

    public static function delete(int $journalId, string $status): self
    {
        return new self(
            "accounting_journals.id {$journalId} is {$status} and cannot be deleted. "
            .'A posted entry is half of a balance that other entries were built on; removing it would leave '
            .'the books out of balance with no record of what went missing. Reverse it instead.'
        );
    }

    public static function line(int $journalId, string $status, string $action): self
    {
        return new self(
            "A line of accounting_journals.id {$journalId} cannot be {$action}: the journal is {$status}. "
            .'Lines are only editable while the entry is a draft — once posted, the entry is the record. '
            .'Nothing was written.'
        );
    }
}
