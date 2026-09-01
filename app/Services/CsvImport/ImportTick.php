<?php

namespace App\Services\CsvImport;

/**
 * The result of asking CsvImportProcessor to advance one run by one unit of
 * work — one staged file, or one ~50-row chunk.
 *
 * `idle` is what the command loops on: it means this run has nothing further it
 * can do right now, either because it finished or because it is blocked on a
 * human (an unconfirmed product mapping). The command moves to the next run
 * rather than spinning on this one.
 */
final class ImportTick
{
    public function __construct(
        public readonly string $phase,
        public readonly int $rowsProcessed,
        public readonly bool $idle = false,
        public readonly ?string $note = null,
    ) {}
}
