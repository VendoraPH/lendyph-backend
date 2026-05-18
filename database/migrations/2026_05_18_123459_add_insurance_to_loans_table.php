<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('insurance_premium_pct', 5, 2)->nullable()->after('net_proceeds');
            $table->decimal('insurance_premium_amount', 12, 2)->nullable()->after('insurance_premium_pct');
            $table->enum('insurance_payment_type', ['full', 'partial'])->nullable()->after('insurance_premium_amount');
            $table->decimal('insurance_partial_amount', 12, 2)->nullable()->after('insurance_payment_type');
            $table->decimal('insurance_remaining_balance', 12, 2)->default(0)->after('insurance_partial_amount');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_premium_pct',
                'insurance_premium_amount',
                'insurance_payment_type',
                'insurance_partial_amount',
                'insurance_remaining_balance',
            ]);
        });
    }
};
