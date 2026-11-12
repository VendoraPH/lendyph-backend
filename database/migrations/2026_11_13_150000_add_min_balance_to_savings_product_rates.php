<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tiered savings rates (gap round S2).
     *
     * - `min_balance`: the lowest closing balance, in pesos, that earns the
     *   row's rate; null is the base tier, which covers every balance below
     *   the first tier.
     * - `rate_post_id`: one id shared by every row one rate post wrote. The
     *   ladder for a date is the rows of the newest post on that date, so a
     *   same-date correction replaces the whole ladder while the table stays
     *   append-only. Rows with none (every row before this migration) keep
     *   the old rule: the newest row per minimum balance.
     *
     * Both null on every existing row, so each existing rate reads as its
     * date's base tier and every figure stays the same. Time deposit rates
     * never carry a minimum balance. No backfill. Only adds; safe to run
     * twice.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('savings_product_rates', 'min_balance')) {
            Schema::table('savings_product_rates', function (Blueprint $table): void {
                $table->decimal('min_balance', 15, 2)->nullable()->after('annual_rate');
            });
        }

        if (! Schema::hasColumn('savings_product_rates', 'rate_post_id')) {
            Schema::table('savings_product_rates', function (Blueprint $table): void {
                $table->uuid('rate_post_id')->nullable()->after('min_balance');
            });
        }
    }

    public function down(): void
    {
        foreach (['rate_post_id', 'min_balance'] as $column) {
            if (Schema::hasColumn('savings_product_rates', $column)) {
                Schema::table('savings_product_rates', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
