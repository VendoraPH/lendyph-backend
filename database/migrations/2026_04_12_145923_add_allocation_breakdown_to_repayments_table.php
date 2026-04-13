<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->decimal('overdue_interest_applied', 12, 2)->default(0)->after('penalty_applied');
            $table->decimal('current_interest_applied', 12, 2)->default(0)->after('overdue_interest_applied');
            $table->decimal('current_principal_applied', 12, 2)->default(0)->after('current_interest_applied');
            $table->decimal('next_interest_applied', 12, 2)->default(0)->after('current_principal_applied');
            $table->decimal('next_principal_applied', 12, 2)->default(0)->after('next_interest_applied');
        });
    }

    public function down(): void
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->dropColumn([
                'overdue_interest_applied',
                'current_interest_applied',
                'current_principal_applied',
                'next_interest_applied',
                'next_principal_applied',
            ]);
        });
    }
};
