<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'gcash', 'maya', 'bank_transfer', 'online'])->default('cash')->after('payment_date');
            $table->string('reference_number', 100)->nullable()->after('method');
            $table->decimal('balance_before', 12, 2)->default(0)->after('overpayment');
            $table->decimal('balance_after', 12, 2)->default(0)->after('balance_before');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->dropColumn(['method', 'reference_number', 'balance_before', 'balance_after']);
        });
    }
};
