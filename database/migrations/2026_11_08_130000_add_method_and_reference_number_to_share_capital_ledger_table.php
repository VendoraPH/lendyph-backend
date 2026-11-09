<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a standalone share capital entry's money moved, in the Record
     * Payment vocabulary, so its journal can post to that method's account:
     *
     * - `method`: cash, gcash, maya, bank_transfer or online.
     * - `reference_number`: the transfer's reference, required unless cash.
     *
     * Both nullable. Rows written before this, build-up rows (they carry their
     * payment's `repayment_id`) and release rows (`loan_id`) keep null: their
     * money is in another journal, or was never posted. Adds columns only, so
     * no row is written or changed and there is nothing to audit or preview.
     * Each column is added only when missing, so running it again changes
     * nothing.
     */
    public function up(): void
    {
        $columns = [
            'method' => fn (Blueprint $table) => $table->string('method', 30)->nullable()->after('credit'),
            'reference_number' => fn (Blueprint $table) => $table->string('reference_number', 100)->nullable()->after('method'),
        ];

        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('share_capital_ledger', $column)) {
                Schema::table('share_capital_ledger', $add);
            }
        }
    }

    /**
     * Nothing to undo. Dropping the columns would erase how each entry since
     * was paid, which its journal was posted from.
     */
    public function down(): void {}
};
