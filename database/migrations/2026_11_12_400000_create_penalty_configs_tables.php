<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penalty configurations (Phase 4, Settings → Penalties): a named set of
     * day brackets, each with its own rate, covering one or more loan
     * products.
     *
     * - `penalty_configs`: the name (unique) and its status. A product sits in
     *   at most one active configuration; PenaltyConfigService enforces it
     *   under a lock on the product rows.
     * - `penalty_config_conditions`: the brackets. `days_from` and `days_to`
     *   count days past the due date (the day after it is day 1), and
     *   `days_to` null is open-ended (the last bracket only). The rate is a
     *   percent with up to 4 decimals, never rounded.
     * - `penalty_config_products`: the products it covers.
     *
     * A loan copies its product's active configuration at creation
     * (2026_11_12_400100), so editing a configuration never reprices a loan.
     * Only adds; safe to run twice.
     */
    public function up(): void
    {
        if (! Schema::hasTable('penalty_configs')) {
            Schema::create('penalty_configs', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('penalty_config_conditions')) {
            Schema::create('penalty_config_conditions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('penalty_config_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('days_from');
                $table->unsignedInteger('days_to')->nullable();
                $table->decimal('rate', 7, 4);
                $table->timestamps();

                $table->index(['penalty_config_id', 'days_from'], 'penalty_conditions_config_days_index');
            });
        }

        if (! Schema::hasTable('penalty_config_products')) {
            Schema::create('penalty_config_products', function (Blueprint $table): void {
                $table->foreignId('penalty_config_id')->constrained()->cascadeOnDelete();
                $table->foreignId('loan_product_id')->constrained()->cascadeOnDelete();

                $table->primary(['penalty_config_id', 'loan_product_id']);
                $table->index('loan_product_id', 'penalty_config_products_product_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_config_products');
        Schema::dropIfExists('penalty_config_conditions');
        Schema::dropIfExists('penalty_configs');
    }
};
