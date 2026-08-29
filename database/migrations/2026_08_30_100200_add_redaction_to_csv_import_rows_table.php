<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retention for the personal data staged in `csv_import_rows`.
     *
     * `raw` is every cell of a member's line verbatim and `normalized` is the
     * parsed version of the same — name, birthdate, contact number, address,
     * monthly income — held for the life of the deployment with nothing to
     * remove it. The assembled CSV behind it is already deleted the moment a run
     * closes (CsvImportUploadService::releaseStorage); these rows were the one
     * copy left with no retention decision at all, and they are readable through
     * `GET /api/imports/{run}/errors` and its CSV download for as long as they
     * exist.
     *
     * Redaction rather than deletion, because the row is also the evidence. The
     * per-outcome counts on the status endpoint and the grouped summary on the
     * error report are both derived from `line_number`, `status`, `result` and
     * `result_category`, and deleting rows would silently change numbers an
     * operator may have to reconcile years later. Blanking the personal columns
     * leaves every count exactly where it was and takes the data out.
     */
    public function up(): void
    {
        Schema::table('csv_import_rows', function (Blueprint $table) {
            /**
             * `raw` was NOT NULL because a staged row always has a source line.
             * It still does — until the line is redacted, which is the one state
             * that column was never given a way to express. Widened rather than
             * blanked to `[]`, so "no personal data here" is distinguishable
             * from "an empty line was uploaded" without inspecting a second
             * column.
             */
            $table->json('raw')->nullable()->change();

            /**
             * When this row's personal columns were blanked, and the marker that
             * makes redacting idempotent — every sweep only ever touches rows
             * where this is still NULL, so a re-run costs nothing and a run
             * interrupted half way through resumes rather than rewriting.
             *
             * Deliberately a timestamp rather than a boolean: an erasure request
             * has to be answerable with a date, and the row that no longer holds
             * the data is the only thing left to answer it with.
             */
            $table->timestamp('redacted_at')->nullable()->after('attempts');
        });

        Schema::table('csv_import_runs', function (Blueprint $table) {
            /**
             * Set once every row of the run has been redacted.
             *
             * Not a duplicate of the per-row column — it is what keeps the
             * nightly sweep from re-reading a finished run's rows forever. The
             * row-level guard decides WHAT to redact; this decides whether the
             * run has to be looked at at all, and `csv_import_runs` holds a few
             * dozen rows against the millions in `csv_import_rows`.
             *
             * Also published by the status endpoint, so an operator opening an
             * old run's error report sees why it reads thinner than it did.
             */
            $table->timestamp('rows_redacted_at')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('csv_import_runs', function (Blueprint $table) {
            $table->dropColumn('rows_redacted_at');
        });

        Schema::table('csv_import_rows', function (Blueprint $table) {
            $table->dropColumn('redacted_at');
        });

        /*
         * Rolling back cannot restore what was redacted, and must not fail
         * trying: any row already blanked holds a NULL `raw` that the original
         * NOT NULL definition rejects. They are given an empty payload so the
         * column can be narrowed again. Stated plainly because it is lossy —
         * this direction exists to unstick a migration, not to undo a
         * retention run.
         */
        DB::table('csv_import_rows')->whereNull('raw')->update(['raw' => '[]']);

        Schema::table('csv_import_rows', function (Blueprint $table) {
            $table->json('raw')->nullable(false)->change();
        });
    }
};
