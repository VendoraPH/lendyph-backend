<?php

namespace App\Services\CsvImport;

use ArrayObject;
use Generator;
use Illuminate\Support\Facades\Storage;

/**
 * Streaming reader for the two migration CSVs.
 *
 * Deliberately dependency-free: choosing not to add a CSV package means every
 * hazard a package would have absorbed is handled here explicitly, and each one
 * below is a real way a coop's export has silently corrupted an import.
 *
 * The file-level gauntlet runs eagerly in read(), before a single row is
 * yielded, because all four of these conditions parse "successfully" and
 * produce wrong data rather than an error:
 *
 *  - UTF-16 (Excel's "Unicode Text" save) — every cell arrives NUL-padded.
 *  - CR-only line endings (classic Mac / some Excel exports) — PHP dropped
 *    auto_detect_line_endings in 8.1, so the whole file reads as ONE record.
 *  - The wrong delimiter — a semicolon export parses as one giant column.
 *  - The wrong record width — the file is a different report, or a column was
 *    inserted, and every value lands one position off.
 *
 * The two that are repaired rather than rejected:
 *
 *  - A UTF-8 BOM on the first cell. Left in place, "\u{FEFF}A-001" is a
 *    DIFFERENT account number from "A-001", so the member row imports under a
 *    key none of their loans can find and every loan they hold orphans.
 *  - Windows-1252 bytes (Excel-on-Windows' default single-byte save). Invalid
 *    UTF-8 going into a utf8mb4 column is an insert error, not a data problem,
 *    so it is converted per cell and noted once for the run.
 */
class CsvImportReader
{
    /**
     * Bytes sampled to decide encoding, line endings and delimiter.
     */
    private const SNIFF_BYTES = 4096;

    /**
     * Records buffered to establish the modal width. Buffered rather than
     * re-read so the reader stays single-pass and works on a stream.
     */
    private const WIDTH_SAMPLE_RECORDS = 100;

    /**
     * At least this many of the first record's leading cells must look like
     * column labels before the record is treated as a header. Two of three
     * tolerates one renamed column while making a false positive on real data
     * (an account number, a surname, a given name) essentially impossible.
     */
    private const HEADER_TOKEN_MATCHES_REQUIRED = 2;

    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * The only disk a migration CSV may be read from. See self::readFromDisk().
     */
    public const PII_DISK = 'private';

    private const DELIMITER_CANDIDATES = [',', ';', "\t"];

    /**
     * Open a migration CSV and settle every file-level question about it.
     *
     * @throws CsvFileRejectedException when the file cannot be imported at all
     */
    public function read(string $path, string $shape): CsvReadResult
    {
        $expectedWidth = CsvImportSchema::width($shape);

        if (! is_file($path) || ! is_readable($path)) {
            throw new CsvFileRejectedException('unreadable', "The file [{$path}] could not be opened for reading.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new CsvFileRejectedException('unreadable', "The file [{$path}] could not be opened for reading.");
        }

        try {
            $sample = (string) fread($handle, self::SNIFF_BYTES);

            $this->rejectUnsupportedEncoding($sample);
            $this->rejectCarriageReturnOnlyLineEndings($sample);

            $delimiter = $this->sniffDelimiter($sample);

            rewind($handle);

            $notes = new ArrayObject;
            $buffered = $this->bufferSampleRecords($handle, $delimiter, $notes);

            if ($buffered === []) {
                throw new CsvFileRejectedException('empty', 'The file contains no rows.');
            }

            $this->rejectUnexpectedWidth($buffered, $expectedWidth, $shape, $delimiter);

            $hasHeader = $this->looksLikeHeader($buffered[0], $shape);
            $header = null;

            if ($hasHeader) {
                $header = $buffered[0];
                $this->rejectMismatchedHeader($header, $shape);
                array_shift($buffered);
            }

            $rowFactory = function () use ($handle, $delimiter, $buffered, $hasHeader, $notes): Generator {
                yield from $this->streamRows($handle, $delimiter, $buffered, $hasHeader, $notes);
            };

            return new CsvReadResult(
                shape: $shape,
                delimiter: $delimiter,
                columnCount: $expectedWidth,
                hasHeader: $hasHeader,
                header: $header,
                rowFactory: $rowFactory,
                notes: $notes,
            );
        } catch (\Throwable $e) {
            fclose($handle);

            throw $e;
        }
    }

    /**
     * Open a migration CSV that lives on a filesystem disk, refusing any disk
     * but `private`.
     *
     * These files are full borrower PII — names, birthdates, addresses,
     * contact numbers, incomes — for an entire cooperative in one download.
     * The `public` disk is served straight off the filesystem by nginx with no
     * authentication whatsoever, so a migration CSV landing there is a
     * membership roll published to the internet.
     *
     * Nothing in the storage layer enforces which disk a path string refers to,
     * so the enforcement has to happen at each point of use. This is mine.
     *
     * @throws CsvFileRejectedException when the disk is not `private`, or the
     *                                  path escapes the disk root
     */
    public function readFromDisk(string $disk, string $path, string $shape): CsvReadResult
    {
        if ($disk !== self::PII_DISK) {
            throw new CsvFileRejectedException(
                'insecure_disk',
                "Migration CSVs may only be read from the [".self::PII_DISK."] disk, not [{$disk}]. "
                .'These files carry every member\'s name, birthdate, address and income, and the other disks '
                .'are either web-reachable or unversioned.',
            );
        }

        $root = realpath(Storage::disk($disk)->path(''));
        $resolved = realpath(Storage::disk($disk)->path($path));

        // A path that climbs out of the disk root would read an arbitrary file
        // off the server and hand its contents to the import report.
        if ($root === false || $resolved === false || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new CsvFileRejectedException(
                'path_outside_disk',
                "The file [{$path}] could not be resolved inside the [{$disk}] disk.",
            );
        }

        return $this->read($resolved, $shape);
    }

    /**
     * UTF-16 is rejected outright rather than transcoded.
     *
     * A per-cell error would be useless here — EVERY cell is wrong — and
     * transcoding invites guessing at endianness on a file we have one chance
     * to read correctly. The fix is one menu item in Excel, so the message says
     * exactly which one.
     */
    private function rejectUnsupportedEncoding(string $sample): void
    {
        $advice = 'Re-save it from Excel using File > Save As > "CSV UTF-8 (Comma delimited)" and upload again.';

        if (str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF")) {
            throw new CsvFileRejectedException(
                'utf16_encoding',
                'This file is saved as UTF-16 ("Unicode Text"), which cannot be read as CSV. '.$advice,
            );
        }

        // UTF-16 without a byte-order mark: a NUL byte cannot occur in a valid
        // text CSV, so its presence is decisive.
        if (str_contains($sample, "\0")) {
            throw new CsvFileRejectedException(
                'utf16_encoding',
                'This file contains NUL bytes, which means it is saved as UTF-16 or is not a text CSV at all. '.$advice,
            );
        }
    }

    /**
     * Classic Mac (CR-only) line endings.
     *
     * PHP removed auto_detect_line_endings in 8.1, so fgetcsv sees no line
     * terminator at all and returns the ENTIRE file as a single record. The
     * symptom is indistinguishable from a one-column file, so the failure has
     * to be named explicitly or nobody will work out what happened.
     */
    private function rejectCarriageReturnOnlyLineEndings(string $sample): void
    {
        if (! str_contains($sample, "\r")) {
            return;
        }

        $carriageReturnsNotFollowedByNewline = preg_match_all("/\r(?!\n)/", $sample);

        if ($carriageReturnsNotFollowedByNewline > 0 && ! str_contains($sample, "\n")) {
            throw new CsvFileRejectedException(
                'cr_only_line_endings',
                'This file uses classic Mac (CR-only) line endings, so PHP reads the whole file as a single row. '
                .'Re-save it from Excel using File > Save As > "CSV UTF-8 (Comma delimited)" and upload again.',
            );
        }
    }

    /**
     * Pick the delimiter by frequency over the sample.
     *
     * Counting raw bytes does include separators that sit inside quoted cells,
     * which is imprecise — but the winner only has to beat the other two, and
     * the width check downstream rejects the file outright if the choice was
     * wrong, so an imprecise count cannot turn into a bad import.
     */
    private function sniffDelimiter(string $sample): string
    {
        $lastNewline = strrpos($sample, "\n");

        if ($lastNewline !== false) {
            $sample = substr($sample, 0, $lastNewline);
        }

        $best = ',';
        $bestCount = substr_count($sample, ',');

        foreach (self::DELIMITER_CANDIDATES as $candidate) {
            $count = substr_count($sample, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param  resource  $handle
     * @param  ArrayObject<int, string>  $notes
     * @return list<list<string>>
     */
    private function bufferSampleRecords($handle, string $delimiter, ArrayObject $notes): array
    {
        $records = [];
        $isFirstRecord = true;

        while (count($records) < self::WIDTH_SAMPLE_RECORDS) {
            $record = $this->readRecord($handle, $delimiter, $isFirstRecord, $notes);

            if ($record === null) {
                break;
            }

            $isFirstRecord = false;

            if ($this->isBlankRecord($record)) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * The modal width across the sample decides whether the file is the shape
     * it claims to be, and a mismatch rejects the FILE.
     *
     * Rejecting per row would be far worse than useless: a file that is
     * uniformly one column off produces a complete, plausible import in which
     * every birthdate is a gender and every pledge amount is a spouse's first
     * name. Nothing downstream can detect that, and no operator reviewing a
     * success report would either.
     *
     * @param  list<list<string>>  $records
     */
    private function rejectUnexpectedWidth(array $records, int $expectedWidth, string $shape, string $delimiter): void
    {
        $widths = [];

        foreach ($records as $record) {
            $width = count($record);
            $widths[$width] = ($widths[$width] ?? 0) + 1;
        }

        // Sort by frequency, then by width, so the modal width is deterministic
        // when two widths are equally common rather than depending on the order
        // rows happened to arrive in.
        ksort($widths);
        arsort($widths);

        $modalWidth = (int) array_key_first($widths);
        $modalCount = $widths[$modalWidth];
        $sampled = count($records);

        $delimiterName = match ($delimiter) {
            ';' => 'semicolon', "\t" => 'tab', default => 'comma',
        };

        if ($modalWidth !== $expectedWidth) {
            throw new CsvFileRejectedException(
                'unexpected_column_count',
                "This file has {$modalWidth} columns but the {$shape} import expects {$expectedWidth} "
                ."(read using a {$delimiterName} separator). No rows were imported, because a file whose columns "
                .'are shifted would import every value into the wrong field. Check that you uploaded the '
                ."{$shape} sheet and that no columns were added or removed.",
            );
        }

        // The modal width being right is not enough on its own. If it is not
        // held by a clear majority of the sample, the file does not have a
        // consistent shape at all, and there is no basis for deciding which
        // rows are the correct ones — an unquoted comma inside a money cell
        // produces exactly this, and reading such a file positionally would
        // shift values into neighbouring fields.
        if ($modalCount * 2 <= $sampled) {
            throw new CsvFileRejectedException(
                'inconsistent_column_count',
                "The rows in this file do not have a consistent number of columns — only {$modalCount} of the first "
                ."{$sampled} rows have the expected {$expectedWidth} (read using a {$delimiterName} separator). "
                .'This usually means a value contains an unquoted separator. No rows were imported, because there '
                .'is no way to tell which values belong to which columns.',
            );
        }
    }

    /**
     * @param  list<string>  $record
     */
    private function looksLikeHeader(array $record, string $shape): bool
    {
        $tokens = CsvImportSchema::headerTokens($shape);
        $matches = 0;

        foreach (array_slice($record, 0, 3) as $cell) {
            if (in_array(CsvImportSchema::labelKey($cell), $tokens, true)) {
                $matches++;
            }
        }

        return $matches >= self::HEADER_TOKEN_MATCHES_REQUIRED;
    }

    /**
     * A present header is verified position by position against the declared
     * sequence.
     *
     * Detection alone is not enough. A file can have the right NUMBER of
     * columns in the wrong ORDER — two columns swapped in the source workbook
     * passes the width check and imports cleanly into the wrong fields. When
     * the file tells us its column names, we hold it to them.
     *
     * @param  list<string>  $header
     */
    private function rejectMismatchedHeader(array $header, string $shape): void
    {
        $labels = CsvImportSchema::labels($shape);

        foreach ($labels as $index => $expectedLabel) {
            $found = $header[$index] ?? '';
            $accepted = CsvImportSchema::acceptedLabelsAt($shape, $index);

            if (in_array(CsvImportSchema::labelKey($found), $accepted, true)) {
                continue;
            }

            $position = $index + 1;

            throw new CsvFileRejectedException(
                'header_mismatch',
                "Column {$position} of this file is headed \"{$found}\" but the {$shape} import expects "
                ."\"{$expectedLabel}\". No rows were imported, because importing columns in a different order "
                .'would write every value into the wrong field.',
            );
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<list<string>>  $buffered
     * @param  ArrayObject<int, string>  $notes
     * @return Generator<int, CsvRow>
     */
    private function streamRows($handle, string $delimiter, array $buffered, bool $hasHeader, ArrayObject $notes): Generator
    {
        try {
            $recordNumber = $hasHeader ? 1 : 0;
            $rowNumber = 0;

            foreach ($buffered as $record) {
                $recordNumber++;
                $rowNumber++;

                yield new CsvRow($recordNumber, $rowNumber, $record);
            }

            while (($record = $this->readRecord($handle, $delimiter, false, $notes)) !== null) {
                if ($this->isBlankRecord($record)) {
                    continue;
                }

                $recordNumber++;
                $rowNumber++;

                yield new CsvRow($recordNumber, $rowNumber, $record);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param  resource  $handle
     * @param  ArrayObject<int, string>  $notes
     * @return list<string>|null
     */
    private function readRecord($handle, string $delimiter, bool $isFirstRecord, ArrayObject $notes): ?array
    {
        // `escape` is passed explicitly: PHP 8.4 deprecates the historical
        // default of "\\", which is not RFC 4180 and silently swallows the
        // backslash in an address like "123 Main St \ Unit 4".
        $record = fgetcsv($handle, 0, $delimiter, '"', '');

        if ($record === false || $record === null) {
            return null;
        }

        $cells = [];

        foreach ($record as $position => $cell) {
            $cell = $cell ?? '';

            if ($isFirstRecord && $position === 0) {
                $cell = $this->stripUtf8Bom($cell);
            }

            $cells[] = $this->repairEncoding($cell, $notes);
        }

        return $cells;
    }

    private function stripUtf8Bom(string $value): string
    {
        return str_starts_with($value, self::UTF8_BOM)
            ? substr($value, strlen(self::UTF8_BOM))
            : $value;
    }

    /**
     * Bring a cell into valid UTF-8, noting once per run that it happened.
     *
     * Windows-1252 is what Excel on Windows writes unless the operator picks
     * the UTF-8 variant explicitly, and it differs from UTF-8 exactly where
     * Philippine data lives: the peso sign, curly quotes pasted from Word, and
     * accented names (Peña, Muñoz). Left alone these are invalid UTF-8 and
     * MySQL rejects the INSERT, so the row fails for a reason that has nothing
     * to do with the borrower.
     *
     * @param  ArrayObject<int, string>  $notes
     */
    private function repairEncoding(string $value, ArrayObject $notes): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $note = 'Some cells were not valid UTF-8 and were read as Windows-1252 (the Excel-on-Windows default). '
            .'Check any name or address containing an accented character.';

        if (! in_array($note, $notes->getArrayCopy(), true)) {
            $notes[] = $note;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param  list<string>  $record
     */
    private function isBlankRecord(array $record): bool
    {
        foreach ($record as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
