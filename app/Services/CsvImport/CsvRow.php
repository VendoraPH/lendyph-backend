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
     * @param  int|null  $fileWidth  The width of the layout the file is in
     *                               (CsvImportSchema::acceptedWidths()), which every one of its
     *                               rows is held to. Null for a row built outside a file: it is
     *                               held to the shape's full width.
     */
    public function __construct(
        public readonly int $recordNumber,
        public readonly int $rowNumber,
        public readonly array $cells,
        public readonly ?int $fileWidth = null,
    ) {}

    public function cell(int $index): ?string
    {
        return $this->cells[$index] ?? null;
    }
}
