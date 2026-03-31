<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('application_number', 20)->unique();
            $table->string('loan_account_number', 20)->unique()->nullable();

            $table->foreignId('borrower_id')->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('loan_product_id')->constrained('loan_products')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // Snapshot from product
            $table->decimal('interest_rate', 8, 4);
            $table->enum('interest_method', ['straight', 'diminishing', 'upon_maturity']);
            $table->unsignedSmallInteger('term');
            $table->enum('frequency', ['daily', 'weekly', 'semi_monthly', 'monthly']);

            // Application fields
            $table->decimal('principal_amount', 12, 2);
            $table->date('start_date');
            $table->date('maturity_date');
            $table->json('deductions')->nullable();
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_proceeds', 12, 2)->default(0);

            // From product
            $table->decimal('penalty_rate', 8, 4)->default(0);
            $table->unsignedSmallInteger('grace_period_days')->default(0);

            // Status workflow
            $table->enum('status', ['draft', 'for_review', 'approved', 'rejected', 'released', 'closed', 'void'])
                ->default('draft');

            // Approval
            $table->text('approval_remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Release
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();

            // Creator
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('borrower_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index('loan_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
