<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The penalty waiver that forgave this period's penalty, if one did.
     *
     * RepaymentService::applyPenalties() rewrites a late period's penalty on
     * every payment and every night, so without a mark on the period a waiver
     * lasted only until the next of either. A period carrying one is never
     * charged penalty again.
     */
    public function up(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->foreignId('penalty_waiver_id')->nullable()->after('penalty_paid')
                ->constrained('loan_adjustments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalty_waiver_id');
        });
    }
};
