<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gcash_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 32)->unique();
            $table->dateTime('transaction_date');
            $table->enum('type', ['cash_in', 'cash_out']);
            $table->decimal('amount', 12, 2);
            $table->decimal('charge_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->enum('status', ['pending', 'paid', 'completed']);
            $table->foreignId('borrower_id')->constrained('borrowers');
            $table->foreignId('transactor_user_id')->constrained('users');
            $table->text('remarks')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index('status');
            $table->index(['borrower_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gcash_transactions');
    }
};
