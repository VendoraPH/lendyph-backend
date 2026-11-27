<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable and additive, with no backfill: a Co-makers-tab row (created via
     * CoMakerController::store) has no reliable member identity to backfill
     * onto this column — guessing one from a name is the exact bug this column
     * exists to remove (see coMakerRecordFor()'s old firstOrCreate() key). The
     * unique index enforces at most one canonical co-maker record per member.
     * cascadeOnDelete() mirrors `borrower_id` on this same table.
     */
    public function up(): void
    {
        Schema::table('co_makers', function (Blueprint $table) {
            $table->foreignId('member_borrower_id')->nullable()->after('borrower_id')
                ->constrained('borrowers')->cascadeOnDelete();
            $table->unique('member_borrower_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('co_makers', function (Blueprint $table) {
            // The unique index is single-column on this column, so it drops
            // automatically with it.
            $table->dropConstrainedForeignId('member_borrower_id');
        });
    }
};
