<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIC submissions (review S-L9 and S-M2):
 *
 * - `filename` is unique, so two files generated in the same second can't
 *   both be saved: the second answers 409 in words instead of a deadlock.
 * - `affected_records` holds a correction's affected records (record types
 *   and keys only), worked out once at generation from two frozen captures,
 *   so the file's page never re-diffs them.
 *
 * The table is new and holds no rows yet: nothing to backfill or deduplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cic_submissions', function (Blueprint $table) {
            $table->dropIndex(['filename']);
            $table->unique('filename');
            $table->json('affected_records')->nullable()->after('correction_reason');
        });
    }

    public function down(): void
    {
        Schema::table('cic_submissions', function (Blueprint $table) {
            $table->dropColumn('affected_records');
            $table->dropUnique(['filename']);
            $table->index('filename');
        });
    }
};
