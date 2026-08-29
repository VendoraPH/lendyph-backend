<?php

namespace App\Services\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The single read behind `GET /api/imports/{run}` — the endpoint a browser
 * polls for the whole length of an import.
 *
 * Because it is polled, every number in it is bought with a fixed number of
 * queries no matter how many files, phases or millions of staged rows the run
 * has. In particular the per-file outcome counts come from ONE grouped query
 * over `csv_import_rows`, which is answered from the
 * `(csv_import_file_id, status, result)` index without touching the `raw` and
 * `normalized` JSON blobs that make those rows wide. The obvious alternative —
 * a count query per file per outcome — is ten round trips per poll against the
 * largest table in the schema, and it grows as the import does.
 */
class RunStatusReader
{
    /**
     * How many missing chunk indexes are listed before the list is truncated.
     *
     * Must equal CsvImportUploadService::MISSING_CHUNK_LIST_LIMIT. It only
     * applies on the fallback path below — when the upload service is present
     * it does this itself — but a client must never see two truncation
     * thresholds depending on which endpoint answered.
     */
    private const MISSING_CHUNK_LIST_LIMIT = 500;

    /**
     * @return array<string, mixed>
     */
    public function payload(CsvImportRun $run): array
    {
        $run->loadMissing('files', 'initiatedBy');

        /** @var list<CsvImportFile> $files */
        $files = $run->files->sortBy(fn (CsvImportFile $f): int => $f->kind === 'customers' ? 0 : 1)->values()->all();
        $fileIds = array_map(fn (CsvImportFile $f): int => $f->id, $files);

        $counts = $this->rowCounts($fileIds);
        $upload = $this->uploadPayload($run);

        $filePayloads = [];

        foreach ($files as $file) {
            $filePayloads[$file->kind] = $this->filePayload(
                $file,
                $counts[$file->id] ?? [],
                $upload['files'][$file->kind] ?? null,
            );
        }

        $totals = $this->sumCounts(array_map(fn (array $f): array => $f['counts'], array_values($filePayloads)));
        $mapping = ProductMappingResolver::normalizeStoredMapping($run->product_mapping);

        $loanRowsStaged = (int) ($filePayloads['loans']['counts']['total'] ?? 0);
        /*
         * CsvImportRun::closedPhases(), which defers to
         * CsvImportUploadService::CLOSED_PHASES when that class is present. It
         * used to be a private copy here; `imports:redact-rows` needed the same
         * list, and a second copy of a phase list is the thing that silently
         * stops being true the next time a phase is added.
         */
        $isClosed = in_array($run->phase, CsvImportRun::closedPhases(), true);

        $lastAdvancedAt = $this->lastAdvancedAt($run, $files);
        $now = now();

        return [
            'id' => $run->id,
            'branch_id' => $run->branch_id,
            'as_of_date' => $run->as_of_date?->toDateString(),
            'phase' => $run->phase,
            'initiated_by' => $run->initiatedBy === null ? null : [
                'id' => $run->initiatedBy->id,
                'name' => $run->initiatedBy->full_name ?? $run->initiatedBy->username,
            ],
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'failure_reason' => $run->failure_reason,

            /**
             * When `imports:redact-rows` blanked this run's staged rows, or null
             * while they still hold the file's contents.
             *
             * Published because the error report visibly changes on that date
             * and an operator is owed the reason. Every count and every group
             * still reconciles — redaction keeps `line_number`, `status`,
             * `result`, `result_category` and each note's field and code
             * precisely so they do — but the sentence beside each issue and the
             * Original Value column beside it are gone, because that is where
             * the member's own cell was quoted. A screen that cannot say why
             * looks like a bug in the report rather than a retention policy
             * doing its job.
             */
            'rows_redacted_at' => $run->rows_redacted_at?->toIso8601String(),

            /**
             * Whether the run is over, by any route — completed, failed, or
             * cancelled. Published rather than left for the client to derive
             * from `phase`, because a client that hardcodes the list is the
             * thing that breaks when a phase is added, and one was.
             */
            'is_closed' => $isClosed,

            /**
             * Cheap and approximate ON PURPOSE. Deciding this exactly means
             * pulling every distinct product string out of the staged loan
             * rows' JSON — a full scan of the widest table in the schema, on an
             * endpoint that is polled every few seconds. So a run with no
             * confirmed mapping and loan rows on file is reported as needing
             * one, and GET /api/imports/{run}/product-mapping is the
             * authoritative coverage check.
             */
            'product_mapping_required' => ! $isClosed
                && $mapping === []
                && ($loanRowsStaged > 0 || $run->phase === 'awaiting_mapping'),
            'product_mapping_confirmed' => $mapping !== [],

            /**
             * True as soon as anything has been staged, not once errors exist.
             *
             * The report carries WARNINGS as well as errors, and warnings live
             * inside `normalized.warnings` where they cannot be counted without
             * that same full scan. Gating the download on the countable half is
             * exactly the failure this report exists to prevent: an admin who
             * is never offered the file never learns that twelve phone numbers
             * were blanked or twelve loans exceed the product maximum.
             * `rows_with_errors` below is the number to put on a red badge.
             */
            'error_report_available' => $totals['total'] > 0,
            'rows_with_errors' => $totals['invalid'] + $totals['failed'],

            /**
             * Server clock, both halves of it.
             *
             * The UI shows "last advanced 4m ago", and a browser computing that
             * from its own Date.now() reports whatever its clock is wrong by —
             * on a mis-set machine, an import that just moved looks stalled for
             * hours, or a genuinely stalled one looks fresh. Both the reference
             * instant and the elapsed seconds are therefore computed here.
             */
            'last_advanced_at' => $lastAdvancedAt->toIso8601String(),
            'server_time' => $now->toIso8601String(),
            'seconds_since_last_advance' => max(0, $now->getTimestamp() - $lastAdvancedAt->getTimestamp()),

            /**
             * Keyed by kind, exactly as CsvImportUploadService::runPayload()
             * publishes it, so `files.customers` means the same thing whichever
             * endpoint answered. A positional list would have made the two
             * disagree on nothing more interesting than array order.
             */
            'files' => $filePayloads,
            'chunk_size' => $upload['chunk_size'] ?? null,
            'totals' => $totals,
        ];
    }

    /**
     * Every per-file, per-outcome count in ONE query.
     *
     * @param  list<int>  $fileIds
     * @return array<int, array<string, int>> file id => "{status}|{result}" => count
     */
    private function rowCounts(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $rows = DB::table('csv_import_rows')
            ->selectRaw('csv_import_file_id, `status`, `result`, COUNT(*) as row_count')
            ->whereIn('csv_import_file_id', $fileIds)
            ->groupBy('csv_import_file_id', 'status', 'result')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $fileId = (int) $row->csv_import_file_id;
            $key = $row->status.'|'.($row->result ?? '');
            $counts[$fileId][$key] = ($counts[$fileId][$key] ?? 0) + (int) $row->row_count;
        }

        return $counts;
    }

    /**
     * The upload engineer's own view of this run.
     *
     * CsvImportUploadService owns chunk transfer, so it owns what "missing"
     * means — including the rule this reader had wrong on its own: an assembled
     * file has had its chunk ROWS deleted, so deriving missing indexes from
     * that table reports every chunk of a finished upload as outstanding and
     * sends a client that already succeeded back to re-upload 100 MiB.
     * Calling through is how the two endpoints stay one description of a run
     * rather than two that agree until someone edits one of them.
     *
     * Resolved by name rather than injected, and only when the class exists, so
     * this branch can be developed and tested before the upload work merges.
     * Once it has, the fallback below is dead code and should be deleted.
     *
     * @return array{chunk_size?: int, files?: array<string, array<string, mixed>>}
     */
    private function uploadPayload(CsvImportRun $run): array
    {
        if (! class_exists(CsvImportUploadService::class)) {
            return [];
        }

        return app(CsvImportUploadService::class)->runPayload($run);
    }

    /**
     * One file: the upload service's block, plus what staging and importing
     * made of it.
     *
     * The two additions are namespaced under `counts` and `staging` rather than
     * flattened in, so nothing here can collide with a key the upload service
     * owns — this block is a superset of its contract, never a variant of it.
     *
     * @param  array<string, int>  $counts  "{status}|{result}" => count
     * @param  array<string, mixed>|null  $uploadBlock
     * @return array<string, mixed>
     */
    private function filePayload(CsvImportFile $file, array $counts, ?array $uploadBlock): array
    {
        return ($uploadBlock ?? $this->fallbackUploadBlock($file)) + [
            'staging' => [
                'delimiter' => $file->delimiter,
                'encoding_note' => $file->encoding_note,
                'header_skipped' => (bool) $file->header_skipped,
                'record_count' => $file->record_count === null ? null : (int) $file->record_count,
                'column_count' => $file->column_count === null ? null : (int) $file->column_count,
            ],
            'counts' => $this->shapeCounts($counts),
        ];
    }

    /**
     * The upload block, computed here, for as long as CsvImportUploadService is
     * not on this branch yet.
     *
     * Deliberately the same keys, the same cap and the same assembled-file rule
     * as CsvImportUploadService::runPayload(), so swapping to it changes no
     * response and no test. DELETE THIS once the upload work has merged.
     *
     * @return array<string, mixed>
     */
    private function fallbackUploadBlock(CsvImportFile $file): array
    {
        $assembled = $file->assembled_path !== null;
        $totalChunks = (int) $file->total_chunks;

        $received = $assembled ? [] : DB::table('csv_import_file_chunks')
            ->where('csv_import_file_id', $file->id)
            ->orderBy('chunk_index')
            ->pluck('chunk_index')
            ->map(fn ($index): int => (int) $index)
            ->all();

        $missing = [];
        $missingCount = 0;

        if (! $assembled) {
            /**
             * Walked as gaps between the indexes that arrived rather than by
             * counting 0..total_chunks-1, so the work is bounded by what was
             * actually received plus the list cap — never by a declared chunk
             * count. The upload endpoints now compute `total_chunks` server-side
             * from `size_bytes`, so a hostile value can no longer reach this
             * table; this stays as the cheaper shape regardless.
             */
            $expected = 0;

            foreach ($received as $index) {
                while ($expected < $index) {
                    $missingCount++;

                    if (count($missing) < self::MISSING_CHUNK_LIST_LIMIT) {
                        $missing[] = $expected;
                    }

                    $expected++;
                }

                $expected = max($expected, $index + 1);
            }

            $missingCount += max(0, $totalChunks - $expected);

            while ($expected < $totalChunks && count($missing) < self::MISSING_CHUNK_LIST_LIMIT) {
                $missing[] = $expected++;
            }
        }

        return [
            'id' => $file->id,
            'kind' => $file->kind,
            'original_filename' => $file->original_filename,
            'size_bytes' => (int) $file->size_bytes,
            'sha256' => $file->sha256,
            'chunk_size' => (int) $file->chunk_size,
            'total_chunks' => $totalChunks,
            'assembled' => $assembled,
            'received_chunks' => $assembled ? $totalChunks : count($received),
            'missing_chunks' => $missing,
            'missing_chunk_count' => $missingCount,
            'missing_chunks_truncated' => $missingCount > count($missing),
        ];
    }

    /**
     * The outcome counts, with `matched_existing` standing on its own.
     *
     * That separation is the point of this shape. The coop already has 44
     * self-registered members who appear in the migration file and are reused
     * rather than duplicated; folding them into `skipped` tells the admin their
     * data did not land when it did, and the natural next move — re-uploading
     * the file to "fix" it — is the one action that can do real damage.
     * `already_imported` is kept apart from both for the same reason: it is the
     * only count that means the file was uploaded twice.
     *
     * @param  array<string, int>  $counts  "{status}|{result}" => count
     * @return array<string, int>
     */
    private function shapeCounts(array $counts): array
    {
        $sum = function (?string $status, ?string $result) use ($counts): int {
            $total = 0;

            foreach ($counts as $key => $count) {
                [$rowStatus, $rowResult] = explode('|', $key, 2);

                if ($status !== null && $rowStatus !== $status) {
                    continue;
                }

                if ($result !== null && $rowResult !== $result) {
                    continue;
                }

                $total += $count;
            }

            return $total;
        };

        return [
            'total' => $sum(null, null),
            'valid' => $sum('valid', null),
            'invalid' => $sum('invalid', null),
            // A NULL result is the resume marker: not decided yet.
            'pending' => $sum(null, ''),
            'imported' => $sum(null, 'imported'),
            'matched_existing' => $sum(null, 'matched_existing'),
            'already_imported' => $sum(null, 'already_imported'),
            'skipped' => $sum(null, 'skipped'),
            'failed' => $sum(null, 'failed'),
        ];
    }

    /**
     * @param  list<array<string, int>>  $all
     * @return array<string, int>
     */
    private function sumCounts(array $all): array
    {
        $totals = $this->shapeCounts([]);

        foreach ($all as $counts) {
            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return $totals;
    }

    /**
     * The most recent evidence that this run moved, from the server's clock.
     *
     * Taken from the run and its two file rows rather than from
     * MAX(csv_import_rows.updated_at). `updated_at` on that table is not
     * indexed, so a MAX over it turns the index-only grouped count above into a
     * full scan of every staged row — on a polled endpoint. The run row is the
     * right source anyway: the import advances `phase` and `cursor_row_id` on
     * it as it works, which is precisely the progress signal being reported.
     *
     * @param  list<CsvImportFile>  $files
     */
    private function lastAdvancedAt(CsvImportRun $run, array $files): CarbonInterface
    {
        $candidates = array_filter([
            $run->updated_at,
            $run->created_at,
            $run->started_at,
            $run->finished_at,
        ]);

        foreach ($files as $file) {
            $candidates[] = $file->updated_at;
            $candidates[] = $file->created_at;
        }

        $latest = null;

        foreach (array_filter($candidates) as $candidate) {
            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return $latest ?? now();
    }
}
