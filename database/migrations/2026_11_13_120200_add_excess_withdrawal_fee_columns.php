<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The excess-withdrawal fee (gap round S1, new records only). A withdrawal's
 * fee is decided once, when it is requested, and the withdrawal counts
 * against the free withdrawals of the month it was requested in:
 *
 * - `savings_approvals` and `portal_requests`: `fee_amount` (the fee decided
 *   at request, reserved beside the amount; ₱0.00 on every existing
 *   request), `counted_on` (the date it counts in) and the product's rule
 *   then, `rule_free_withdrawals` and `rule_excess_fee`, so a changed rule
 *   can be told apart at payout;
 * - `savings_transactions.counted_on`: on a withdrawal, the date its request
 *   counts in (null on existing rows, which count by `date`);
 * - `savings_transactions.fee_for_transaction_id`: a `fee` row names the
 *   withdrawal it was charged on, once (unique).
 *
 * Added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['savings_approvals', 'portal_requests'] as $name) {
            if (! Schema::hasColumn($name, 'fee_amount')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->decimal('fee_amount', 15, 2)->default(0)->after('amount');
                    $table->date('counted_on')->nullable();
                    $table->unsignedSmallInteger('rule_free_withdrawals')->nullable();
                    $table->decimal('rule_excess_fee', 12, 2)->nullable();
                });
            }
        }

        if (! Schema::hasColumn('savings_transactions', 'fee_for_transaction_id')) {
            Schema::table('savings_transactions', function (Blueprint $table): void {
                $table->date('counted_on')->nullable();
                $table->foreignId('fee_for_transaction_id')->nullable()->unique()->constrained('savings_transactions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('savings_transactions', 'fee_for_transaction_id')) {
            Schema::table('savings_transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('fee_for_transaction_id');
                $table->dropColumn('counted_on');
            });
        }

        foreach (['portal_requests', 'savings_approvals'] as $name) {
            if (Schema::hasColumn($name, 'fee_amount')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->dropColumn(['fee_amount', 'counted_on', 'rule_free_withdrawals', 'rule_excess_fee']);
                });
            }
        }
    }
};
