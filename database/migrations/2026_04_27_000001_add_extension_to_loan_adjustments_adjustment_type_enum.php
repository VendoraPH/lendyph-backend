<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'extension' to the loan_adjustments.adjustment_type enum.
     *
     * Used by POST /api/loans/{id}/extend, which writes a directly-applied
     * LoanAdjustment row (no pending→approved→applied workflow) so the
     * extension shows up in the existing /loans/{loan}/adjustments list.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE loan_adjustments MODIFY adjustment_type ENUM(
            'restructure','penalty_waiver','balance_adjustment','term_extension','extension'
        ) NOT NULL");
    }

    public function down(): void
    {
        // Migrate any 'extension' rows to 'term_extension' (closest semantic match)
        // before narrowing the enum, otherwise MySQL truncates them silently.
        DB::table('loan_adjustments')
            ->where('adjustment_type', 'extension')
            ->update(['adjustment_type' => 'term_extension']);

        DB::statement("ALTER TABLE loan_adjustments MODIFY adjustment_type ENUM(
            'restructure','penalty_waiver','balance_adjustment','term_extension'
        ) NOT NULL");
    }
};
