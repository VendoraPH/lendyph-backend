<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a payment paid on each period: one row per period it reached.
     *
     * A repayment row stores only its totals, so voiding one used to re-run
     * those totals from period 1 and reversed whichever periods came first.
     *
     * `amortization_schedule_id` is nullOnDelete because a restructure, a term
     * extension and an extension delete the open periods they replace; the
     * period number stays as it was.
     */
    public function up(): void
    {
        Schema::create('repayment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repayment_id')->constrained('repayments')->cascadeOnDelete();
            $table->foreignId('amortization_schedule_id')->nullable()
                ->constrained('amortization_schedules')->nullOnDelete();
            $table->unsignedInteger('period_number');
            $table->decimal('penalty', 12, 2)->default(0);
            $table->decimal('interest', 12, 2)->default(0);
            $table->decimal('principal', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repayment_allocations');
    }
};
