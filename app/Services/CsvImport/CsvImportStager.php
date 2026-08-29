<?php

namespace App\Services\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Turns an assembled CSV into one `csv_import_rows` per record.
 *
 * Staging is the whole reason this import is resumable and reportable: every
 * line is read, normalised and recorded exactly once, and only then does a
 * second pass turn the valid ones into borrowers and loans. A crash during that
 * second pass therefore loses no parsing work, and a bad row can be explained to
 * an admin by line number rather than by a stack trace.
 *
 * TWO MySQL JSON PROPERTIES DECIDE THE STORAGE SHAPE HERE. Both were established
 * by probing the server rather than reasoned about, and both produce silently
 * wrong data rather than an error:
 *
 * ONE — MySQL does not preserve JSON OBJECT key order. It rewrites keys by
 * length and then lexicographically, so `{"Account No.":…,"Last Name":…}` reads
 * back in an order that has nothing to do with the file. JSON ARRAYS are
 * preserved verbatim. `raw` is therefore stored as a positional LIST of cells,
 * never an object keyed by header name. Were it an object, anything that
 * recovered column position by iterating it — pairing it against the header for
 * the error report, say — would show every value under the wrong heading. That
 * is the same column-shift corruption CsvImportReader rejects whole FILES to
 * prevent, arriving through the back door with no error anywhere.
 *
 * TWO — a whole-number float loses its type through a JSON column: 12500.0 goes
 * in and comes back as int 12500, while 12500.5 survives as a float. So money
 * never travels as a float at all. NormalizedRow::toPayload() writes every value
 * as a string or null, and NormalizedRow::fromPayload() casts back using the
 * schema's declared type — which is the only thing that decides what a value
 * means. self::assertPayloadRoundTrips() re-reads the first staged row of every
 * file and holds both properties to account on real data, because the failure
 * mode of getting either wrong is a plausible-looking import, not an exception.
 *
 * This class NEVER writes to the filesystem. It reads the assembled file off the
 * private disk and writes rows to the database, nothing else. The scheduler runs
 * as root and php-fpm as www-data, so any file this created would be root-owned
 * 0600 and unreadable by the web process forever after — a failure that only
 * appears in production.
 */
class CsvImportStager
{
    /**
     * Rows per INSERT. Large enough that a 12,000-line coop export is 60 round
     * trips rather than 12,000, small enough that the batch being built is a few
     * hundred kilobytes rather than the whole file.
     */
    private const INSERT_BATCH = 200;

    /**
     * Distinct "Loan Product" strings collected before the collection is capped.
     *
     * A column-SHIFTED file yields roughly one distinct "product" per row —
     * every borrower's surname, or every maturity date, read out of the wrong
     * position — so on a 400,000-row export an uncapped distinct set is memory
     * exhaustion, and migration day is exactly when a file is most likely to be
     * malformed. The mapping screen also asks a human to pick a LoanProduct for
     * each of these, so anything approaching this number is unusable as a form
     * whatever caused it.
     *
     * 500 deliberately matches the threshold the mapping endpoint refuses at, so
     * that a shifted file is capped here and then REFUSED there, rather than
     * capped below the refusal threshold and quietly presented as a 200-item
     * form nobody can fill in.
     */
    private const MAX_DISTINCT_PRODUCTS = 500;

    public function __construct(
        private readonly CsvImportReader $reader = new CsvImportReader,
        private readonly CustomerRowNormalizer $customers = new CustomerRowNormalizer,
        private readonly LoanRowNormalizer $loans = new LoanRowNormalizer,
    ) {}

    /**
     * Stage one assembled file.
     *
     * Deliberately NOT wrapped in one transaction. A 100,000-row file would hold
     * a single transaction open for minutes, and the completion signal does not
     * need one: `csv_import_files.record_count` is written last and only after
     * every row is in, so a stage that died halfway leaves `record_count` NULL
     * and is simply re-staged from scratch.
     *
     * Re-staging deletes the file's existing rows first, which is safe precisely
     * because it refuses to run once any row carries a `result` — at that point
     * the import pass has begun and those rows are the record of what was
     * written to real tables.
     *
     * @throws CsvFileRejectedException when the file cannot be read at all
     * @throws LogicException when the file has already been imported from
     */
    public function stage(CsvImportFile $file): StagingResult
    {
        $shape = $this->shapeFor($file);

        if ($file->assembled_path === null) {
            throw new LogicException(
                "csv_import_files #{$file->id} has no assembled_path, so there is nothing to stage. "
                .'The upload has to be reassembled and hash-verified first.'
            );
        }

        $this->clearPreviousStaging($file);

        $result = $this->reader->readFromDisk(CsvImportReader::PII_DISK, $file->assembled_path, $shape);

        $batch = [];
        $staged = 0;
        $valid = 0;
        $invalid = 0;
        $products = [];
        $productsTruncated = false;
        $now = now();

        foreach ($result->rows() as $row) {
            $normalized = $shape === CsvImportSchema::LOANS
                ? $this->loans->normalize($row)
                : $this->customers->normalize($row);

            if ($shape === CsvImportSchema::LOANS) {
                $this->collectLoanProduct($normalized, $products, $productsTruncated);
            }

            $isValid = $normalized->isValid();
            $isValid ? $valid++ : $invalid++;

            $batch[] = [
                'csv_import_file_id' => $file->id,
                // The record ordinal counting the header, which is the physical
                // line for every export that has no blank lines and no newline
                // inside a quoted cell. Both numbers are stored rather than one
                // derived from the other precisely so an error report never has
                // to guess which of the two it is holding.
                'line_number' => $row->recordNumber,
                'record_number' => $row->rowNumber,
                // The erasure key. Written here and only here, for every row,
                // valid or not and parsed or not — see self::externalAccountNo().
                'external_account_no' => self::externalAccountNo($normalized, $row->cells),
                // A LIST. See the class docblock — an object here is corruption.
                'raw' => json_encode($row->cells, JSON_UNESCAPED_UNICODE),
                'normalized' => json_encode($normalized->toPayload(), JSON_UNESCAPED_UNICODE),
                'status' => $isValid ? 'valid' : 'invalid',
                'errors' => $isValid ? null : json_encode($normalized->errorsToArray(), JSON_UNESCAPED_UNICODE),
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $staged++;

            if (count($batch) >= self::INSERT_BATCH) {
                DB::table('csv_import_rows')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('csv_import_rows')->insert($batch);
        }

        $notes = $result->notes();

        if ($productsTruncated) {
            $notes[] = 'More than '.self::MAX_DISTINCT_PRODUCTS.' distinct values were found in the Loan Product '
                .'column, which usually means the columns are shifted and this is not the product column at all. '
                .'Only the first '.self::MAX_DISTINCT_PRODUCTS.' were collected; check the file before mapping.';
        }

        $this->assertPayloadRoundTrips($file, $shape);

        // Written LAST and only on a complete pass: this is the file's "staged"
        // flag, so it must not be true of a half-staged file.
        $file->forceFill([
            'delimiter' => $result->delimiter,
            'column_count' => $result->columnCount,
            'header_skipped' => $result->hasHeader,
            'encoding_note' => $this->encodingNote($notes),
            'record_count' => $staged,
        ])->save();

        Log::info('csv-import: staged a file', [
            'csv_import_file_id' => $file->id,
            'csv_import_run_id' => $file->csv_import_run_id,
            'kind' => $file->kind,
            'staged' => $staged,
            'valid' => $valid,
            'invalid' => $invalid,
            'distinct_loan_products' => count($products),
        ]);

        return new StagingResult(
            staged: $staged,
            valid: $valid,
            invalid: $invalid,
            loanProducts: array_values($products),
            notes: array_values($notes),
        );
    }

    /**
     * Whether this file already holds a complete set of staged rows.
     */
    public function isStaged(CsvImportFile $file): bool
    {
        return $file->record_count !== null;
    }

    /**
     * The value `csv_import_rows.external_account_no` holds — the one link an
     * erasure has to a line that never produced a borrower.
     *
     * WRITTEN FOR EVERY ROW, including the invalid ones, and BEFORE any outcome
     * exists — which is the whole reason this exists rather than being derived
     * later. `borrower_id` is only ever set on a row that produced or matched a
     * member; a row rejected at staging, an ambiguous identity match, a loan
     * whose member was not found, a row abandoned after repeated attempts and a
     * row that threw mid-write all carry NULL there while still holding that
     * member's entire line in `raw`. Before this column, BorrowerPurgeService
     * could not find any of them — and keying the erasure on the outcome would
     * have meant re-auditing that list every time an outcome is added.
     *
     * Public and static so the test fixtures that write staged rows directly
     * derive the key the same way this does. Two definitions of "which string
     * links this line to a member" is one definition that quietly stops matching.
     *
     * The NORMALISED value first, and NOT re-trimmed: CsvImportProcessor writes
     * exactly `(string) $normalized->value('account_no')` into
     * `borrowers.external_account_no`, so anything done to it here that is not
     * done there is a member this predicate will fail to match. ValueNormalizer
     * has already trimmed it, stripped the BOM and the non-breaking space, and
     * capped it at the 50 characters both columns hold.
     *
     * THE RAW CELL BEHIND IT, because "written for every row" was not true of a
     * row that never PARSED. When cellsByKey() fails its column-count check —
     * one stray delimiter inside an unquoted address is enough — both
     * normalizers return a NormalizedRow with EMPTY values, so there is no
     * `account_no` to read and the key was staged NULL while `raw` still held
     * the member's whole line: name, birthdate, contact number, address,
     * income. That is one parse-failure class rather than the five outcome
     * classes the column was added for, and the 30-day sweep still reaches
     * those rows because it scopes by `csv_import_file_id` rather than by this
     * key — but an erasure request asked before that clock expires would have
     * missed them.
     *
     * THE FALLBACK MAY BE WRONG, AND THAT IS THE POINT — do not "fix" it out.
     * The row failed to parse precisely because its columns do not line up, so
     * cell 0 is not guaranteed to be the account number. The failure is
     * symmetric and both directions are acceptable:
     *
     *  - a wrong key matches no member, which is exactly the NULL it replaces;
     *  - a wrong key that happens to equal ANOTHER member's account number
     *    redacts one extra row on that member's erasure. That costs a line of
     *    report arithmetic. It discloses nothing — redaction only ever removes
     *    data, never exposes it — and it is strictly better than leaving a
     *    member unerasable.
     *
     * Capped at 50 here where the normalised path is not, and the asymmetry is
     * deliberate: on that path ValueNormalizer's own 50-character cap
     * guarantees the width, so truncating there would be a silent bug hiding a
     * schema change. On THIS path the normalizer never ran, nothing bounds the
     * cell, and an over-long one would fail the whole staging pass with MySQL
     * error 1406 — losing every row in the file over one malformed line. A
     * truncated key is just another key that fails to match.
     *
     * Empty becomes NULL rather than `''`. An empty string is a key every blank
     * row would share, and a purge matching on it would blank strangers' lines.
     *
     * @param  list<string>  $cells  The row exactly as read, positionally.
     */
    public static function externalAccountNo(NormalizedRow $normalized, array $cells = []): ?string
    {
        $value = $normalized->value('account_no');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        /*
         * The schema's own index, never a literal 0. `account_no` leads both
         * shapes today; deriving it is what keeps that from becoming a fact
         * this method silently depends on.
         */
        $cell = $cells[CsvImportSchema::indexOf($normalized->shape, 'account_no')] ?? null;

        if (! is_string($cell)) {
            return null;
        }

        // The same trim, BOM strip and non-breaking-space repair the normalised
        // path already had applied to it — otherwise a cell carrying a stray
        // U+FEFF is a different string from the one on the borrower and never
        // matches.
        $cell = (new ValueNormalizer)->text($cell);

        return $cell === null ? null : mb_substr($cell, 0, 50);
    }

    private function shapeFor(CsvImportFile $file): string
    {
        return $file->kind === 'loans' ? CsvImportSchema::LOANS : CsvImportSchema::CUSTOMERS;
    }

    /**
     * @param  array<string, string>  $products
     */
    private function collectLoanProduct(NormalizedRow $normalized, array &$products, bool &$truncated): void
    {
        $value = $normalized->value('loan_product');

        // A blank Loan Product cell is collected as `""` rather than skipped.
        // Those rows still have to be filed against SOME product, so the admin
        // has to be offered the choice — and `""` is the key the mapping is
        // stored and looked up under. Skipping it would hide the decision and
        // then fail every one of those loans as unmapped.
        $product = is_string($value) ? $value : '';

        // Keyed by the value itself so "distinct" is exact rather than
        // case-folded: the admin maps the strings the file actually contains,
        // and folding two spellings together here would silently map both to
        // whichever product they happened to pick for one of them.
        if (array_key_exists($product, $products)) {
            return;
        }

        if (count($products) >= self::MAX_DISTINCT_PRODUCTS) {
            $truncated = true;

            return;
        }

        $products[$product] = $product;
    }

    /**
     * Drop a previous, incomplete staging pass — and refuse outright once the
     * import pass has touched any of those rows.
     *
     * A row carrying a `result` is the record that a borrower or a loan was
     * written to a real table. Deleting it would leave the domain row in place
     * with nothing saying where it came from, and a re-stage would then import
     * it a second time.
     */
    private function clearPreviousStaging(CsvImportFile $file): void
    {
        $decided = CsvImportRow::query()
            ->where('csv_import_file_id', $file->id)
            ->whereNotNull('result')
            ->count();

        if ($decided > 0) {
            throw new LogicException(
                "csv_import_files #{$file->id} already has {$decided} row(s) carrying an import result, so it "
                .'cannot be re-staged. Those rows are the record of what was written to borrowers and loans; '
                .'deleting them would import the same records again with nothing to detect it.'
            );
        }

        CsvImportRow::query()->where('csv_import_file_id', $file->id)->delete();
    }

    /**
     * Read the first staged row back out of MySQL and prove both JSON hazards
     * were actually avoided on THIS file.
     *
     * Asserted rather than trusted because neither failure raises an error. A
     * key-ordered `raw` and a retyped money value both produce a staged file
     * that looks entirely normal and imports plausible garbage — the exact class
     * of bug that has to be caught on the way in or not at all.
     */
    private function assertPayloadRoundTrips(CsvImportFile $file, string $shape): void
    {
        $stored = CsvImportRow::query()
            ->where('csv_import_file_id', $file->id)
            ->orderBy('id')
            ->first();

        if ($stored === null) {
            return;
        }

        if (! is_array($stored->raw) || ! array_is_list($stored->raw)) {
            throw new LogicException(
                "The staged `raw` payload for csv_import_files #{$file->id} did not come back as a JSON list. "
                .'MySQL rewrites JSON object key order, so a `raw` that is not a list has already lost which '
                .'value belongs to which column.'
            );
        }

        // Rebuilds through the schema's declared types and throws if any money
        // value came back as something other than the string it went in as.
        NormalizedRow::fromPayload((array) $stored->normalized);

        if (count($stored->raw) !== CsvImportSchema::width($shape) && $stored->status === 'valid') {
            throw new LogicException(
                "A valid staged row for csv_import_files #{$file->id} holds ".count($stored->raw)
                .' cells but the '.$shape.' shape is '.CsvImportSchema::width($shape).' columns wide.'
            );
        }
    }

    /**
     * @param  list<string>  $notes
     */
    private function encodingNote(array $notes): ?string
    {
        if ($notes === []) {
            return null;
        }

        // varchar(255): truncated deliberately rather than allowed to raise
        // MySQL error 1406, which would fail the whole staging pass over a
        // diagnostic string.
        return mb_substr(implode(' ', $notes), 0, 255);
    }
}
