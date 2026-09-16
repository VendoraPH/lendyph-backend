<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A GCash transaction now has exactly one party, which is either a member
     * (`borrower_id`) or a walk-in (`gcash_non_member_id`).
     *
     * `borrower_id` has to become nullable for that to be expressible at all —
     * it was NOT NULL, which is why every walk-in the counter tried to record
     * was rejected. "Exactly one of the two" is enforced in
     * StoreGCashTransactionRequest and by the CHECK constraint below, so a row
     * with neither party (or both) cannot be written by any path.
     */
    public function up(): void
    {
        Schema::table('gcash_transactions', function (Blueprint $table) {
            $table->foreignId('gcash_non_member_id')
                ->nullable()
                ->after('borrower_id')
                ->constrained('gcash_non_members')
                ->restrictOnDelete();

            $table->index(['gcash_non_member_id', 'transaction_date'], 'gcash_tx_non_member_date_idx');
        });

        Schema::table('gcash_transactions', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable()->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE gcash_transactions
            ADD CONSTRAINT chk_gcash_tx_exactly_one_party
            CHECK (
                (borrower_id IS NOT NULL AND gcash_non_member_id IS NULL)
                OR (borrower_id IS NULL AND gcash_non_member_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gcash_transactions DROP CONSTRAINT chk_gcash_tx_exactly_one_party');

        Schema::table('gcash_transactions', function (Blueprint $table) {
            $table->dropIndex('gcash_tx_non_member_date_idx');
            $table->dropConstrainedForeignId('gcash_non_member_id');
        });

        // Rows whose only party was a walk-in cannot satisfy a NOT NULL
        // borrower_id, and there is no borrower to point them at. The down path
        // drops them rather than inventing one.
        DB::table('gcash_transactions')->whereNull('borrower_id')->delete();

        Schema::table('gcash_transactions', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable(false)->change();
        });
    }
};
