<?php

namespace App\Services\CsvImport;

use App\Models\CsvImportRow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Takes the people out of `csv_import_rows` and leaves the arithmetic.
 *
 * ONE definition of what a redaction is, because there are two callers and they
 * must not drift: the scheduled `imports:redact-rows`, which is the retention
 * clock, and BorrowerPurgeService, which has to blank a member's staged lines at
 * the moment they exercise a right to erasure — long before that clock expires.
 * A second copy of this list is a column that quietly stops being blanked the
 * next time one is added.
 *
 * WHAT GOES:
 *
 *  - `raw`, every cell of the member's line exactly as submitted.
 *  - `normalized.values`, the same line parsed — name, birthdate, contact
 *    number, address, income — and `normalized.errors`, a second copy of the
 *    note list below.
 *  - `result_message`, which quotes the cell that failed.
 *  - The `message` of every note in `errors` and `normalized.warnings`. Those
 *    are not incidental: ValueNormalizer writes `"{$original}" is not a valid
 *    amount.` and `"{$original}" is not a usable contact number and was left
 *    blank.`, so the notes carry the very cells `raw` was blanked for.
 *
 * WHAT STAYS, AND WHY IT MUST:
 *
 *  - `line_number`, `status`, `result` and `result_category`. Every count on
 *    `GET /api/imports/{run}` is derived from `status` and `result` alone, so a
 *    redacted run reports the same 4,812 rows and the same 312 failures it did
 *    the day it finished.
 *  - The `field` and `code` of every note. The error summary groups by CODE, and
 *    codes are a bounded vocabulary — `date_invalid`, `product_not_mapped` —
 *    with nothing personal in them. Keeping them is what makes the grouped
 *    report reconcile exactly across a redaction instead of collapsing every
 *    per-field error into one row-level bucket. Blanking the whole `errors`
 *    column would have been simpler and would have thrown that away.
 *  - `borrower_id` and `loan_id`, which are links rather than data, and are what
 *    lets a later erasure find this row at all.
 *
 * The result is a report that still says "312 rows failed on `date_invalid`, at
 * lines 41, 88, 102…" and no longer says whose birthdays they were.
 */
class CsvImportRowRedactor
{
    /**
     * Rows read per pass.
     *
     * Small on purpose. Redaction is a background sweep competing with the app
     * for the widest table in the schema, and the alternative — one UPDATE over
     * a run's whole staged set — holds a lock for as long as it takes.
     */
    private const CHUNK = 500;

    /**
     * What a note's message becomes.
     *
     * A fixed sentence rather than an empty string: this lands in the Message
     * column of the CSV an operator downloads and in `stats.by_category[].label`
     * above the count, and a blank there reads as a broken report rather than a
     * retention policy doing its job. The category beside it still carries the
     * meaning.
     */
    public const REDACTED_MESSAGE = 'Message removed: this row has been redacted.';

    /**
     * Blank every unredacted row the query matches.
     *
     * Chunked and committing as it goes, deliberately NOT wrapped in a
     * transaction of its own. An interruption then leaves the rows it already
     * redacted redacted and simply resumes on the next pass — the `redacted_at`
     * guard is what makes repeating it free. Wrapping the sweep would mean an
     * interruption threw away all of it, and doing it in one statement would
     * lock millions of rows on a box that is also serving the app.
     *
     * (BorrowerPurgeService calls this INSIDE its transaction, which is the
     * opposite choice and the right one there: a handful of rows, which must
     * roll back with the delete they accompany.)
     *
     * @param  Builder<CsvImportRow>  $query
     * @return int rows redacted
     */
    public function redact(Builder $query): int
    {
        $redacted = 0;

        $query->clone()
            ->unredacted()
            ->select(['id', 'errors', 'normalized'])
            ->chunkById(self::CHUNK, function (Collection $rows) use (&$redacted): void {
                $now = now();

                /**
                 * The columns that are the same for every row, applied in one
                 * statement. Most staged rows carry neither an error nor a
                 * warning, so this is the path almost all of them take.
                 *
                 * @var list<int> $plain
                 */
                $plain = [];

                foreach ($rows as $row) {
                    $errors = $this->redactNotes($row->errors);
                    $normalized = $this->redactNormalized($row->normalized);

                    if ($errors === null && $normalized === null) {
                        $plain[] = $row->id;

                        continue;
                    }

                    $redacted += DB::table('csv_import_rows')
                        ->where('id', $row->id)
                        ->update($this->columns($now) + [
                            'errors' => $errors === null ? null : json_encode($errors),
                            'normalized' => $normalized === null ? null : json_encode($normalized),
                        ]);
                }

                if ($plain !== []) {
                    $redacted += DB::table('csv_import_rows')
                        ->whereIn('id', $plain)
                        ->update($this->columns($now) + ['errors' => null, 'normalized' => null]);
                }
            });

        return $redacted;
    }

    /**
     * The columns every redacted row gets, whatever its notes.
     *
     * `updated_at` is moved because the row genuinely changed. Nothing reads it:
     * RunStatusReader deliberately derives "last advanced" from the run and its
     * files rather than MAX(csv_import_rows.updated_at), precisely because that
     * column is not indexed.
     *
     * @return array<string, mixed>
     */
    private function columns(CarbonInterface $now): array
    {
        return [
            'raw' => null,
            'result_message' => null,
            'redacted_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * A note list with every message replaced and every field and code kept.
     *
     * Tolerates the shapes ErrorReportBuilder::notes() tolerates — a note
     * triple, a Laravel-style `field => [messages]` bag, a flat list of strings
     * — because a redaction that silently dropped an unexpected shape would
     * leave that shape's personal data in place, which is the one outcome this
     * class exists to prevent. Anything it cannot recognise as a note is
     * discarded rather than kept.
     *
     * @return list<array{field: string, code: string, message: string}>|null
     */
    private function redactNotes(mixed $notes): ?array
    {
        if (! is_array($notes) || $notes === []) {
            return null;
        }

        $out = [];

        foreach ($notes as $key => $note) {
            if (is_array($note) && (isset($note['message']) || isset($note['code']))) {
                $out[] = [
                    'field' => (string) ($note['field'] ?? (is_string($key) ? $key : '')),
                    'code' => (string) ($note['code'] ?? 'invalid'),
                    'message' => self::REDACTED_MESSAGE,
                ];

                continue;
            }

            foreach ((array) $note as $message) {
                if (is_scalar($message)) {
                    $out[] = [
                        'field' => is_string($key) ? $key : '',
                        'code' => 'invalid',
                        'message' => self::REDACTED_MESSAGE,
                    ];
                }
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * The staged payload reduced to its warnings.
     *
     * Rebuilt key by key rather than unset key by key, so a key added to
     * NormalizedRow::toPayload() later is dropped by default instead of
     * surviving a redaction because nobody remembered it here. `values` — the
     * parsed member — and the duplicate `errors` list both go; `shape` and `row`
     * are kept because they are structure, not data, and they keep the JSON
     * self-describing.
     *
     * Null when there is nothing left worth storing, which is the common case:
     * the warnings key is what the error report's JSON_LENGTH leg matches on, so
     * a row with no warnings must not be given an empty one.
     *
     * @return array<string, mixed>|null
     */
    private function redactNormalized(mixed $normalized): ?array
    {
        if (! is_array($normalized)) {
            return null;
        }

        $warnings = $this->redactNotes($normalized['warnings'] ?? null);

        if ($warnings === null) {
            return null;
        }

        return [
            'shape' => (string) ($normalized['shape'] ?? ''),
            'row' => (int) ($normalized['row'] ?? 0),
            'warnings' => $warnings,
        ];
    }
}
