<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('share_capital_pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->enum('schedule', ['15', '30', '15/30'])->default('15/30');
            $table->boolean('auto_credit')->default(false);
            $table->timestamps();

            $table->unique('borrower_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('share_capital_pledges');
    }
};
