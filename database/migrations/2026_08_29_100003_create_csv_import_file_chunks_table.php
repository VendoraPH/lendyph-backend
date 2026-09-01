<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One received slice of an uploaded CSV, stored as its own row.
     *
     * A coop's legacy extract is far too large for a single request that has to
     * survive a Philippine mobile connection, so the browser cuts it up and
     * sends the pieces independently. This table is the receipt book.
     *
     * The `(csv_import_file_id, chunk_index)` unique index below is the design,
     * not a safety net bolted on afterwards. It makes row-exists equivalent to
     * chunk-stored, which buys two things:
     *
     * 1. "Which chunks are still missing?" is a set complement — the indexes
     *    0..total_chunks-1 minus the indexes present — answerable with one
     *    query, and correct no matter how the upload was interrupted.
     * 2. Two requests carrying the same chunk index cannot both succeed. The
     *    loser gets a duplicate-key error and is handled as the retry it is.
     *
     * The obvious alternative — a `received_chunks` JSON bitmap on
     * `csv_import_files` — is racy by construction: read-modify-write of one
     * column from concurrent requests loses whichever write landed first, and
     * the file then reports a chunk missing that is sitting on disk, or
     * complete when it is not. Chunked uploads are concurrent by definition, so
     * that race is the normal case, not an edge one.
     */
    public function up(): void
    {
        Schema::create('csv_import_file_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csv_import_file_id')->constrained('csv_import_files')->cascadeOnDelete();

            /** Zero-based position of this chunk in the reassembled file. */
            $table->unsignedInteger('chunk_index');

            $table->unsignedBigInteger('size_bytes');

            /**
             * Digest of this slice alone. Verified on arrival, so a chunk
             * corrupted in transit is rejected while it can still be resent —
             * rather than surfacing hours later as a whole-file hash mismatch
             * with no way to tell which piece went bad.
             */
            $table->string('sha256', 64);

            $table->string('path');
            $table->timestamps();

            $table->unique(['csv_import_file_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_file_chunks');
    }
};
