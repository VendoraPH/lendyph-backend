<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who linked each co-maker to a loan.
     *
     * "When" is the row's existing `created_at`, so no new datetime column is
     * added (and nothing needs registering in TimezoneShift).
     *
     * Nullable with no backfill: every link written before this column existed
     * was made by whoever created or edited the loan, and nothing on record says
     * which of them it was. nullOnDelete() so removing a user never takes a
     * loan's co-maker links with them, the same as `loans.created_by`.
     */
    public function up(): void
    {
        Schema::table('co_maker_loan', function (Blueprint $table) {
            $table->foreignId('added_by')->nullable()->after('co_maker_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('co_maker_loan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
        });
    }
};
