<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A loan's own CIC classification, over its product's mapping, with the
 * reason staff gave (prompt §3.3). A null field keeps the product's value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_loan_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained('loans')->cascadeOnDelete();
            $table->enum('record_type', ['CI', 'CN'])->nullable();
            $table->string('contract_type', 3)->nullable();
            $table->string('transaction_type', 10)->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_loan_overrides');
    }
};
