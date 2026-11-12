<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tiered savings rates (gap round S2). `min_balance` is the lowest closing
     * balance, in pesos, that earns the row's rate; null is the base tier,
     * which covers every balance below the first tier. The rows sharing one
     * `effective_from` are that date's ladder (RateBook).
     *
     * Null on every existing row, so each existing rate reads as its date's
     * base tier and every figure stays the same. Time deposit rates never
     * carry one. No backfill. Only adds; safe to run twice.
     */
    public function up(): void
    {
        if (Schema::hasColumn('savings_product_rates', 'min_balance')) {
            return;
        }

        Schema::table('savings_product_rates', function (Blueprint $table): void {
            $table->decimal('min_balance', 15, 2)->nullable()->after('annual_rate');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('savings_product_rates', 'min_balance')) {
            return;
        }

        Schema::table('savings_product_rates', function (Blueprint $table): void {
            $table->dropColumn('min_balance');
        });
    }
};
