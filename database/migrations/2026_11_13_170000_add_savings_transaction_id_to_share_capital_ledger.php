<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The savings row a share capital credit came from (gap round S3): a
 * transfer from a member's savings to their share capital writes one
 * `share_capital_transfer` savings row and one share capital credit naming
 * it here. Its journal (Dr 2050 / Cr 3060) is the savings row's, so
 * AutomaticPoster::shareCapitalEntry() refuses a row that has one, as it
 * refuses `repayment_id` and `loan_id` rows.
 *
 * Nullable: every other share capital row has no savings row behind it.
 * Unique, so a savings row credits share capital once, and restrict on
 * delete (savings rows are never deleted). No backfill: new records only.
 * Added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('share_capital_ledger', 'savings_transaction_id')) {
            return;
        }

        Schema::table('share_capital_ledger', function (Blueprint $table): void {
            $table->foreignId('savings_transaction_id')->nullable()->after('loan_id')->unique()
                ->constrained('savings_transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('share_capital_ledger', 'savings_transaction_id')) {
            return;
        }

        Schema::table('share_capital_ledger', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('savings_transaction_id');
        });
    }
};
