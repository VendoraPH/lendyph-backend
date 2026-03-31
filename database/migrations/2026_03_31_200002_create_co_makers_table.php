<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_makers', function (Blueprint $table) {
            $table->id();
            $table->string('co_maker_code', 20)->unique();
            $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->string('occupation')->nullable();
            $table->string('employer')->nullable();
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->string('relationship_to_borrower')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('borrower_id');
            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_makers');
    }
};
