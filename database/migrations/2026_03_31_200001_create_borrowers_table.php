<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowers', function (Blueprint $table) {
            $table->id();
            $table->string('borrower_code', 20)->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix', 20)->nullable();
            $table->date('birthdate')->nullable();
            $table->enum('civil_status', ['single', 'married', 'widowed', 'separated', 'divorced'])->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->text('address')->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('employer_or_business')->nullable();
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('last_name');
            $table->index('contact_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowers');
    }
};
