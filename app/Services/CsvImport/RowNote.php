<?php

namespace App\Services\CsvImport;

/**
 * One thing worth telling the operator about a single row.
 *
 * Warnings and errors are the same structure on purpose: an error is a warning
 * that stopped the row, and the difference is which list it lands in, not what
 * it contains.
 */
final class RowNote
{
    public function __construct(
        public readonly string $field,
        public readonly string $code,
        public readonly string $message,
    ) {}

    /**
     * @return array{field: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
