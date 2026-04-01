<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number', 20)->unique();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->enum('adjustment_type', ['restructure', 'penalty_waiver', 'balance_adjustment', 'term_extension']);
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'applied'])->default('pending');
            $table->text('remarks')->nullable();

            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index('loan_id');
            $table->index('status');
            $table->index('adjustment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_adjustments');
    }
};
