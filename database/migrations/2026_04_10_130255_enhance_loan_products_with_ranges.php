<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Enhance loan_products with range fields, description, frequencies,
     * notarial fee, and custom fees — matching the frontend's advanced form.
     *
     * Legacy single-value columns (interest_rate, term, frequency) are kept
     * for backward compat — LoanService uses them as defaults when creating loans.
     */
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->decimal('min_interest_rate', 8, 4)->nullable()->after('interest_rate');
            $table->unsignedSmallInteger('min_term')->nullable()->after('term');
            $table->unsignedSmallInteger('max_term')->nullable()->after('min_term');
            $table->json('frequencies')->nullable()->after('frequency');
            $table->decimal('min_processing_fee', 8, 4)->nullable()->after('processing_fee');
            $table->decimal('max_processing_fee', 8, 4)->nullable()->after('min_processing_fee');
            $table->decimal('min_service_fee', 8, 4)->nullable()->after('service_fee');
            $table->decimal('max_service_fee', 8, 4)->nullable()->after('min_service_fee');
            $table->decimal('notarial_fee', 8, 4)->nullable()->default(0)->after('max_service_fee');
            $table->json('custom_fees')->nullable()->after('notarial_fee');
        });
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'min_interest_rate', 'min_term', 'max_term',
                'frequencies', 'min_processing_fee', 'max_processing_fee',
                'min_service_fee', 'max_service_fee', 'notarial_fee', 'custom_fees',
            ]);
        });
    }
};
