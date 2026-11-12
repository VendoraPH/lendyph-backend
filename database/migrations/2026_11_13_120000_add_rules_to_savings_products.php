<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Savings product rules (gap round S1, new records only). Every column is
 * nullable or defaults to today's behaviour, so an existing product keeps
 * taking deposits, withdrawals and transfers without limits:
 *
 * - `max_balance`, `min_deposit`, `max_deposit`, `min_withdrawal`,
 *   `max_withdrawal`: pesos, null = no limit;
 * - `free_withdrawals_per_month`: null = unlimited;
 * - `excess_withdrawal_fee`: pesos, null = a withdrawal beyond the free count
 *   is refused;
 * - `allow_deposits`, `allow_withdrawals`, `allow_transfers`: on;
 * - `eligible_member_types`, `eligible_branch_ids`: JSON lists, null = all.
 *
 * Added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('savings_products', 'max_balance')) {
            return;
        }

        Schema::table('savings_products', function (Blueprint $table): void {
            $table->decimal('max_balance', 12, 2)->nullable();
            $table->decimal('min_deposit', 12, 2)->nullable();
            $table->decimal('max_deposit', 12, 2)->nullable();
            $table->decimal('min_withdrawal', 12, 2)->nullable();
            $table->decimal('max_withdrawal', 12, 2)->nullable();
            $table->unsignedSmallInteger('free_withdrawals_per_month')->nullable();
            $table->decimal('excess_withdrawal_fee', 12, 2)->nullable();
            $table->boolean('allow_deposits')->default(true);
            $table->boolean('allow_withdrawals')->default(true);
            $table->boolean('allow_transfers')->default(true);
            $table->json('eligible_member_types')->nullable();
            $table->json('eligible_branch_ids')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('savings_products', 'max_balance')) {
            return;
        }

        Schema::table('savings_products', function (Blueprint $table): void {
            $table->dropColumn([
                'max_balance', 'min_deposit', 'max_deposit', 'min_withdrawal', 'max_withdrawal',
                'free_withdrawals_per_month', 'excess_withdrawal_fee',
                'allow_deposits', 'allow_withdrawals', 'allow_transfers',
                'eligible_member_types', 'eligible_branch_ids',
            ]);
        });
    }
};
