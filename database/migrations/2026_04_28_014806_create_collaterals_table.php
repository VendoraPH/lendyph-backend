<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaterals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
            $table->foreignId('collateral_type_id')->constrained('collateral_types')->restrictOnDelete();
            $table->string('detail_value')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['borrower_id']);
            $table->index(['collateral_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaterals');
    }
};
