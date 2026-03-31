<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('interest_rate', 8, 4);
            $table->enum('interest_method', ['straight', 'diminishing', 'upon_maturity']);
            $table->unsignedSmallInteger('term');
            $table->enum('frequency', ['daily', 'weekly', 'semi_monthly', 'monthly']);
            $table->decimal('processing_fee', 8, 4)->default(0);
            $table->decimal('service_fee', 8, 4)->default(0);
            $table->decimal('penalty_rate', 8, 4)->default(0);
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->decimal('min_amount', 12, 2)->default(0);
            $table->decimal('max_amount', 12, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};
