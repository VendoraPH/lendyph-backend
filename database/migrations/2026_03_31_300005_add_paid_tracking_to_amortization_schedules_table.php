<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->decimal('principal_paid', 12, 2)->default(0)->after('remaining_balance');
            $table->decimal('interest_paid', 12, 2)->default(0)->after('principal_paid');
            $table->decimal('penalty_amount', 12, 2)->default(0)->after('interest_paid');
            $table->decimal('penalty_paid', 12, 2)->default(0)->after('penalty_amount');
        });
    }

    public function down(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->dropColumn(['principal_paid', 'interest_paid', 'penalty_amount', 'penalty_paid']);
        });
    }
};
