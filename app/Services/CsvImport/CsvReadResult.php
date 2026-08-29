<?php

namespace App\Services\CsvImport;

use Generator;

/**
 * The outcome of opening a migration CSV: everything decided about the file up
 * front, plus a lazy stream of its data rows.
 *
 * `notes()` is only complete once rows() has been fully consumed — per-cell
 * encoding repair is discovered while streaming, by design, so that a 50 MB
 * file is never held in memory to answer a question about its first row.
 */
final class CsvReadResult
{
    /**
     * @param  list<string>|null  $header
     * @param  \Closure(): Generator<int, CsvRow>  $rowFactory
     * @param  \ArrayObject<int, string>  $notes
     */
    public function __construct(
        public readonly string $shape,
        public readonly string $delimiter,
        public readonly int $columnCount,
        public readonly bool $hasHeader,
        public readonly ?array $header,
        private readonly \Closure $rowFactory,
        private readonly \ArrayObject $notes,
    ) {}

    private bool $consumed = false;

    /**
     * The file's data rows, lazily.
     *
     * Callable ONCE. This is a stream over an open file handle, not a
     * collection: a second call would find the handle already read to the end
     * and yield nothing at all, which as a silent empty import is far worse
     * than an exception. If you need the rows twice, keep them.
     *
     * @return Generator<int, CsvRow>
     */
    public function rows(): Generator
    {
        if ($this->consumed) {
            throw new \LogicException(
                'CsvReadResult::rows() streams the file and can only be read once. '
                .'Iterating it a second time would silently yield nothing.'
            );
        }

        $this->consumed = true;

        yield from ($this->rowFactory)();
    }

    /**
     * Run-level observations that belong to the file, not to any one row —
     * "cells were re-encoded from Windows-1252", for instance.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return array_values($this->notes->getArrayCopy());
    }
}
