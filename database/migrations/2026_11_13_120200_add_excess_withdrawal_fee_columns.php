<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The excess-withdrawal fee (gap round S1, new records only):
 *
 * - `savings_approvals.fee_amount`: the fee a pending withdrawal reserves
 *   beside its amount (₱0.00 on every existing request);
 * - `savings_transactions.fee_for_transaction_id`: a `fee` row names the
 *   withdrawal it was charged on, once (unique).
 *
 * Added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('savings_approvals', 'fee_amount')) {
            Schema::table('savings_approvals', function (Blueprint $table): void {
                $table->decimal('fee_amount', 15, 2)->default(0)->after('amount');
            });
        }

        if (! Schema::hasColumn('savings_transactions', 'fee_for_transaction_id')) {
            Schema::table('savings_transactions', function (Blueprint $table): void {
                $table->foreignId('fee_for_transaction_id')->nullable()->unique()->constrained('savings_transactions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('savings_transactions', 'fee_for_transaction_id')) {
            Schema::table('savings_transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('fee_for_transaction_id');
            });
        }

        if (Schema::hasColumn('savings_approvals', 'fee_amount')) {
            Schema::table('savings_approvals', function (Blueprint $table): void {
                $table->dropColumn('fee_amount');
            });
        }
    }
};
