<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The loan whose release produced this ledger row, for the rows that come
     * from share capital withheld at release (LoanService::release(), through
     * ShareCapitalReleaseCredit).
     *
     * Shaped like `repayment_id` (see
     * 2026_09_24_000000_add_repayment_id_to_share_capital_ledger_table):
     * nullable, because manual entries, payments and auto-credit runs write
     * rows with no release behind them at all.
     *
     * UNIQUE, so a release writes at most one row however it is retried, and
     * the unique index is also the one the foreign key uses. Restrict on
     * delete rather than null, unlike `repayment_id`: a row with a `loan_id` is
     * money withheld at release, which the Cash Flow report keeps out of cash
     * received, and a row that lost its loan would quietly turn into cash. A
     * released loan is never deleted, so this refuses nothing that happens.
     *
     * No backfill: past releases are never touched. The read-only
     * `share-capital:release-deductions-report` lists them.
     */
    public function up(): void
    {
        Schema::table('share_capital_ledger', function (Blueprint $table) {
            $table->foreignId('loan_id')->nullable()->after('repayment_id');
            $table->unique('loan_id');
            $table->foreign('loan_id')->references('id')->on('loans')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('share_capital_ledger', function (Blueprint $table) {
            $table->dropForeign(['loan_id']);
            $table->dropUnique(['loan_id']);
            $table->dropColumn('loan_id');
        });
    }
};
