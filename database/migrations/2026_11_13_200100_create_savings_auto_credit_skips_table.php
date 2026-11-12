<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Past savings auto-credit runs (gap round B7), new runs only:
 *
 * - `savings_auto_credit_skips`: one row per instruction a run skipped, with
 *   its account, amount and reason in words, so a run's skipped list can be
 *   read again later and limited to a branch user's own accounts.
 * - `savings_auto_credit_runs.skips_recorded`: true on runs that wrote their
 *   skipped list. Runs before this change keep false: their skipped count is
 *   on the run, but the list itself was never stored.
 *
 * Only adds, each step skipped when already there, so running it again
 * changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('savings_auto_credit_skips')) {
            Schema::create('savings_auto_credit_skips', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->constrained('savings_auto_credit_runs')->restrictOnDelete();
                $table->foreignId('instruction_id')->constrained('savings_auto_credit_instructions')->restrictOnDelete();
                $table->foreignId('account_id')->constrained('savings_accounts')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('reason', 500);
                $table->timestamp('created_at')->nullable();
                $table->index(['run_id', 'account_id']);
                $table->index('account_id');
            });
        }

        if (! Schema::hasColumn('savings_auto_credit_runs', 'skips_recorded')) {
            Schema::table('savings_auto_credit_runs', function (Blueprint $table): void {
                $table->boolean('skips_recorded')->default(false)->after('skipped_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('savings_auto_credit_runs', 'skips_recorded')) {
            Schema::table('savings_auto_credit_runs', function (Blueprint $table): void {
                $table->dropColumn('skips_recorded');
            });
        }

        Schema::dropIfExists('savings_auto_credit_skips');
    }
};
