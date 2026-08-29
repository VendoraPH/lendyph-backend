<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One of the two CSV files belonging to an import run.
     *
     * Each file is uploaded in chunks and reassembled server-side, so this row
     * describes both what the browser claimed to be sending (`size_bytes`,
     * `sha256`, `total_chunks`) and what the server actually ended up with
     * (`assembled_path`, `assembled_sha256`). Keeping the two separate is the
     * whole point: they are compared, and a mismatch fails the run before a
     * single row is parsed rather than importing a truncated file.
     */
    public function up(): void
    {
        Schema::create('csv_import_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_import_run_id')->constrained('csv_import_runs')->cascadeOnDelete();

            /**
             * Which of the two files this is. The customers file has to be
             * imported first — loan rows join to borrowers by
             * `external_account_no` — so the kind is not cosmetic, it decides
             * ordering.
             */
            $table->enum('kind', ['customers', 'loans']);

            $table->string('original_filename');

            /** Byte length and digest as declared by the uploader, before assembly. */
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);

            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');

            /** Where the reassembled file landed, and what it actually hashed to. */
            $table->string('assembled_path')->nullable();
            $table->string('assembled_sha256', 64)->nullable();

            /**
             * Sniffed at assembly, not assumed. Philippine coop exports come
             * out of Excel as comma-, semicolon- or tab-separated depending on
             * the machine's locale, and guessing wrong turns every row into a
             * single unparsable column.
             */
            $table->char('delimiter', 1)->nullable();

            /**
             * What had to be done to read the bytes — a stripped UTF-8 BOM, a
             * Windows-1252 transcode. Recorded so a later "why is this name
             * mangled" question has an answer.
             */
            $table->string('encoding_note')->nullable();

            $table->boolean('header_skipped')->default(false);

            /** Populated during staging: data rows found, and columns per row. */
            $table->unsignedInteger('record_count')->nullable();
            $table->unsignedInteger('column_count')->nullable();

            $table->timestamps();

            /**
             * One customers file and one loans file per run, enforced by the
             * database rather than by a check-then-insert in the upload
             * handler. Two tabs starting the same upload cannot produce two
             * customer files for one run and leave the importer to guess which
             * is authoritative.
             */
            $table->unique(['csv_import_run_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_files');
    }
};
