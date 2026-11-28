<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each loan product's default CIC classification: the contract record (CI
 * installment or CN non-installment), contract type, transaction type and
 * purpose. A loan's own structure decides first, this mapping next, and a
 * per-loan override (cic_loan_overrides) last.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->unique()->constrained('loan_products')->cascadeOnDelete();
            $table->enum('record_type', ['CI', 'CN']);
            $table->string('contract_type', 3);
            $table->string('transaction_type', 10);
            $table->string('purpose_code', 3)->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_product_mappings');
    }
};
