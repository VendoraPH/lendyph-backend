<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Share capital auto-credit's skipped list (finish run round 7), new runs only,
 * in the savings auto-credit's pattern:
 *
 * - `auto_credit_run_skips`: one row per pledge a run skipped, with its
 *   member, the pledge amount it didn't credit and the reason in words (a
 *   pledge already credited by hand in the run's month), so a run's skipped
 *   list can be read again later.
 * - `auto_credit_runs.skipped_count`: how many it skipped. Past runs keep 0:
 *   they skipped nothing.
 *
 * Only adds, each step skipped when already there, so running it again
 * changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auto_credit_run_skips')) {
            Schema::create('auto_credit_run_skips', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->constrained('auto_credit_runs')->restrictOnDelete();
                $table->foreignId('pledge_id')->constrained('share_capital_pledges')->restrictOnDelete();
                $table->foreignId('borrower_id')->constrained('borrowers')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('reason', 500);
                $table->timestamp('created_at')->nullable();
                $table->index(['run_id', 'pledge_id']);
                $table->index('borrower_id');
            });
        }

        if (! Schema::hasColumn('auto_credit_runs', 'skipped_count')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->unsignedInteger('skipped_count')->default(0)->after('member_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('auto_credit_runs', 'skipped_count')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->dropColumn('skipped_count');
            });
        }

        Schema::dropIfExists('auto_credit_run_skips');
    }
};
