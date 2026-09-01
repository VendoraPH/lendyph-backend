<?php

namespace App\Services\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a run's staged rows into the report an admin actually fixes their file
 * from.
 *
 * One staged row can be several lines here — a row with a bad date and a
 * truncated purpose has one error and one warning, and both belong in front of
 * the person who has to correct the spreadsheet. So the unit of this report is
 * the ISSUE, not the row, and the paginated endpoint pages over rows while
 * listing the issues those rows carry. Both numbers are reported rather than
 * one being quietly passed off as the other.
 *
 * WARNINGS ARE NOT OPTIONAL. A report of errors alone tells an admin which rows
 * failed and nothing about the rows that succeeded with their data changed —
 * twelve phone numbers dropped as unusable, twelve loans written outside the
 * product's maximum. Those are the rows nobody goes back to check, which is
 * exactly why they have to be in the file.
 */
class ErrorReportBuilder
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    /**
     * A loan written outside the bounds its mapped LoanProduct configures.
     *
     * A PUBLISHED CONTRACT, and the reader half of it. The importer bypasses
     * LoanService::createLoan(), so its `min_amount`/`max_amount`/`min_term`/
     * `max_term`/rate-range guards never fire — deliberately, and that stays: a
     * migration has to be able to carry a decade of historical loans that
     * today's product rules would reject, and the cooperative this exists for
     * has exactly one product, ₱1,000–75,000 over 30 monthly periods. Enforcing
     * would make the migration impossible rather than safe.
     *
     * What was missing is the trace. The breach was counted at mapping time by
     * ProductMappingResolver::compatibility() — a forecast, made before the
     * import runs and against the mapping as it stood then — and then nothing
     * recorded which rows actually landed out of bounds. The importer writes
     * this string into `csv_import_rows.result_category` on the row it just
     * imported; this class turns it into a WARNING line, so the run's own error
     * report carries the reviewable list instead of the number being a forecast
     * nobody can expand.
     *
     * A warning and never an error: the loan is on the books and correct as the
     * cooperative recorded it. It is the product configuration it disagrees
     * with, and that is an operator's judgement, not a failure.
     *
     * Survives redaction. `result_category` is one of the four columns
     * `imports:redact-rows` keeps, so this list still reconciles after the
     * personal data is gone — only the interpolated message reverts to the
     * generic sentence below.
     */
    public const CATEGORY_OUT_OF_PRODUCT_BOUNDS = 'out_of_product_bounds';

    /**
     * Results that mean the row was actually written.
     *
     * `failed` and `skipped` are excluded because both already emit a line of
     * their own from `result_category`, and a row must not be reported twice
     * for one category.
     *
     * @var list<string>
     */
    private const WRITTEN_RESULTS = ['imported', 'matched_existing', 'already_imported'];

    /** Rows read per chunk when summarising or streaming. */
    private const CHUNK = 500;

    /** Most distinct reason groups reported; codes are a bounded vocabulary. */
    private const MAX_CATEGORIES = 100;

    /**
     * Most distinct MESSAGES held per reason group while summarising.
     *
     * MAX_CATEGORIES bounds the output; this bounds the working set, and they
     * are not the same problem. Messages interpolate the offending cell —
     * ValueNormalizer writes `"09171234567 / 0928..." is not a usable contact
     * number` — so the number of distinct messages inside ONE category is
     * bounded by the number of distinct bad cells in the file, not by the code
     * vocabulary. A migration file with 400,000 malformed phone numbers would
     * otherwise hold 400,000 distinct strings in one array on one request, and
     * exhausting memory_limit is a fatal error, not an exception anything can
     * catch. Counting stays exact; only the sample of distinct wordings is
     * capped.
     */
    private const MAX_MESSAGES_PER_CATEGORY = 50;

    /**
     * The CSV column headings, in order.
     *
     * `Row Number` is the PHYSICAL line in the submitted file, header included —
     * `csv_import_rows.line_number`, never the `record_number` ordinal and never
     * the `row` field inside the staged payload, which counts data records only.
     * The physical line is the number the admin's spreadsheet shows in its
     * gutter, and telling them to fix row 4,812 when the problem is on 4,813 is
     * how an error report wastes an afternoon.
     *
     * @var list<string>
     */
    public const CSV_HEADERS = [
        'File',
        'Row Number',
        'Account No.',
        'Loan No.',
        'Severity',
        'Category',
        'Field',
        'Message',
        'Original Value',
    ];

    /**
     * Rows worth reporting on, narrowed by the caller's filters.
     *
     * @param  array{severity?: string|null, kind?: string|null}  $filters
     * @return Builder<CsvImportRow>
     */
    public function query(CsvImportRun $run, array $filters = []): Builder
    {
        $fileIds = $run->files
            ->when(filled($filters['kind'] ?? null), fn ($files) => $files->where('kind', $filters['kind']))
            ->pluck('id')
            ->all();

        $severity = $filters['severity'] ?? null;

        return CsvImportRow::query()
            ->whereIn('csv_import_file_id', $fileIds === [] ? [0] : $fileIds)
            ->where(function (Builder $q) use ($severity): void {
                if ($severity !== self::SEVERITY_WARNING) {
                    $q->orWhere('status', 'invalid')->orWhere('result', 'failed');
                }

                if ($severity !== self::SEVERITY_ERROR) {
                    /**
                     * Warnings live inside the staged payload rather than in a
                     * column, so this leg cannot use the
                     * `(csv_import_file_id, status, result)` index and scans.
                     * Acceptable here and nowhere else: the error report is a
                     * deliberate operator action, not the status poll.
                     *
                     * JSON_LENGTH returns NULL for a missing path, and NULL > 0
                     * is not true, so a row with no `warnings` key is simply not
                     * matched.
                     */
                    $q->orWhereRaw('JSON_LENGTH(`normalized`, ?) > 0', ['$.warnings'])
                        ->orWhere('result', 'skipped')
                        /*
                         * A column read rather than a JSON one, so this leg
                         * costs nothing beyond the scan the warnings leg above
                         * already forces — and unlike that one it still matches
                         * after `imports:redact-rows` has blanked `normalized`,
                         * because `result_category` is a column redaction keeps.
                         */
                        ->orWhere('result_category', self::CATEGORY_OUT_OF_PRODUCT_BOUNDS);
                }
            })
            ->orderBy('csv_import_file_id')
            ->orderBy('line_number')
            ->orderBy('id');
    }

    /**
     * One page of reported rows, already flattened into issue lines.
     *
     * @param  array{severity?: string|null, kind?: string|null}  $filters
     * @return array{paginator: LengthAwarePaginator, issues: list<array<string, mixed>>}
     */
    public function paginate(CsvImportRun $run, array $filters, int $perPage): array
    {
        $paginator = $this->query($run, $filters)
            ->select($this->reportColumns())
            ->paginate($perPage);

        $files = $this->filesById($run);
        $issues = [];

        foreach ($paginator->items() as $row) {
            foreach ($this->issuesFor($row, $files[$row->csv_import_file_id] ?? null, $filters['severity'] ?? null) as $issue) {
                $issues[] = $issue;
            }
        }

        return ['paginator' => $paginator, 'issues' => $issues];
    }

    /**
     * The grouped view — what an admin acts on.
     *
     * A single line reading "312 rows — Loan Product 'Regular' could not be
     * resolved" is one fix; 312 individual rows are 312 reads of the same
     * sentence. Grouping is by CATEGORY rather than by message because most
     * messages interpolate the offending cell and would each be unique;
     * `label` is then the most common message inside the group, which reads as
     * the sentence above whenever a category has one cause, and
     * `distinct_messages` says when it does not.
     *
     * @param  array{severity?: string|null, kind?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function stats(CsvImportRun $run, array $filters = []): array
    {
        $files = $this->filesById($run);
        $severity = $filters['severity'] ?? null;

        $bySeverity = [self::SEVERITY_ERROR => 0, self::SEVERITY_WARNING => 0];
        $groups = [];
        $rowsReported = 0;
        $totalIssues = 0;

        $this->query($run, $filters)
            /**
             * Without `raw`. The summary counts severities and categories and
             * never renders an Original Value, and `raw` is the widest column on
             * the widest table — leaving it out of a scan that touches every
             * reported row in the run is most of this query's cost.
             */
            ->select($this->reportColumns(withRaw: false))
            ->reorder('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$bySeverity, &$groups, &$rowsReported, &$totalIssues, $files, $severity): void {
                foreach ($chunk as $row) {
                    $issues = $this->issuesFor($row, $files[$row->csv_import_file_id] ?? null, $severity);

                    if ($issues === []) {
                        continue;
                    }

                    $rowsReported++;

                    foreach ($issues as $issue) {
                        $totalIssues++;
                        $bySeverity[$issue['severity']]++;

                        $key = $issue['severity'].'|'.$issue['category'];
                        $groups[$key] ??= [
                            'severity' => $issue['severity'],
                            'category' => $issue['category'],
                            'count' => 0,
                            'messages' => [],
                            'messages_dropped' => 0,
                        ];
                        $groups[$key]['count']++;

                        if (isset($groups[$key]['messages'][$issue['message']])) {
                            $groups[$key]['messages'][$issue['message']]++;
                        } elseif (count($groups[$key]['messages']) < self::MAX_MESSAGES_PER_CATEGORY) {
                            $groups[$key]['messages'][$issue['message']] = 1;
                        } else {
                            $groups[$key]['messages_dropped']++;
                        }
                    }
                }
            });

        uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['category'], $b['category']));

        $byCategory = [];

        foreach (array_slice($groups, 0, self::MAX_CATEGORIES) as $group) {
            arsort($group['messages']);

            $byCategory[] = [
                'severity' => $group['severity'],
                'category' => $group['category'],
                'count' => $group['count'],
                // The most common wording SEEN. Sampling preserves it: a
                // dominant message is dominant long before the cap is reached.
                'label' => (string) array_key_first($group['messages']),
                'distinct_messages' => count($group['messages']),
                'distinct_messages_truncated' => $group['messages_dropped'] > 0,
            ];
        }

        return [
            'total_issues' => $totalIssues,
            'rows_reported' => $rowsReported,
            'by_severity' => $bySeverity,
            'by_category' => $byCategory,
            'categories_truncated' => count($groups) > count($byCategory),
        ];
    }

    /**
     * Every issue line in the run, lazily, for the streamed download.
     *
     * A generator rather than a collection, and chunked underneath, so the CSV
     * is written to the socket as it is read out of the database and the report
     * never has to fit in memory — nor on disk. Nothing here creates a file.
     *
     * @param  array{severity?: string|null, kind?: string|null}  $filters
     * @return Generator<int, list<string>>
     */
    public function csvRows(CsvImportRun $run, array $filters = []): Generator
    {
        $files = $this->filesById($run);
        $severity = $filters['severity'] ?? null;

        /**
         * `reorder('id')` before lazyById, on purpose. Keyset iteration is only
         * correct when the sort IS the key: leaving the file/line ordering in
         * front of it would mean paging with `where id > ?` through a result set
         * sorted by something else, which drops and repeats rows. Id order is
         * staging order — file by file, line by line — so the file reads the
         * same way regardless. Keyset rather than offset also matters because
         * the processor may be updating these very rows while the download
         * streams; an offset walk would shift under it.
         */
        $rows = $this->query($run, $filters)
            ->select($this->reportColumns())
            ->reorder('id')
            ->lazyById(self::CHUNK);

        $lastRowId = null;

        try {
            yield from $this->emit($rows, $files, $severity, $lastRowId);
        } catch (Throwable $e) {
            /**
             * The response has already begun by the time this generator runs,
             * so an escaping exception is not a 500 — it is a stack trace
             * appended to a CSV of members' names, birthdates and phone
             * numbers, which the operator then opens in Excel. With APP_DEBUG
             * on it is a very detailed one. The download ends with an honest
             * final row instead, and the exception is described where it
             * belongs.
             *
             * NOT `report($e)`, which was the same leak wearing a different
             * name. report() hands the exception to the framework's default
             * handler, which writes `$e->getMessage()` to the DEFAULT channel —
             * `single`: one file, never rotated, no scrubbing, mode 644. A
             * QueryException's message is the failing SQL with the bindings
             * substituted in, and of every site on this path THIS is the one
             * holding a member when it throws: the generator above is streaming
             * staged rows, so the query that failed is a query about a person,
             * not about a run. Every other catch on this path is handling
             * run-level metadata — ids, counts, phases.
             *
             * So the same treatment as those: the digest on the shared line,
             * the full text only to the opt-in `csv-import` channel. See
             * ImportErrorDigest, which is where the whole argument lives.
             */
            Log::warning('csv-import: an error report download stopped early', [
                'csv_import_run_id' => $run->id,
                /*
                 * Safe to name, and worth naming. `severity` and `kind` are a
                 * closed vocabulary validated at the request boundary, and
                 * `last_row_id` is a surrogate key — the autoincrement, not
                 * anything out of the file. It is the difference between an
                 * operator being told to download it again forever and an
                 * engineer being able to look at the row that poisoned the
                 * export.
                 *
                 * It is the row the export had REACHED, which is deliberately
                 * not the same as the last row it finished. emit() stamps it on
                 * entry to each row, so a throw raised while turning that row
                 * into issue lines names THAT row — the useful answer — and a
                 * throw raised while lazyById() fetches the next chunk names the
                 * last row completed, which is the only honest answer available
                 * there. Stated because the difference is one row and somebody
                 * will chase it.
                 */
                'severity' => $severity,
                'kind' => $filters['kind'] ?? null,
                'last_row_id' => $lastRowId,
            ] + ImportErrorDigest::context($e));

            ImportErrorDigest::recordDiagnostics($e, [
                'csv_import_run_id' => $run->id,
                'stage' => 'error-report-download',
                'last_row_id' => $lastRowId,
            ]);

            yield ['', '', '', '', self::SEVERITY_ERROR, 'export_failed', '', 'This report stopped early and is incomplete. Please download it again.', ''];
        }
    }

    /**
     * @param  iterable<int, CsvImportRow>  $rows
     * @param  array<int, CsvImportFile>  $files
     * @param  int|null  $lastRowId  By reference, so the caller's catch can say
     *                               how far the download actually got. Stamped
     *                               on ENTRY to each row, so a throw from
     *                               issuesFor() names the offending row rather
     *                               than the one before it. A surrogate id and
     *                               nothing else — see the log line in
     *                               csvRows().
     * @return Generator<int, list<string>>
     */
    private function emit(iterable $rows, array $files, ?string $severity, ?int &$lastRowId = null): Generator
    {
        foreach ($rows as $row) {
            $lastRowId = (int) $row->id;

            foreach ($this->issuesFor($row, $files[$row->csv_import_file_id] ?? null, $severity) as $issue) {
                yield [
                    $issue['file'],
                    (string) $issue['row_number'],
                    $issue['account_no'],
                    $issue['loan_no'],
                    $issue['severity'],
                    $issue['category'],
                    $issue['field_label'],
                    /**
                     * Message before Original Value, and BOTH reach
                     * CsvExportTrait::streamCsv() as plain strings so it can
                     * neutralise a leading `=`, `+`, `-`, `@`, tab or CR on
                     * either. Original Value echoes the coop's own cell back
                     * into a file they open in Excel; Message interpolates that
                     * same cell, so sanitising one column and not the other
                     * reintroduces through the second exactly what the first
                     * closed.
                     */
                    $issue['message'],
                    $issue['original_value'],
                ];
            }
        }
    }

    /**
     * Every issue one staged row carries.
     *
     * @return list<array<string, mixed>>
     */
    public function issuesFor(CsvImportRow $row, ?CsvImportFile $file, ?string $severity = null): array
    {
        $shape = $file?->kind === CsvImportSchema::LOANS ? CsvImportSchema::LOANS : CsvImportSchema::CUSTOMERS;
        $normalized = is_array($row->normalized) ? $row->normalized : [];
        $raw = is_array($row->raw) ? $row->raw : [];

        $base = [
            'csv_import_row_id' => $row->id,
            'csv_import_file_id' => $row->csv_import_file_id,
            'file' => (string) ($file?->original_filename ?? ''),
            'file_kind' => (string) ($file?->kind ?? ''),
            'row_number' => (int) $row->line_number,
            'record_number' => (int) $row->record_number,
            'account_no' => $this->cellFor($shape, 'account_no', $normalized, $raw),
            'loan_no' => $shape === CsvImportSchema::LOANS ? $this->cellFor($shape, 'loan_no', $normalized, $raw) : '',
        ];

        $issues = [];

        if ($severity !== self::SEVERITY_WARNING) {
            foreach ($this->notes($row->errors) as $note) {
                $issues[] = $this->issue($base, self::SEVERITY_ERROR, $note, $shape, $normalized, $raw);
            }

            /**
             * A row staging rejected but which carries no note is still a
             * rejected row, and must not vanish from the report because the
             * notes did not survive. One synthetic line stands in for it.
             */
            if ($issues === [] && $row->status === 'invalid') {
                $issues[] = $this->issue($base, self::SEVERITY_ERROR, [
                    'field' => '__row',
                    'code' => (string) ($row->result_category ?: 'invalid_row'),
                    'message' => (string) ($row->result_message ?: 'This row was rejected during staging.'),
                ], $shape, $normalized, $raw);
            }

            if ($row->result === 'failed') {
                $issues[] = $this->issue($base, self::SEVERITY_ERROR, [
                    'field' => '__row',
                    'code' => (string) ($row->result_category ?: 'import_failed'),
                    'message' => (string) ($row->result_message ?: 'This row could not be imported.'),
                ], $shape, $normalized, $raw);
            }
        }

        if ($severity !== self::SEVERITY_ERROR) {
            foreach ($this->notes($normalized['warnings'] ?? null) as $note) {
                $issues[] = $this->issue($base, self::SEVERITY_WARNING, $note, $shape, $normalized, $raw);
            }

            /**
             * `skipped` is not a failure, but an admin who sees 56 rows skipped
             * and no reason will assume data was lost. `matched_existing` and
             * `already_imported` are deliberately NOT here: those rows landed,
             * they are reported as counts on the status endpoint, and listing
             * them would bury the lines that need action.
             */
            if ($row->result === 'skipped') {
                $issues[] = $this->issue($base, self::SEVERITY_WARNING, [
                    'field' => '__row',
                    'code' => (string) ($row->result_category ?: 'skipped'),
                    'message' => (string) ($row->result_message ?: 'This row was skipped.'),
                ], $shape, $normalized, $raw);
            }

            /**
             * The loan landed, and it disagrees with its product.
             *
             * Gated on the row having been WRITTEN. A `failed` or `skipped` row
             * carrying this category has already produced its own line above
             * from the same column, and emitting a second one would double the
             * group's count against a single row.
             */
            if ($row->result_category === self::CATEGORY_OUT_OF_PRODUCT_BOUNDS
                && in_array((string) $row->result, self::WRITTEN_RESULTS, true)) {
                $issues[] = $this->issue($base, self::SEVERITY_WARNING, [
                    'field' => '__row',
                    'code' => self::CATEGORY_OUT_OF_PRODUCT_BOUNDS,
                    'message' => (string) ($row->result_message
                        ?: 'This loan was imported outside the limits configured on its loan product.'),
                ], $shape, $normalized, $raw);
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array{field: string, code: string, message: string}  $note
     * @param  array<string, mixed>  $normalized
     * @param  array<int|string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function issue(array $base, string $severity, array $note, string $shape, array $normalized, array $raw): array
    {
        $field = (string) $note['field'];
        $isColumn = $field !== '' && in_array($field, CsvImportSchema::keys($shape), true);

        return $base + [
            'severity' => $severity,
            'category' => (string) $note['code'],
            'field' => $field,
            'field_label' => $isColumn ? CsvImportSchema::labels($shape)[CsvImportSchema::indexOf($shape, $field)] : '',
            'message' => (string) $note['message'],
            /**
             * The cell as it arrived, straight out of `raw` — before trimming,
             * before coercion. An admin correcting the file is looking for the
             * characters that are actually in it.
             */
            'original_value' => $isColumn ? $this->rawCell($shape, $field, $raw) : '',
        ];
    }

    /**
     * Normalise whatever shape the notes were stored in into note triples.
     *
     * @return list<array{field: string, code: string, message: string}>
     */
    private function notes(mixed $notes): array
    {
        if (! is_array($notes)) {
            return [];
        }

        $out = [];

        foreach ($notes as $key => $note) {
            if (is_array($note) && (isset($note['message']) || isset($note['code']))) {
                $out[] = [
                    'field' => (string) ($note['field'] ?? (is_string($key) ? $key : '')),
                    'code' => (string) ($note['code'] ?? 'invalid'),
                    'message' => (string) ($note['message'] ?? ''),
                ];

                continue;
            }

            /**
             * Tolerated fallback: a Laravel-style `field => [messages]` bag, or
             * a flat list of strings. The staging pass writes RowNote triples,
             * but this report must not go blank if it ever writes anything else
             * — a report that silently loses rows is worse than an ugly one.
             */
            foreach ((array) $note as $message) {
                if (is_scalar($message)) {
                    $out[] = [
                        'field' => is_string($key) ? $key : '',
                        'code' => 'invalid',
                        'message' => (string) $message,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The best available value of one schema column: normalised first, raw
     * behind it.
     *
     * @param  array<string, mixed>  $normalized
     * @param  array<int|string, mixed>  $raw
     */
    private function cellFor(string $shape, string $key, array $normalized, array $raw): string
    {
        $index = CsvImportSchema::indexOf($shape, $key);
        $values = $normalized['values'] ?? null;

        if (is_array($values) && isset($values[$index]) && is_scalar($values[$index])) {
            return (string) $values[$index];
        }

        return $this->rawCell($shape, $key, $raw);
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function rawCell(string $shape, string $key, array $raw): string
    {
        $index = CsvImportSchema::indexOf($shape, $key);

        if (isset($raw[$index]) && is_scalar($raw[$index])) {
            return (string) $raw[$index];
        }

        // Tolerate a raw payload keyed by field name rather than by position.
        if (isset($raw[$key]) && is_scalar($raw[$key])) {
            return (string) $raw[$key];
        }

        return '';
    }

    /**
     * @return array<int, CsvImportFile>
     */
    private function filesById(CsvImportRun $run): array
    {
        return $run->files->keyBy('id')->all();
    }

    /**
     * @return list<string>
     */
    private function reportColumns(bool $withRaw = true): array
    {
        return array_values(array_filter([
            'id',
            'csv_import_file_id',
            'line_number',
            'record_number',
            $withRaw ? 'raw' : null,
            'normalized',
            'status',
            'errors',
            'result',
            'result_category',
            'result_message',
        ]));
    }
}
