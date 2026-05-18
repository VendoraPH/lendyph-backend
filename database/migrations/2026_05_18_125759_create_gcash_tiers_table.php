<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gcash_tiers', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_amount', 12, 2);
            $table->decimal('max_amount', 12, 2);
            $table->decimal('cash_in_rate', 12, 2);
            $table->decimal('cash_out_rate', 12, 2);
            $table->unsignedInteger('display_order');
            $table->timestamps();

            $table->index('display_order');
            $table->index(['min_amount', 'max_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gcash_tiers');
    }
};
