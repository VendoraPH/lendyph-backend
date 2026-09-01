<?php

namespace App\Services\CsvImport;

use RuntimeException;

/**
 * The file as a whole cannot be imported — as distinct from a row that failed.
 *
 * Thrown only for conditions where continuing would produce silently wrong
 * data rather than a visible per-row error: a UTF-16 file, CR-only line
 * endings, or a record width that does not match the declared shape. All three
 * parse "successfully" in the sense that fgetcsv returns something; what they
 * return is nonsense mapped onto real columns.
 */
class CsvFileRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
