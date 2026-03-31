<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_maker_loan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('co_maker_id')->constrained('co_makers')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'co_maker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_maker_loan');
    }
};
