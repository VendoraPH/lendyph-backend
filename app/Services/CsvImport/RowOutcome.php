<?php

namespace App\Services\CsvImport;

/**
 * What the import pass decided about one staged row — the exact set of values
 * that goes into `csv_import_rows.result` and its two companion columns.
 *
 * `matched_existing` and `already_imported` are kept apart on purpose. The first
 * means the member was already on file from normal operations and was reused;
 * the second means a previous run of THIS import wrote them. Both are
 * non-failures, but only the second tells the admin they uploaded the file
 * twice — and they will ask.
 */
final class RowOutcome
{
    private function __construct(
        public readonly string $result,
        public readonly ?string $category = null,
        public readonly ?string $message = null,
        public readonly ?int $borrowerId = null,
        public readonly ?int $loanId = null,
    ) {}

    public static function imported(?int $borrowerId = null, ?int $loanId = null, ?string $category = null, ?string $message = null): self
    {
        return new self('imported', $category, $message, $borrowerId, $loanId);
    }

    public static function matchedExisting(?int $borrowerId, string $message, ?int $loanId = null): self
    {
        return new self('matched_existing', 'matched_existing', $message, $borrowerId, $loanId);
    }

    public static function alreadyImported(?int $borrowerId, string $message, ?int $loanId = null): self
    {
        return new self('already_imported', 'already_imported', $message, $borrowerId, $loanId);
    }

    public static function skipped(string $category, string $message, ?int $borrowerId = null): self
    {
        return new self('skipped', $category, $message, $borrowerId);
    }

    public static function failed(string $category, string $message, ?int $borrowerId = null): self
    {
        return new self('failed', $category, $message, $borrowerId);
    }
}
