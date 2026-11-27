<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 EB-B: the daily portfolio snapshot gains one line per branch × loan
 * product and a run log, and its organisation-wide row gains PAR 60, the PAR
 * loan counts and the loan counts.
 *
 * - `portfolio_snapshot_lines`: written by `portfolio:snapshot` with the
 *   organisation row; they add up to it, figure by figure. Branch users' past
 *   dashboard points and the executive dashboard's history read them.
 * - `portfolio_snapshot_runs`: one row per run, with its outcome in words.
 * - The new organisation columns are nullable: days recorded before them
 *   stay as they were (no backfill). `line_count` is null on a day with no
 *   lines (recorded before them, or whose lines didn't add up).
 *
 * The lines name their branch and product by id with no foreign key, so a
 * past day's record never blocks a change to a branch or product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_daily_snapshots', function (Blueprint $table) {
            $table->unsignedInteger('loan_count')->nullable()->after('snapshot_date');
            $table->unsignedInteger('active_loan_count')->nullable()->after('loan_count');
            $table->unsignedInteger('par_1_loans')->nullable()->after('par_1_amount');
            $table->unsignedInteger('par_30_loans')->nullable()->after('par_30_amount');
            $table->decimal('par_60_amount', 15, 2)->nullable()->after('par_30_loans');
            $table->unsignedInteger('par_60_loans')->nullable()->after('par_60_amount');
            $table->unsignedInteger('par_90_loans')->nullable()->after('par_90_amount');
            $table->unsignedInteger('line_count')->nullable()->after('par_90_loans');
        });

        Schema::create('portfolio_snapshot_lines', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('loan_product_id');
            $table->unsignedInteger('loan_count');
            $table->unsignedInteger('active_loan_count');
            $table->unsignedInteger('overdue_loans_count');
            $table->decimal('overdue_amount', 15, 2);
            $table->decimal('total_outstanding', 15, 2);
            $table->decimal('outstanding_principal', 15, 2);
            $table->decimal('par_1_amount', 15, 2);
            $table->unsignedInteger('par_1_loans');
            $table->decimal('par_30_amount', 15, 2);
            $table->unsignedInteger('par_30_loans');
            $table->decimal('par_60_amount', 15, 2);
            $table->unsignedInteger('par_60_loans');
            $table->decimal('par_90_amount', 15, 2);
            $table->unsignedInteger('par_90_loans');
            $table->timestamp('created_at')->nullable();

            $table->unique(['snapshot_date', 'branch_id', 'loan_product_id'], 'portfolio_snapshot_lines_day_branch_product_unique');
            $table->index(['branch_id', 'snapshot_date']);
        });

        Schema::create('portfolio_snapshot_runs', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->boolean('is_replay')->default(false);
            $table->string('status', 20);
            $table->unsignedInteger('line_count')->nullable();
            $table->json('checks')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_snapshot_runs');
        Schema::dropIfExists('portfolio_snapshot_lines');

        Schema::table('portfolio_daily_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'loan_count', 'active_loan_count', 'par_1_loans', 'par_30_loans', 'par_60_amount',
                'par_60_loans', 'par_90_loans', 'line_count',
            ]);
        });
    }
};
