<?php

namespace App\Services\CsvImport;

/**
 * One thing worth telling the operator about a single row.
 *
 * Warnings and errors are the same structure on purpose: an error is a warning
 * that stopped the row, and the difference is which list it lands in, not what
 * it contains.
 *
 * A warning says what the import did to a cell ("so it was imported as
 * blank"). `ifNotImported` says the same for a row that was never imported
 * (refused at the checks, skipped or failed at import, or left when its run
 * closed): what would happen to the cell once the row imports. The error report
 * shows it on such a row, and imported rows keep `message`. Null when the
 * message reads true either way.
 */
final class RowNote
{
    public function __construct(
        public readonly string $field,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $ifNotImported = null,
    ) {}

    /**
     * @return array{field: string, code: string, message: string, message_if_not_imported?: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
            ...($this->ifNotImported === null ? [] : ['message_if_not_imported' => $this->ifNotImported]),
        ];
    }
}
