<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_collaterals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('collateral_id')->constrained('collaterals')->restrictOnDelete();
            $table->decimal('snapshot_value', 14, 2)->default(0);
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'collateral_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_collaterals');
    }
};
