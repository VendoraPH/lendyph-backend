<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The highest existing row carries a code the next one cannot be derived from.
 *
 * Thrown instead of guessing, because guessing here is unrecoverable in a way
 * that is easy to underestimate. `borrower_code` and `application_number` are
 * allocated as "highest row's number, plus one". Read that number with a bare
 * cast and a malformed code — `BRW-`, `BRW-00A1`, an empty string, a value some
 * old migration or manual fix left behind — evaluates to 0, so the next
 * allocation is 000001. That code already exists, the unique index rejects it,
 * and it rejects the SAME code on every subsequent attempt: the sequence never
 * advances again. Not the importer's sequence — THE sequence. Every teller
 * creating a borrower or a loan fails too, permanently, until somebody finds the
 * one bad row.
 *
 * Failing loudly on the first allocation after the bad row costs one failed
 * request and names the row to fix. The alternative costs every create the
 * system will ever attempt.
 */
class MalformedSequenceCodeException extends RuntimeException
{
    /**
     * `$sequenceCode` rather than `$code`: Exception already has a non-readonly
     * `$code`, and redeclaring it is a fatal error rather than a shadow.
     */
    public function __construct(
        string $message,
        public readonly string $prefix,
        public readonly ?string $sequenceCode,
        public readonly string $where,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  string  $prefix  The code family, e.g. `BRW`.
     * @param  string|null  $code  The unparseable value, as read.
     * @param  string  $where  Enough to find the row, e.g. `borrowers.id 812`.
     */
    public static function for(string $prefix, ?string $code, string $where): self
    {
        $shown = $code === null ? 'NULL' : "[{$code}]";

        return new self(
            "Cannot allocate the next {$prefix}- code: the highest existing row ({$where}) carries {$shown}, "
            ."which is not of the form {$prefix}-000000, so the next number cannot be derived from it. "
            .'Nothing was written. Fix or remove that row: deriving a number from it anyway would restart the '
            ."sequence at {$prefix}-000001, which already exists, and every create after that — importer and "
            .'teller alike — would fail on the unique index with no way to recover.',
            $prefix,
            $code,
            $where,
        );
    }
}
