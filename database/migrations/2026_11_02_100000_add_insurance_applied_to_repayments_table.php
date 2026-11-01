<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each payment collected of the insurance premium still owed on its loan
 * (`loans.insurance_remaining_balance`). Additive: every existing payment
 * collected none, so 0 is the true value for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->decimal('insurance_applied', 12, 2)->default(0)->after('penalty_applied');
        });
    }

    public function down(): void
    {
        Schema::table('repayments', function (Blueprint $table) {
            $table->dropColumn('insurance_applied');
        });
    }
};
