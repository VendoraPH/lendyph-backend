<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The link an erasure needs, for the staged rows that never got one.
     *
     * `csv_import_rows.borrower_id` was the only thing tying a member to their
     * CSV lines, and FIVE producers leave it NULL while the row still holds that
     * member's entire line in `raw` and `normalized`:
     *
     *  - CsvImportProcessor::markInvalidRowsSkipped(), which stamps
     *    `result = 'skipped'` on every row that failed validation at staging and
     *    stamps no borrower, because there is none to stamp;
     *  - BorrowerMatch::ambiguous(), which passes `borrower: null` even though
     *    its `candidateIds` name real members on file — the ambiguity is about
     *    WHICH member, not about whether the line describes one;
     *  - a loan row that fails `borrower_not_found`;
     *  - a row given up on after MAX_ATTEMPTS, stamped `failed`/`abandoned`;
     *  - a row that threw while being written, stamped `failed`/`exception`.
     *
     * The last two were not in the report this fix answers, and they matter
     * because they are shape-agnostic: a CUSTOMERS row that was abandoned or
     * threw holds a member's whole line with no borrower attached, exactly like
     * the first two. That is also the argument for keying on a column the STAGER
     * writes rather than on anything the import pass decides — the erasure then
     * does not depend on anybody enumerating the outcomes correctly, this
     * comment included.
     *
     * So BorrowerPurgeService, which redacted `where borrower_id = ?`, answered
     * a right-to-erasure request incompletely. The realistic shape of it is a
     * member imported successfully by a second run whose first-run line was
     * ambiguous or invalid: the linked row is blanked, the other two are not,
     * and `GET /api/imports/{run}/errors.csv` goes on streaming that member's
     * legacy account number and real birthdate to any admin until the retention
     * clock expires.
     *
     * `borrowers.external_account_no` is what actually links a member to their
     * CSV lines — it is the coop's own account number, the join key the whole
     * import is built on, and it is present on the row whether or not the import
     * managed to produce a borrower. Recording it here at staging time gives the
     * erasure path a predicate it can use.
     *
     * A COLUMN RATHER THAN A JSON PREDICATE, and that choice is the point of
     * this migration. The alternative was to match on
     * `JSON_UNQUOTE(JSON_EXTRACT(normalized, '$.values[0]'))`, which needs no
     * schema change and is wrong twice over.
     *
     * It is unindexable, and by a margin that was measured rather than assumed.
     * EXPLAIN on a 200,000-row copy of this table, both predicates ORed with the
     * `borrower_id` leg the erasure already had:
     *
     *   indexed column  index_merge union(borrower_id, external_account_no)
     *                   rows examined: 2
     *   JSON predicate  range scan on PRIMARY
     *                   rows examined: 99,372
     *
     * and the second figure understates it, because every one of those rows has
     * its `normalized` blob decoded to evaluate the path. `DELETE
     * /api/borrowers/{borrower}` runs this inline, so that is an interactive
     * request scanning the widest table in the schema.
     *
     * And it couples the erasure path to the POSITIONAL layout of a JSON blob.
     * `normalized.values` is a list precisely because MySQL rewrites object key
     * order (see NormalizedRow::toPayload()), and index 0 is `account_no` only
     * for as long as nobody reorders CsvImportSchema. That is an implicit
     * contract that breaks quietly, and the thing it would break is the one path
     * whose failure mode is "a member was told they were erased and they were
     * not".
     */
    public function up(): void
    {
        Schema::table('csv_import_rows', function (Blueprint $table) {
            /**
             * varchar(50), matching `borrowers.external_account_no` exactly, and
             * that is not a coincidence to be tidied later: both normalizers cap
             * this cell at 50 characters through
             * ValueNormalizer::boundedText($raw, 'account_no', 50, ...), and a
             * value that cannot fit in the borrowers column can never match a
             * member anyway. Widening one of the three without the others is how
             * this column starts failing to match.
             *
             * Nullable because a row may genuinely have no account number — a
             * blank cell, a line rejected before it was parsed — and NULL there
             * means "nothing to match on", which is honest. It is never the
             * empty string: `''` would be a key that every blank row shares, and
             * a purge matching on it would blank strangers' lines.
             */
            $table->string('external_account_no', 50)->nullable()->after('record_number');

            /**
             * Indexed, because this is looked up by VALUE by an interactive
             * request — DELETE /api/borrowers/{borrower} runs the purge inline —
             * and the table it is looked up in is the one this feature is
             * designed to let grow to millions of rows. Not unique: one account
             * number legitimately appears on a customer row, on every loan row
             * belonging to that member, and again on every re-upload.
             */
            $table->index('external_account_no');
        });

        $this->backfill();
    }

    /**
     * Populate the column for rows staged before it existed.
     *
     * WITHOUT THIS THE FIX HAS A SILENT HOLE. Every row already in the table has
     * a NULL here, so the widened erasure predicate would match none of them and
     * the incomplete erasure it fixes would go on being incomplete for exactly
     * the rows that already exist — which, on a box that has already run a
     * migration, is all of them.
     *
     * This is the one place the positional JSON read is correct rather than
     * merely convenient. A migration describes a MOMENT: at the moment it runs,
     * `account_no` is index 0 of `normalized.values` for both the customers and
     * the loans shape, and freezing that fact into a historical record is what a
     * migration is for. The erasure path must not freeze it, because it has to
     * keep being true forever — hence the column.
     *
     * Public and separately callable so the backfill can be exercised by a test
     * against rows that really do carry a NULL, rather than asserted by reading
     * the SQL.
     */
    public function backfill(): void
    {
        /*
         * Nowdoc, so `$.values[0]` reaches MySQL as written. In a
         * double-quoted PHP string `$.` survives by accident rather than by
         * rule, and a JSON path that is silently wrong extracts NULL from every
         * row and backfills nothing — with no error to notice.
         */
        $value = <<<'SQL'
        JSON_UNQUOTE(JSON_EXTRACT(`normalized`, '$.values[0]'))
        SQL;

        $type = <<<'SQL'
        JSON_TYPE(JSON_EXTRACT(`normalized`, '$.values[0]'))
        SQL;

        $lastId = 0;

        while (true) {
            /*
             * Walked in id windows rather than run as one UPDATE. This table is
             * the widest in the schema and the deploy that runs this migration
             * is not a maintenance window; a single statement over millions of
             * rows holds a lock for as long as it takes.
             */
            $ids = DB::table('csv_import_rows')
                ->where('id', '>', $lastId)
                ->whereNull('external_account_no')
                ->whereNotNull('normalized')
                ->orderBy('id')
                ->limit(2000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $lastId = (int) $ids->last();

            DB::table('csv_import_rows')
                ->whereIn('id', $ids)
                /*
                 * JSON_TYPE = 'STRING', not merely "the path resolved".
                 * JSON_UNQUOTE of a JSON null returns the four-character string
                 * "null", so an account number that was genuinely absent would
                 * otherwise be backfilled as the literal text null — a value
                 * that looks populated, matches nothing, and hides the fact
                 * that the row has no key.
                 *
                 * It is also what excludes rows an earlier retention sweep
                 * already redacted: those carry a `normalized` rebuilt without
                 * a `values` key, so the path resolves to nothing. They hold no
                 * personal data and have nothing left for an erasure to find.
                 */
                ->whereRaw("{$type} = 'STRING'")
                ->whereRaw("{$value} <> ''")
                ->update([
                    /*
                     * LEFT(..., 50) so this cannot abort a deploy with MySQL
                     * error 1406 on a row staged under some other cap. A
                     * truncated key fails to match, which is no worse than the
                     * NULL it replaces; a migration that throws half way
                     * through leaves the column half populated and the deploy
                     * on the floor.
                     */
                    'external_account_no' => DB::raw("LEFT({$value}, 50)"),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('csv_import_rows', function (Blueprint $table) {
            $table->dropIndex(['external_account_no']);
            $table->dropColumn('external_account_no');
        });
    }
};
