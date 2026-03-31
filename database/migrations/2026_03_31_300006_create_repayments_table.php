<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repayments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 20)->unique();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('principal_applied', 12, 2)->default(0);
            $table->decimal('interest_applied', 12, 2)->default(0);
            $table->decimal('penalty_applied', 12, 2)->default(0);
            $table->decimal('overpayment', 12, 2)->default(0);
            $table->enum('payment_type', ['exact', 'partial', 'advance'])->default('exact');
            $table->enum('status', ['posted', 'voided'])->default('posted');
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('loan_id');
            $table->index('payment_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repayments');
    }
};
