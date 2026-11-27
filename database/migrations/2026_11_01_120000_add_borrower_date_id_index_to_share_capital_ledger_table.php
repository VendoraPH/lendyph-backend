<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One member's share capital in posting order: the running balance of
 * GET /share-capital/ledger sums each entry's earlier entries by
 * (borrower_id, date, id), and the members list and the statement group and
 * order by the same columns. Additive; the single-column indexes stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_capital_ledger', function (Blueprint $table) {
            $table->index(['borrower_id', 'date', 'id'], 'share_capital_ledger_borrower_date_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('share_capital_ledger', function (Blueprint $table) {
            $table->dropIndex('share_capital_ledger_borrower_date_id_index');
        });
    }
};
