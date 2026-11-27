<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per day of the portfolio's arrears figures, written by
 * `portfolio:snapshot` at 23:55 Asia/Manila (decision 2d, Arvin,
 * 2026-11-02). The dashboard's overdue sparkline reads its past days from
 * here, so a past day shows what was overdue that day rather than today's
 * payment state replayed against an old date. No backfill: days before the
 * first run have no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->unsignedInteger('overdue_loans_count');
            $table->decimal('overdue_amount', 15, 2);
            $table->decimal('total_outstanding', 15, 2);
            // The PAR ratio's denominator: PAR n ÷ outstanding principal.
            $table->decimal('outstanding_principal', 15, 2);
            $table->decimal('par_1_amount', 15, 2);
            $table->decimal('par_30_amount', 15, 2);
            $table->decimal('par_90_amount', 15, 2);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_daily_snapshots');
    }
};
