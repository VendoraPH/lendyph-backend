<?php

namespace App\Services\CsvImport;

/**
 * One data record as it came off the file, after encoding repair and before
 * any normalisation.
 */
final class CsvRow
{
    /**
     * @param  int  $recordNumber  1-based index among ALL records including the header.
     * @param  int  $rowNumber  1-based index among DATA records only — what an operator counts.
     * @param  list<string>  $cells
     */
    public function __construct(
        public readonly int $recordNumber,
        public readonly int $rowNumber,
        public readonly array $cells,
    ) {}

    public function cell(int $index): ?string
    {
        return $this->cells[$index] ?? null;
    }
}
