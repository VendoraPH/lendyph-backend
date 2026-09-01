<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of one uploaded CSV, staged before anything is written to the
     * real tables.
     *
     * Staging first is what makes the import resumable and reportable. Every
     * line is parsed, validated and recorded here in one pass; only then does a
     * second pass turn valid rows into borrowers and loans. A crash halfway
     * through the second pass loses no work, and a failed row can be explained
     * to the admin by line number instead of by a stack trace.
     */
    public function up(): void
    {
        Schema::create('csv_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_import_file_id')->constrained('csv_import_files')->cascadeOnDelete();

            /**
             * The physical line in the submitted file, header included and
             * 1-based — the number the admin's spreadsheet shows in its gutter.
             * This is the only number that belongs in an error message.
             */
            $table->unsignedInteger('line_number');

            /**
             * The data ordinal, counted after the header is skipped. Kept as a
             * separate column rather than derived as `line_number - 1`, because
             * the two only differ by one when the file has exactly one header
             * line and no blank or continued lines above the data — and
             * conflating them is precisely how an error report ends up telling
             * an admin to fix row 4,812 when the problem is on row 4,813.
             * Storing both means neither has to be reconstructed later.
             */
            $table->unsignedInteger('record_number');

            /** The line exactly as parsed, before any coercion. Kept for forensics. */
            $table->json('raw');

            /** The same line after trimming, date parsing and amount coercion. */
            $table->json('normalized')->nullable();

            $table->enum('status', ['valid', 'invalid']);

            /** Per-field validation messages for an `invalid` row. */
            $table->json('errors')->nullable();

            /**
             * What the import pass did with this row.
             *
             * NULL is the resume marker and carries the whole restart story: a
             * row with no result has not been attempted, or was in flight when
             * the process died. Resuming is therefore `where result is null`,
             * with no separate progress table to fall out of sync.
             *
             * `matched_existing` and `already_imported` are deliberately
             * distinct outcomes. The first means the member was already on file
             * from normal operations and was reused; the second means a prior
             * run of this same import wrote them. Both are non-failures, but
             * only the second means the admin uploaded the file twice — and
             * they will ask.
             */
            $table->enum('result', [
                'imported',
                'matched_existing',
                'already_imported',
                'skipped',
                'failed',
            ])->nullable();

            /**
             * Coarse bucket for grouping an error report; the human sentence
             * beside it.
             *
             * The message is TEXT rather than a varchar because it is where a
             * driver's own error string ends up when a row fails for a reason
             * the validator did not anticipate, and those run long. Under
             * MySQL's strict mode an over-length varchar write is error 1406,
             * so a 300-character failure reason would make the row fail to
             * record WHY it failed — losing exactly the information the column
             * exists to keep.
             */
            $table->string('result_category')->nullable();
            $table->text('result_message')->nullable();

            /**
             * What this row produced, when it produced something. nullOnDelete
             * rather than cascade: deleting an imported borrower must not erase
             * the record that the import created them.
             */
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();

            /**
             * Retry counter, so a row that fails the same way repeatedly is
             * given up on instead of spinning a resumable run forever.
             */
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            /**
             * The processing cursor. Rows are consumed in id order within a
             * file, so `where csv_import_file_id = ? and id > ? order by id` is
             * an index range scan from the cursor onward — it never re-reads
             * the millions of rows already behind it.
             */
            $table->index(['csv_import_file_id', 'id']);

            /**
             * The reporting and resume index. `where result is null` (what is
             * left to do) and the per-outcome counts on the summary screen both
             * land here, and both are answered from the index alone without
             * touching the `raw` and `normalized` JSON blobs that make these
             * rows wide.
             */
            $table->index(['csv_import_file_id', 'status', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_rows');
    }
};
