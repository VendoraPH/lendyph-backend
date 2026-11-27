<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The defaults are what every existing row already means — a term in
     * months and a rate per month — so no row changes and no backfill is
     * needed. `loans` carries its own copy, taken from the product when the
     * loan is created, so editing a product later never reprices a loan.
     */
    public function up(): void
    {
        foreach (['loan_products', 'loans'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->enum('term_unit', ['months', 'days'])->default('months')->after('term');
                $table->enum('interest_rate_frequency', ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly'])
                    ->default('monthly')->after('interest_rate');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['loan_products', 'loans'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['term_unit', 'interest_rate_frequency']);
            });
        }
    }
};
