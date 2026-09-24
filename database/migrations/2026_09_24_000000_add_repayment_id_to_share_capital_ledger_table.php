<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What produced this ledger row, for the rows that come from a payment.
     *
     * Mirrors `loan_ledger_entries.repayment_id` (see
     * 2026_08_06_000000_create_loan_ledger_entries_table): nullable and
     * nullOnDelete, because manual entries and auto-credit runs write
     * `share_capital_ledger` rows with no repayment behind them at all, and a
     * voided-away repayment must not take its (already posted) credit or
     * reversal rows with it.
     */
    public function up(): void
    {
        Schema::table('share_capital_ledger', function (Blueprint $table) {
            $table->foreignId('repayment_id')->nullable()->after('borrower_id')
                ->constrained('repayments')->nullOnDelete();
            $table->index('repayment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('share_capital_ledger', function (Blueprint $table) {
            $table->dropForeign(['repayment_id']);
            $table->dropIndex(['repayment_id']);
            $table->dropColumn('repayment_id');
        });
    }
};
