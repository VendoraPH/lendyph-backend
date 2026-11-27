<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The restructure, term extension or extension that closed this period.
     *
     * Rescheduling closes a period money was collected on at what was
     * collected, instead of deleting it, and carries the rest forward. A
     * closed period is `paid` like one paid in full, so this is what tells
     * them apart when the next restructure picks the date it starts from.
     */
    public function up(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->foreignId('closed_by_adjustment_id')->nullable()->after('penalty_waiver_id')
                ->constrained('loan_adjustments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_adjustment_id');
        });
    }
};
