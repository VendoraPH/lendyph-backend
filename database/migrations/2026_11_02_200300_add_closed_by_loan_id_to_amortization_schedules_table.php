<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The loan whose release closed this period: a restructure into a new loan
     * closes the source's partly paid periods at what was collected on them,
     * as `closed_by_adjustment_id` marks a period an in-place restructure or
     * an extension closed. No adjustment exists on that path, so it needs its
     * own marker (AmortizationSchedule::isClosedEarly() reads either).
     *
     * New restructures only: periods closed before this column existed stay
     * unmarked.
     */
    public function up(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->foreignId('closed_by_loan_id')->nullable()->after('closed_by_adjustment_id')
                ->constrained('loans')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amortization_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_loan_id');
        });
    }
};
