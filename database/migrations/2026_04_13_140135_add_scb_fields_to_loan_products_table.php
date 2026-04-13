<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Share Capital Build-Up (SCB) range configuration to loan products.
 * Matches the frontend's LoanProduct type — the user selects an scb_amount
 * within [min_scb, max_scb] when creating a loan from this product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->boolean('scb_required')->default(false)->after('grace_period_days');
            $table->decimal('min_scb', 12, 2)->default(0)->after('scb_required');
            $table->decimal('max_scb', 12, 2)->default(0)->after('min_scb');
        });
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['scb_required', 'min_scb', 'max_scb']);
        });
    }
};
